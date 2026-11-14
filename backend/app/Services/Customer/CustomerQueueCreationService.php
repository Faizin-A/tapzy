<?php

namespace App\Services\Customer;

use App\Exceptions\IdempotencyPayloadMismatchException;
use App\Exceptions\IdempotencyRecoveryUnavailableException;
use App\Exceptions\QueueSessionInactiveException;
use App\Models\NfcCard;
use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\QueueStatusHistory;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;

class CustomerQueueCreationService
{
    /**
     * Create a new queue ticket for the customer or replay an existing ticket
     * idempotently if the same Idempotency-Key is provided.
     *
     * @param  array<string, mixed>  $validatedData
     * @return array{
     *   queue: Queue,
     *   session_code: string|null,
     *   access_token: string,
     *   waiting_ahead: int,
     *   estimated_wait_minutes: int,
     *   is_replay: bool,
     * }
     */
    public function create(
        Store $store,
        NfcCard $card,
        array $validatedData,
        string $idempotencyKey
    ): array {
        $customerName = isset($validatedData['customer_name']) && is_string($validatedData['customer_name']) && trim($validatedData['customer_name']) !== ''
            ? trim($validatedData['customer_name'])
            : null;

        $customerPhone = isset($validatedData['customer_phone']) && is_string($validatedData['customer_phone']) && trim($validatedData['customer_phone']) !== ''
            ? trim($validatedData['customer_phone'])
            : null;

        $source = isset($validatedData['source']) && is_string($validatedData['source']) && in_array($validatedData['source'], ['nfc', 'barcode'], true)
            ? $validatedData['source']
            : 'nfc';

        $idempotencyKeyHash = hash('sha256', $idempotencyKey);
        $payloadFingerprint = hash('sha256', json_encode([
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'source' => $source,
        ]));

        // Fast path: check if idempotency key already exists outside the lock
        $existing = Queue::withoutGlobalScope(TenantScope::class)
            ->where('store_id', $store->id)
            ->where('idempotency_key_hash', $idempotencyKeyHash)
            ->first();

        if ($existing !== null) {
            return $this->resolveIdempotentReplay($store, $existing, $payloadFingerprint);
        }

        try {
            return DB::transaction(function () use (
                $store,
                $card,
                $customerName,
                $customerPhone,
                $source,
                $idempotencyKeyHash,
                $payloadFingerprint
            ): array {
                // 1. Pessimistic lock on the active QueueSession
                $session = QueueSession::withoutGlobalScope(TenantScope::class)
                    ->where('store_id', $store->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($session === null || $session->status !== 'active') {
                    throw new QueueSessionInactiveException;
                }

                // 2. Double-check idempotency under lock (concurrency race condition)
                $existingUnderLock = Queue::withoutGlobalScope(TenantScope::class)
                    ->where('store_id', $store->id)
                    ->where('idempotency_key_hash', $idempotencyKeyHash)
                    ->first();

                if ($existingUnderLock !== null) {
                    return $this->resolveIdempotentReplay($store, $existingUnderLock, $payloadFingerprint);
                }

                // 3. Count waiting_ahead BEFORE inserting new ticket (so new ticket is excluded)
                $waitingAhead = Queue::withoutGlobalScope(TenantScope::class)
                    ->where('store_id', $store->id)
                    ->where('queue_session_id', $session->id)
                    ->where('status', 'waiting')
                    ->count();

                // 4. Increment last_queue_number
                $nextNumber = $session->last_queue_number + 1;
                $session->last_queue_number = $nextNumber;
                $session->save();

                // 5. Generate formatted number
                $formattedNumber = sprintf('%s-%03d', (string) $store->queue_prefix, $nextNumber);

                // 6. Generate 40-char CSPRNG access token
                $plainAccessToken = Str::random(40);
                $accessTokenHash = hash('sha256', $plainAccessToken);
                try {
                    $accessTokenCiphertext = Crypt::encryptString($plainAccessToken);
                } catch (EncryptException) {
                    throw new IdempotencyRecoveryUnavailableException;
                }

                // 7. Insert Queue record
                $queue = new Queue;
                $queue->store_id = $store->id;
                $queue->queue_session_id = $session->id;
                $queue->nfc_card_id = $card->id;
                $queue->queue_number = $nextNumber;
                $queue->formatted_number = $formattedNumber;
                $queue->customer_name = $customerName;
                $queue->customer_phone = $customerPhone;
                $queue->source = $source;
                $queue->access_token_hash = $accessTokenHash;
                $queue->access_token_ciphertext = $accessTokenCiphertext;
                $queue->status = 'waiting';
                $queue->idempotency_key_hash = $idempotencyKeyHash;
                $queue->request_payload_hash = $payloadFingerprint;
                $queue->save();

                // 8. Insert initial QueueStatusHistory
                $history = new QueueStatusHistory;
                $history->queue_id = $queue->id;
                $history->previous_status = null;
                $history->new_status = 'waiting';
                $history->changed_by_user_id = null;
                $history->notes = 'Tiket antrean dibuat oleh pelanggan melalui NFC/QR';
                $history->created_at = now();
                $history->save();

                return [
                    'queue' => $queue,
                    'session_code' => $session->session_code,
                    'access_token' => $plainAccessToken,
                    'waiting_ahead' => $waitingAhead,
                    'estimated_wait_minutes' => $waitingAhead * (int) $store->avg_service_time,
                    'is_replay' => false,
                ];
            });
        } catch (QueryException $e) {
            // If duplicate key violation occurs on uq_store_queue_idempotency
            $isDuplicateIdempotency = $e->getCode() === '23000'
                && (int) ($e->errorInfo[1] ?? 0) === 1062
                && str_contains($e->getMessage(), 'uq_store_queue_idempotency');

            if ($isDuplicateIdempotency) {
                $retryExisting = Queue::withoutGlobalScope(TenantScope::class)
                    ->where('store_id', $store->id)
                    ->where('idempotency_key_hash', $idempotencyKeyHash)
                    ->first();

                if ($retryExisting !== null) {
                    return $this->resolveIdempotentReplay($store, $retryExisting, $payloadFingerprint);
                }
            }

            throw $e;
        }
    }

    /**
     * @return array{
     *   queue: Queue,
     *   session_code: string|null,
     *   access_token: string,
     *   waiting_ahead: int,
     *   estimated_wait_minutes: int,
     *   is_replay: true,
     * }
     */
    private function resolveIdempotentReplay(
        Store $store,
        Queue $existingQueue,
        string $payloadFingerprint
    ): array {
        if ($existingQueue->request_payload_hash !== $payloadFingerprint) {
            throw new IdempotencyPayloadMismatchException;
        }

        $session = QueueSession::withoutGlobalScope(TenantScope::class)
            ->where('id', $existingQueue->queue_session_id)
            ->where('store_id', $store->id)
            ->first();

        if ($session === null) {
            throw new IdempotencyRecoveryUnavailableException;
        }

        $waitingAhead = Queue::withoutGlobalScope(TenantScope::class)
            ->where('store_id', $store->id)
            ->where('queue_session_id', $existingQueue->queue_session_id)
            ->where('status', 'waiting')
            ->where('queue_number', '<', $existingQueue->queue_number)
            ->count();

        if (! is_string($existingQueue->access_token_ciphertext) || $existingQueue->access_token_ciphertext === '') {
            throw new IdempotencyRecoveryUnavailableException;
        }

        try {
            $accessToken = Crypt::decryptString($existingQueue->access_token_ciphertext);
        } catch (DecryptException) {
            throw new IdempotencyRecoveryUnavailableException;
        }

        if (! preg_match('/^[A-Za-z0-9]{40}$/', $accessToken)
            || ! hash_equals($existingQueue->access_token_hash, hash('sha256', $accessToken))) {
            throw new IdempotencyRecoveryUnavailableException;
        }

        return [
            'queue' => $existingQueue,
            'session_code' => $session->session_code,
            'access_token' => $accessToken,
            'waiting_ahead' => $waitingAhead,
            'estimated_wait_minutes' => $waitingAhead * (int) $store->avg_service_time,
            'is_replay' => true,
        ];
    }
}

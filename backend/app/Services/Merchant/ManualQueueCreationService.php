<?php

namespace App\Services\Merchant;

use App\Exceptions\IdempotencyPayloadMismatchException;
use App\Exceptions\OrderingDisabledException;
use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\QueueStatusHistory;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use App\Services\Customer\CustomerOrderCreationService;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ManualQueueCreationService
{
    public function __construct(
        private readonly CustomerOrderCreationService $orderService,
    ) {}

    public function create(Store $store, array $data, string $idempotencyKey): Queue
    {
        $items = array_values($data['items'] ?? []);
        if ($store->ordering_mode === 'queue_only' && $items !== []) {
            throw new OrderingDisabledException;
        }

        $name = trim((string) $data['customer_name']);
        $phone = $data['customer_phone'] ?? null;
        $payloadHash = hash('sha256', json_encode([
            'customer_name' => $name,
            'customer_phone' => $phone,
            'queue_notes' => $data['queue_notes'] ?? null,
            'order_notes' => $data['order_notes'] ?? null,
            'items' => $items,
        ], JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $idempotencyKey);

        $existing = Queue::withoutGlobalScope(TenantScope::class)
            ->where('store_id', $store->id)->where('idempotency_key_hash', $keyHash)->first();
        if ($existing !== null) {
            return $this->replay($existing, $payloadHash);
        }

        try {
            return DB::transaction(function () use ($store, $name, $phone, $data, $items, $keyHash, $payloadHash): Queue {
                $session = QueueSession::withoutGlobalScope(TenantScope::class)
                    ->where('store_id', $store->id)->where('status', 'active')->lockForUpdate()->first();
                if ($session === null) {
                    throw new ConflictHttpException('Tidak ada session antrean aktif.');
                }

                $existingUnderLock = Queue::withoutGlobalScope(TenantScope::class)
                    ->where('store_id', $store->id)->where('idempotency_key_hash', $keyHash)->first();
                if ($existingUnderLock !== null) {
                    return $this->replay($existingUnderLock, $payloadHash);
                }

                $plainToken = Str::random(40);
                try {
                    $ciphertext = Crypt::encryptString($plainToken);
                } catch (EncryptException $exception) {
                    throw new ConflictHttpException('Token antrean tidak dapat dibuat.', 0, $exception);
                }

                $number = (int) $session->last_queue_number + 1;
                $session->last_queue_number = $number;
                $session->save();

                $queue = new Queue;
                $queue->store_id = $store->id;
                $queue->queue_session_id = $session->id;
                $queue->nfc_card_id = null;
                $queue->queue_number = $number;
                $queue->formatted_number = sprintf('%s-%03d', (string) $store->queue_prefix, $number);
                $queue->customer_name = $name;
                $queue->customer_phone = $phone;
                $queue->source = 'manual';
                $queue->access_token_hash = hash('sha256', $plainToken);
                $queue->access_token_ciphertext = $ciphertext;
                $queue->status = 'waiting';
                $queue->notes = $data['queue_notes'] ?? null;
                $queue->idempotency_key_hash = $keyHash;
                $queue->request_payload_hash = $payloadHash;
                $queue->save();

                $history = new QueueStatusHistory;
                $history->queue_id = $queue->id;
                $history->previous_status = null;
                $history->new_status = 'waiting';
                $history->changed_by_user_id = app(TenantContext::class)->actorId();
                $history->notes = 'Tiket antrean dibuat manual oleh merchant';
                $history->created_at = now();
                $history->save();

                if ($items !== []) {
                    $this->orderService->create($plainToken, [
                        'items' => $items,
                        'notes' => $data['order_notes'] ?? null,
                    ]);
                }

                return $queue->fresh('order');
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'uq_store_queue_idempotency')) {
                $existing = Queue::withoutGlobalScope(TenantScope::class)->where('store_id', $store->id)->where('idempotency_key_hash', $keyHash)->first();
                if ($existing !== null) return $this->replay($existing, $payloadHash);
            }
            throw $exception;
        }
    }

    private function replay(Queue $queue, string $payloadHash): Queue
    {
        if (! hash_equals((string) $queue->request_payload_hash, $payloadHash)) {
            throw new IdempotencyPayloadMismatchException;
        }
        return $queue->fresh('order');
    }
}

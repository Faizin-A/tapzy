<?php

namespace App\Services\NfcCard;

use App\Models\NfcCard;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\QrCode\QrCodeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class NfcCardManagementService
{
    private const CREATE_FIELDS = [
        'card_uid',
        'label',
        'type',
    ];

    private const UPDATE_FIELDS = [
        'label',
        'type',
    ];

    private const TOKEN_GENERATION_ATTEMPTS = 3;

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly NfcTokenGenerator $tokenGenerator,
        private readonly QrCodeService $qrCodeService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{card: NfcCard, public_token: string, public_url: string, qr_code: array{format: string, svg: string, data_uri: string}}
     */
    public function create(
        Store $store,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $attributes = Arr::only($data, self::CREATE_FIELDS);

        return DB::transaction(function () use (
            $store,
            $attributes,
            $actor,
            $ipAddress,
            $userAgent,
        ): array {
            $lockedStore = Store::withTrashed()
                ->whereKey($store->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedStore->trashed()) {
                throw ValidationException::withMessages([
                    'store_id' => 'Store tidak tersedia.',
                ]);
            }

            if (NfcCard::query()->where('store_id', $lockedStore->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'store_id' => 'Store sudah memiliki kartu NFC.',
                ]);
            }

            if (NfcCard::withTrashed()->where('card_uid', $attributes['card_uid'])->exists()) {
                throw ValidationException::withMessages([
                    'card_uid' => 'UID kartu sudah digunakan.',
                ]);
            }

            for ($attempt = 1; $attempt <= self::TOKEN_GENERATION_ATTEMPTS; $attempt++) {
                $publicToken = $this->tokenGenerator->generate();

                try {
                    $card = new NfcCard([
                        'card_uid' => $attributes['card_uid'],
                        'public_token_hash' => $this->tokenGenerator->hash($publicToken),
                        'label' => $attributes['label'],
                        'type' => $attributes['type'],
                        'is_active' => false,
                        'activated_at' => null,
                        'deactivated_at' => null,
                    ]);
                    $card->store_id = $lockedStore->getKey();
                    $card->save();

                    $this->auditLogService->recordNfcCard(
                        AuditLogService::NFC_CARD_CREATED,
                        $card,
                        $actor,
                        [],
                        $this->auditLogService->nfcCardSnapshot($card),
                        $ipAddress,
                        $userAgent,
                    );

                    $publicUrl = url('/api/v1/public/nfc/'.$publicToken);
                    $qrCode = $this->qrCodeService->generate($publicUrl);

                    return [
                        'card' => $card,
                        'public_token' => $publicToken,
                        'public_url' => $publicUrl,
                        'qr_code' => $qrCode,
                    ];
                } catch (QueryException $exception) {
                    if ($this->isPublicTokenCollision($exception)) {
                        continue;
                    }

                    $this->throwValidationForUniqueViolation($exception);
                }
            }

            throw new RuntimeException('Unable to generate a unique NFC token.');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        NfcCard $card,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): NfcCard {
        return DB::transaction(function () use ($card, $data, $actor, $ipAddress, $userAgent): NfcCard {
            $locked = NfcCard::query()->whereKey($card->getKey())->lockForUpdate()->firstOrFail();
            $oldValues = $this->auditLogService->nfcCardSnapshot($locked);

            $locked->fill(Arr::only($data, self::UPDATE_FIELDS));

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->save();

            $this->auditLogService->recordNfcCard(
                AuditLogService::NFC_CARD_UPDATED,
                $locked,
                $actor,
                $oldValues,
                $this->auditLogService->nfcCardSnapshot($locked),
                $ipAddress,
                $userAgent,
            );

            return $locked->fresh();
        });
    }

    public function setActive(
        NfcCard $card,
        bool $active,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): NfcCard {
        return DB::transaction(function () use ($card, $active, $actor, $ipAddress, $userAgent): NfcCard {
            $locked = NfcCard::query()->whereKey($card->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->is_active === $active) {
                return $locked;
            }

            if ($active) {
                $store = Store::query()->whereKey($locked->store_id)->first();

                if ($store === null || ! $store->isOperational()) {
                    throw ValidationException::withMessages([
                        'store_id' => 'Store tidak operasional.',
                    ]);
                }
            }

            $oldValues = $this->auditLogService->nfcCardSnapshot($locked);

            $locked->is_active = $active;

            if ($active) {
                $locked->activated_at = now();
                $locked->deactivated_at = null;
            } else {
                $locked->deactivated_at = now();
            }

            $locked->save();

            $this->auditLogService->recordNfcCard(
                $active
                    ? AuditLogService::NFC_CARD_ACTIVATED
                    : AuditLogService::NFC_CARD_DEACTIVATED,
                $locked,
                $actor,
                $oldValues,
                $this->auditLogService->nfcCardSnapshot($locked),
                $ipAddress,
                $userAgent,
            );

            return $locked->fresh();
        });
    }

    public function delete(
        NfcCard $card,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        DB::transaction(function () use ($card, $actor, $ipAddress, $userAgent): void {
            $locked = NfcCard::query()->whereKey($card->getKey())->lockForUpdate()->firstOrFail();
            $oldValues = $this->auditLogService->nfcCardSnapshot($locked);

            $locked->delete();

            $this->auditLogService->recordNfcCard(
                AuditLogService::NFC_CARD_DELETED,
                $locked,
                $actor,
                $oldValues,
                $this->auditLogService->nfcCardSnapshot($locked),
                $ipAddress,
                $userAgent,
            );
        });
    }

    private function isPublicTokenCollision(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'public_token_hash');
    }

    private function throwValidationForUniqueViolation(QueryException $exception): never
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'card_uid') || str_contains($message, 'uq_card_uid_global')) {
            throw ValidationException::withMessages([
                'card_uid' => 'UID kartu sudah digunakan.',
            ]);
        }

        if (str_contains($message, 'active_store_id') || str_contains($message, 'uq_store_single_card')) {
            throw ValidationException::withMessages([
                'store_id' => 'Store sudah memiliki kartu NFC.',
            ]);
        }

        throw $exception;
    }
}

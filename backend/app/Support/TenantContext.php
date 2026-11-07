<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class TenantContext
{
    private ?int $storeId = null;

    private ?int $actorId = null;

    private bool $platformAccess = false;

    public function setMerchantStore(User $user, Store $store): void
    {
        if ($this->storeId !== null || $this->platformAccess) {
            throw new AuthorizationException('Tenant context sudah ditetapkan untuk request ini.');
        }

        if ($user->trashed()
            || $user->role !== 'merchant'
            || $user->store_id === null
            || (int) $user->store_id !== (int) $store->getKey()
            || $store->trashed()
            || ! $store->is_active) {
            throw new AuthorizationException('Akses tenant tidak tersedia.');
        }

        $this->storeId = (int) $store->getKey();
        $this->actorId = (int) $user->getKey();
        $this->platformAccess = false;
    }

    public function enablePlatformAccess(User $user): void
    {
        if ($this->storeId !== null
            || $this->platformAccess
            || $user->trashed()
            || $user->role !== 'super_admin'
            || $user->store_id !== null) {
            throw new AuthorizationException('Akses lintas tenant tidak tersedia.');
        }

        $this->storeId = null;
        $this->actorId = (int) $user->getKey();
        $this->platformAccess = true;
    }

    public function storeId(): ?int
    {
        return $this->storeId;
    }

    public function actorId(): ?int
    {
        return $this->actorId;
    }

    public function allowsPlatformAccess(): bool
    {
        return $this->platformAccess;
    }

    public function clear(): void
    {
        $this->storeId = null;
        $this->actorId = null;
        $this->platformAccess = false;
    }
}

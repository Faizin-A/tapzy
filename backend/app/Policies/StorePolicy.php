<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Services\Auth\LoginEligibility;
use App\Support\TenantContext;

class StorePolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LoginEligibility $loginEligibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->role === 'super_admin' && $this->hasPlatformAccess($user);
    }

    public function view(User $user, Store $store): bool
    {
        if ($user->role === 'super_admin') {
            return $this->hasPlatformAccess($user);
        }

        return $this->loginEligibility->allows($user)
            && $user->role === 'merchant'
            && ! $this->tenantContext->allowsPlatformAccess()
            && $this->tenantContext->storeId() === (int) $user->store_id
            && $this->tenantContext->actorId() === (int) $user->getKey()
            && (int) $store->getKey() === $this->tenantContext->storeId();
    }

    public function create(User $user): bool
    {
        return $user->role === 'super_admin' && $this->hasPlatformAccess($user);
    }

    public function update(User $user, Store $store): bool
    {
        return $this->view($user, $store);
    }

    public function manage(User $user, Store $store): bool
    {
        return ! $store->trashed() && $user->role === 'super_admin' && $this->hasPlatformAccess($user);
    }

    public function delete(User $user, Store $store): bool
    {
        return $this->manage($user, $store);
    }

    public function restore(User $user, Store $store): bool
    {
        return false;
    }

    public function forceDelete(User $user, Store $store): bool
    {
        return false;
    }

    private function hasPlatformAccess(User $user): bool
    {
        return $this->loginEligibility->allows($user)
            && $user->store_id === null
            && $this->tenantContext->allowsPlatformAccess()
            && $this->tenantContext->actorId() === (int) $user->getKey();
    }
}

<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\NfcCard;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\QueueStatusHistory;
use App\Models\User;
use App\Services\Auth\LoginEligibility;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

class TenantResourcePolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LoginEligibility $loginEligibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->canAccessTenant($user);
    }

    public function before(User $user, string $ability, mixed ...$arguments): ?bool
    {
        if ($ability !== 'create') {
            return null;
        }

        $resourceClass = $arguments[0] ?? null;

        if (! is_string($resourceClass) || ! $this->canAccessTenant($user)) {
            return false;
        }

        return match ($resourceClass) {
            NfcCard::class => $this->hasPlatformAccess($user),
            Product::class, Queue::class, QueueSession::class => $user->role === 'merchant'
                && ! $this->tenantContext->allowsPlatformAccess(),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function view(User $user, Model $resource): bool
    {
        if (! $this->canAccessTenant($user)) {
            return false;
        }

        if ($this->tenantContext->allowsPlatformAccess()) {
            return true;
        }

        return $this->resourceStoreId($resource) === $this->tenantContext->storeId();
    }

    public function update(User $user, Model $resource): bool
    {
        if (! $this->view($user, $resource)) {
            return false;
        }

        return match (true) {
            $resource instanceof NfcCard => $user->role === 'super_admin' && $this->hasPlatformAccess($user),
            $resource instanceof Product,
            $resource instanceof Order,
            $resource instanceof Queue,
            $resource instanceof QueueSession,
            $resource instanceof OrderItem => $user->role === 'merchant',
            default => false,
        };
    }

    public function delete(User $user, Model $resource): bool
    {
        if (! ($resource instanceof Product || $resource instanceof NfcCard)) {
            return false;
        }

        return $this->view($user, $resource)
            && (($resource instanceof Product && $user->role === 'merchant')
                || ($resource instanceof NfcCard && $this->hasPlatformAccess($user)));
    }

    public function restore(User $user, Model $resource): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $resource): bool
    {
        return false;
    }

    private function canAccessTenant(User $user): bool
    {
        if (! $this->loginEligibility->allows($user)) {
            return false;
        }

        if ($user->role === 'super_admin') {
            return $user->store_id === null
                && $this->tenantContext->allowsPlatformAccess()
                && $this->tenantContext->actorId() === (int) $user->getKey();
        }

        return $user->role === 'merchant'
            && ! $this->tenantContext->allowsPlatformAccess()
            && $this->tenantContext->storeId() !== null
            && $this->tenantContext->storeId() === (int) $user->store_id
            && $this->tenantContext->actorId() === (int) $user->getKey();
    }

    private function hasPlatformAccess(User $user): bool
    {
        return $user->role === 'super_admin'
            && $user->store_id === null
            && $this->tenantContext->allowsPlatformAccess()
            && $this->tenantContext->actorId() === (int) $user->getKey();
    }

    private function resourceStoreId(Model $resource): ?int
    {
        $storeId = match (true) {
            $resource instanceof OrderItem => $resource->order?->store_id,
            $resource instanceof QueueStatusHistory => $resource->queue?->store_id,
            default => $resource->getAttribute('store_id'),
        };

        return $storeId === null ? null : (int) $storeId;
    }
}

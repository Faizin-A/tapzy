<?php

namespace App\Policies;

use App\Models\OrderItem;
use App\Models\User;

class OrderItemPolicy
{
    public function __construct(private readonly TenantResourcePolicy $tenantPolicy) {}

    public function viewAny(User $user): bool
    {
        return $this->tenantPolicy->viewAny($user);
    }

    public function view(User $user, OrderItem $item): bool
    {
        return $this->tenantPolicy->view($user, $item);
    }

    /**
     * Order items must be created through the owning order's authorized workflow.
     */
    public function create(User $user, mixed $resource = null): bool
    {
        return false;
    }

    public function update(User $user, OrderItem $item): bool
    {
        return $this->tenantPolicy->update($user, $item);
    }

    public function delete(User $user, OrderItem $item): bool
    {
        return false;
    }

    public function restore(User $user, OrderItem $item): bool
    {
        return false;
    }

    public function forceDelete(User $user, OrderItem $item): bool
    {
        return false;
    }
}

<?php

namespace App\Policies;

use App\Models\QueueStatusHistory;
use App\Models\User;

class QueueStatusHistoryPolicy
{
    public function __construct(private readonly TenantResourcePolicy $tenantPolicy) {}

    public function viewAny(User $user): bool
    {
        return $this->tenantPolicy->viewAny($user);
    }

    public function view(User $user, QueueStatusHistory $history): bool
    {
        return $this->tenantPolicy->view($user, $history);
    }

    public function create(User $user, mixed $resource = null): bool
    {
        return false;
    }

    public function update(User $user, QueueStatusHistory $history): bool
    {
        return false;
    }

    public function delete(User $user, QueueStatusHistory $history): bool
    {
        return false;
    }

    public function restore(User $user, QueueStatusHistory $history): bool
    {
        return false;
    }

    public function forceDelete(User $user, QueueStatusHistory $history): bool
    {
        return false;
    }
}

<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function __construct(private readonly TenantResourcePolicy $tenantPolicy) {}

    public function viewAny(User $user): bool
    {
        return $this->tenantPolicy->viewAny($user);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->tenantPolicy->view($user, $auditLog);
    }

    public function create(User $user, mixed $resource = null): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function restore(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function forceDelete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}

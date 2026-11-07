<?php

namespace App\Services\Auth;

use App\Models\Store;
use App\Models\User;

class LoginEligibility
{
    public function allows(?User $user): bool
    {
        if ($user === null || $user->trashed()) {
            return false;
        }

        return match ($user->role) {
            'super_admin' => $user->store_id === null,
            'merchant' => $user->store_id !== null && Store::query()
                ->whereKey($user->store_id)
                ->where('is_active', true)
                ->exists(),
            default => false,
        };
    }
}

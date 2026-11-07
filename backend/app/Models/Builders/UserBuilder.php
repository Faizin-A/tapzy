<?php

namespace App\Models\Builders;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

class UserBuilder extends Builder
{
    public function delete()
    {
        throw new LogicException('Delete users individually through UserSessionRevocationService.');
    }

    public function forceDelete()
    {
        throw new LogicException('Force deleting users is not supported.');
    }

    public function restore()
    {
        throw new LogicException('Restore users individually through UserSessionRevocationService.');
    }

    public function update(array $values)
    {
        $lifecycleFields = ['deleted_at', 'session_generation'];
        $updatesLifecycle = array_intersect($lifecycleFields, array_keys($values)) !== [];
        $user = $this->getModel();

        if ($updatesLifecycle && (! $user instanceof User || ! $user->allowsSessionLifecycleMutation())) {
            throw new LogicException('User lifecycle fields may only be changed by UserSessionRevocationService.');
        }

        return parent::update($values);
    }
}

<?php

namespace App\Services\Auth;

use App\Models\User;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use OverflowException;

class UserSessionRevocationService
{
    /**
     * @param  Closure(User): bool|null  $softDelete
     */
    public function softDelete(User $user, Closure $softDelete): ?bool
    {
        if (! $user->exists || $user->getKey() === null) {
            return null;
        }

        $connection = $user->getConnection();
        $purgeSessions = $this->databaseSessionPurgeConnection($connection);

        $deletedUser = $connection->transaction(function () use ($user, $softDelete, $connection, $purgeSessions): User|false {
            $lockedUser = $this->lockUser($user, $connection);

            if ($lockedUser->trashed()) {
                return false;
            }

            $previousGeneration = $this->advanceGeneration($lockedUser, $connection);

            if (! $softDelete($lockedUser)) {
                $this->restoreGeneration($lockedUser, $previousGeneration, $connection);

                return false;
            }

            $this->purgeSessions($purgeSessions, (int) $lockedUser->getKey());

            return $lockedUser;
        });

        if (! $deletedUser instanceof User) {
            return false;
        }

        $this->syncModel($user, $deletedUser);

        return true;
    }

    /**
     * @param  Closure(User): bool  $restore
     */
    public function restore(User $user, Closure $restore): bool
    {
        if (! $user->exists || $user->getKey() === null) {
            return false;
        }

        $connection = $user->getConnection();
        $purgeSessions = $this->databaseSessionPurgeConnection($connection);

        $restoredUser = $connection->transaction(function () use ($user, $restore, $connection, $purgeSessions): User|false {
            $lockedUser = $this->lockUser($user, $connection);

            if (! $lockedUser->trashed()) {
                return false;
            }

            $previousGeneration = $this->advanceGeneration($lockedUser, $connection);

            // Remove any stale rows left by an interrupted earlier lifecycle operation.
            $this->purgeSessions($purgeSessions, (int) $lockedUser->getKey());
            $result = $restore($lockedUser);

            if (! $result) {
                $this->restoreGeneration($lockedUser, $previousGeneration, $connection);

                return false;
            }

            return $lockedUser;
        });

        if (! $restoredUser instanceof User) {
            return false;
        }

        $this->syncModel($user, $restoredUser);

        return true;
    }

    private function lockUser(User $user, Connection $connection): User
    {
        $lockedUser = $user->newQuery()
            ->withTrashed()
            ->whereKey($user->getKey())
            ->lockForUpdate()
            ->first();

        if (! $lockedUser instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class, [$user->getKey()]);
        }

        if ($lockedUser->getConnection()->getName() !== $connection->getName()) {
            throw new LogicException('User row lock must use the model connection.');
        }

        return $lockedUser;
    }

    private function advanceGeneration(User $user, Connection $connection): int
    {
        $generation = $user->session_generation;

        if (! is_int($generation) || $generation < 1) {
            throw new LogicException('The user session generation is missing or invalid.');
        }

        if ($generation === PHP_INT_MAX) {
            throw new OverflowException('The user session generation cannot be incremented.');
        }

        $nextGeneration = $generation + 1;
        $updatedAt = $user->freshTimestamp();
        $updated = $connection->table($user->getTable())
            ->where($user->getKeyName(), $user->getKey())
            ->where('session_generation', $generation)
            ->update([
                'session_generation' => $nextGeneration,
                'updated_at' => $updatedAt,
            ]);

        if ($updated !== 1) {
            throw new LogicException('Unable to update the user session generation.');
        }

        $user->session_generation = $nextGeneration;
        $user->updated_at = $updatedAt;
        $user->syncOriginal();

        return $generation;
    }

    private function restoreGeneration(User $user, int $generation, Connection $connection): void
    {
        $updatedAt = $user->freshTimestamp();
        $updated = $connection->table($user->getTable())
            ->where($user->getKeyName(), $user->getKey())
            ->update([
                'session_generation' => $generation,
                'updated_at' => $updatedAt,
            ]);

        if ($updated !== 1) {
            throw new LogicException('Unable to restore the user session generation.');
        }

        $user->session_generation = $generation;
        $user->updated_at = $updatedAt;
        $user->syncOriginal();
    }

    private function databaseSessionPurgeConnection(Connection $userConnection): ?Connection
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        $sessionConnectionName = config('session.connection') ?: config('database.default');
        $sessionConnection = app('db')->connection($sessionConnectionName);

        if ($sessionConnection->getName() !== $userConnection->getName()) {
            throw new LogicException(
                'Database session revocation requires the session table and users table to share one connection.'
            );
        }

        $sessionTable = config('session.table', 'sessions');

        if (! $sessionConnection->getSchemaBuilder()->hasTable($sessionTable)) {
            throw new LogicException('The configured database session table does not exist.');
        }

        return $sessionConnection;
    }

    private function purgeSessions(?Connection $connection, int $userId): void
    {
        if ($connection === null) {
            return;
        }

        $connection->table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->delete();
    }

    private function syncModel(User $target, User $source): void
    {
        $target->setRawAttributes($source->getAttributes(), true);
        $target->exists = $source->exists;
    }
}

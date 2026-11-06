<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use LogicException;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Str::random(64),
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            $validRoleAndStore = match ($user->role) {
                'super_admin' => $user->store_id === null,
                'merchant' => $user->store_id !== null,
                default => false,
            };

            if (! $validRoleAndStore) {
                throw new LogicException('Choose the superAdmin() or merchant(Store $store) factory state.');
            }
        });
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'super_admin',
            'store_id' => null,
        ]);
    }

    public function merchant(Store $store): static
    {
        if (! $store->exists || $store->getKey() === null || $store->trashed()) {
            throw new LogicException('The merchant factory state requires a persisted, non-deleted store.');
        }

        return $this->state(fn (array $attributes) => [
            'role' => 'merchant',
            'store_id' => $store->getKey(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

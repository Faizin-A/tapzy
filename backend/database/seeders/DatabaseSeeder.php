<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = getenv('TAPZY_SUPER_ADMIN_PASSWORD');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set TAPZY_SUPER_ADMIN_PASSWORD to seed the initial super admin.');
        }

        User::factory()->superAdmin()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => $password,
        ]);
    }
}

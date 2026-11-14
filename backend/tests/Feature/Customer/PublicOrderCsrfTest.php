<?php

namespace Tests\Feature\Customer;

use App\Services\Customer\CustomerOrderCreationService;
use App\Services\Customer\PublicStoreResolutionService;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicOrderCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
    }

    public function test_public_order_creation_is_not_blocked_by_csrf(): void
    {
        $this->mock(CustomerOrderCreationService::class, function ($mock): void {
            $mock->shouldReceive('create')->once()->andReturn(null);
        });

        $this->postJson('/api/v1/public/queues/'.str_repeat('A', 40).'/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ], ['Origin' => 'http://localhost'])->assertNotFound();
    }

    public function test_public_queue_creation_is_not_blocked_by_csrf(): void
    {
        $this->mock(PublicStoreResolutionService::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(null);
        });

        $this->postJson(
            '/api/v1/public/stores/'.str_repeat('B', 40).'/queues',
            ['customer_name' => 'Budi'],
            [
                'Origin' => 'http://localhost',
                'Idempotency-Key' => (string) Str::uuid(),
            ],
        )->assertNotFound();
    }

    public function test_login_still_requires_csrf(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'merchant@example.com',
            'password' => 'password',
        ], ['Origin' => 'http://localhost'])->assertStatus(419);
    }

    public function test_authenticated_admin_endpoints_still_require_csrf(): void
    {
        $this->postJson('/api/v1/admin/stores', [
            'name' => 'Toko',
        ], ['Origin' => 'http://localhost'])->assertStatus(419);
    }
}

<?php

namespace Tests\Feature\Customer;

use Tests\TestCase;

class CustomerShellTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    }

    public function test_customer_shell_is_served_for_valid_store_token(): void
    {
        $this->withoutVite();

        $this->get('/t/'.str_repeat('A', 40))
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSee('TAPZY');
    }

    public function test_customer_shell_rejects_malformed_token(): void
    {
        $this->withoutVite();

        $this->get('/t/too-short')->assertNotFound();
    }

    public function test_customer_shell_is_served_for_valid_order_access_token(): void
    {
        $this->withoutVite();

        $this->get('/q/'.str_repeat('B', 40))
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSee('TAPZY');
    }
}

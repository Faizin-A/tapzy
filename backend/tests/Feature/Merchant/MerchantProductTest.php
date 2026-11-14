<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;

class MerchantProductTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const TABLES = ['audit_logs', 'products', 'users', 'stores'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=', 'session.driver' => 'array']);
        $this->setUpPrefixedMySqlDatabase('merchant_product');
        foreach (self::TABLES as $table) Schema::dropIfExists($table);
        Schema::create('stores', function (Blueprint $table): void {
            $table->id(); $table->string('name', 150); $table->string('slug', 150)->unique(); $table->boolean('is_active')->default(true); $table->dateTime('expires_at'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password'); $table->string('role'); $table->unsignedBigInteger('store_id')->nullable(); $table->unsignedBigInteger('session_generation')->default(1); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('store_id'); $table->string('name', 150); $table->text('description')->nullable(); $table->decimal('price', 12, 2)->default(0); $table->string('category', 100)->nullable(); $table->string('image_url', 255)->nullable(); $table->boolean('is_available')->default(true); $table->boolean('is_active')->default(true); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('store_id')->nullable(); $table->unsignedBigInteger('user_id')->nullable(); $table->string('action'); $table->string('entity_type')->nullable(); $table->unsignedBigInteger('entity_id')->nullable(); $table->json('old_values')->nullable(); $table->json('new_values')->nullable(); $table->ipAddress('ip_address')->nullable(); $table->text('user_agent')->nullable(); $table->timestamp('created_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownPrefixedMySqlDatabase(self::TABLES);
        parent::tearDown();
    }

    public function test_merchant_can_crud_own_product_and_audit_each_mutation(): void
    {
        [$store, $merchant] = $this->merchantFixture('crud');
        $create = $this->merchantRequest($merchant)->postJson('/api/v1/merchant/products', ['name' => 'Kopi', 'price' => '12.50', 'category' => 'Minuman']);
        $create->assertCreated()->assertJsonPath('data.name', 'Kopi')->assertJsonPath('data.price', '12.50');
        $productId = $create->json('data.id');
        $this->merchantRequest($merchant)->getJson('/api/v1/merchant/products')->assertOk()->assertJsonPath('data.0.name', 'Kopi');
        $this->merchantRequest($merchant)->getJson('/api/v1/merchant/products/'.$productId)->assertOk();
        $this->merchantRequest($merchant)->patchJson('/api/v1/merchant/products/'.$productId, ['name' => 'Kopi Susu'])->assertOk()->assertJsonPath('data.name', 'Kopi Susu');
        $this->merchantRequest($merchant)->patchJson('/api/v1/merchant/products/'.$productId.'/status', ['is_available' => false])->assertOk()->assertJsonPath('data.is_available', false);
        $this->merchantRequest($merchant)->deleteJson('/api/v1/merchant/products/'.$productId)->assertNoContent();
        $this->assertSame(4, DB::table('audit_logs')->where('store_id', $store->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::PRODUCT_CREATED, 'entity_id' => $productId]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::PRODUCT_UPDATED, 'entity_id' => $productId]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::PRODUCT_STATUS_UPDATED, 'entity_id' => $productId]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::PRODUCT_DELETED, 'entity_id' => $productId]);
    }

    public function test_product_tenant_isolation_and_store_id_payload_protection(): void
    {
        [$store, $merchant] = $this->merchantFixture('own');
        $other = $this->storeFixture('other');
        $productId = $this->productFixture($other, 'Other product');
        $request = $this->merchantRequest($merchant);
        $request->getJson('/api/v1/merchant/products/'.$productId)->assertNotFound();
        $request->patchJson('/api/v1/merchant/products/'.$productId, ['name' => 'Tampered'])->assertNotFound();
        $request->deleteJson('/api/v1/merchant/products/'.$productId)->assertNotFound();
        $request->postJson('/api/v1/merchant/products', ['name' => 'Spoofed', 'price' => 10, 'store_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('store_id');
        $this->assertSame(0, DB::table('products')->where('store_id', $store->id)->count());
    }

    public function test_soft_deleted_product_is_hidden_and_invalid_values_are_rejected(): void
    {
        [$store, $merchant] = $this->merchantFixture('validation');
        $productId = $this->productFixture($store, 'Deleted product');
        $activeProductId = $this->productFixture($store, 'Active product');
        DB::table('products')->where('id', $productId)->update(['deleted_at' => now()]);
        $request = $this->merchantRequest($merchant);
        $request->getJson('/api/v1/merchant/products/'.$productId)->assertNotFound();
        $request->getJson('/api/v1/merchant/products')->assertOk()->assertJsonMissing(['id' => $productId]);
        $request->postJson('/api/v1/merchant/products', ['name' => 'Invalid', 'price' => -1])->assertUnprocessable()->assertJsonValidationErrors('price');
        $request->patchJson('/api/v1/merchant/products/'.$activeProductId.'/status', [])->assertUnprocessable();
    }

    public function test_inactive_store_cannot_use_product_endpoints(): void
    {
        [$store, $merchant] = $this->merchantFixture('inactive');
        DB::table('stores')->where('id', $store->id)->update(['is_active' => false]);
        $this->merchantRequest($merchant)->getJson('/api/v1/merchant/products')->assertForbidden();
    }

    private function merchantRequest(User $merchant): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])
            ->withSession(['auth_session_generation' => 1])
            ->actingAs($merchant, 'web');
    }

    /** @return array{0: Store, 1: User} */
    private function merchantFixture(string $suffix): array
    {
        $store = $this->storeFixture($suffix);
        $id = DB::table('users')->insertGetId(['name' => 'Merchant', 'email' => $suffix.'-'.bin2hex(random_bytes(4)).'@example.test', 'password' => 'irrelevant', 'role' => 'merchant', 'store_id' => $store->id, 'session_generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return [$store, User::query()->findOrFail($id)];
    }

    private function storeFixture(string $suffix): Store
    {
        return Store::create(['name' => 'Store '.$suffix, 'slug' => 'store-'.$suffix.'-'.bin2hex(random_bytes(3)), 'is_active' => true, 'expires_at' => now()->addYear()]);
    }

    private function productFixture(Store $store, string $name): int
    {
        return DB::table('products')->insertGetId(['store_id' => $store->id, 'name' => $name, 'price' => '10.00', 'is_active' => true, 'is_available' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class UserStoreModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::dropIfExists('users');
        Schema::dropIfExists('stores');

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role');
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_valid_role_and_store_combinations_can_be_created(): void
    {
        $store = $this->createStore();

        $merchant = $this->createUser('merchant', $store->id);
        $superAdmin = $this->createUser('super_admin', null);

        $this->assertDatabaseHas('users', [
            'id' => $merchant->id,
            'role' => 'merchant',
            'store_id' => $store->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $superAdmin->id,
            'role' => 'super_admin',
            'store_id' => null,
        ]);
    }

    public function test_invalid_role_and_store_combinations_are_rejected_on_create(): void
    {
        $store = $this->createStore();
        $user = new User(['name' => 'Invalid merchant', 'email' => 'invalid@example.test']);
        $user->role = 'merchant';
        $user->store_id = null;

        try {
            $user->save();
            $this->fail('A merchant without a store must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('users', ['email' => 'invalid@example.test']);
        }

        $invalidAdmin = new User(['name' => 'Invalid admin', 'email' => 'admin@example.test']);
        $invalidAdmin->role = 'super_admin';
        $invalidAdmin->store_id = $store->id;

        try {
            $invalidAdmin->save();
            $this->fail('A super admin assigned to a store must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
        }
    }

    public function test_invalid_role_and_store_combinations_are_rejected_on_update(): void
    {
        $store = $this->createStore();
        $merchant = $this->createUser('merchant', $store->id);

        $merchant->store_id = null;

        try {
            $merchant->save();
            $this->fail('Removing a merchant store must be rejected.');
        } catch (ValidationException) {
            $this->assertSame($store->id, $merchant->fresh()->store_id);
        }

        $merchant->store_id = $store->id;
        $merchant->role = 'super_admin';

        try {
            $merchant->save();
            $this->fail('Changing a merchant to super admin while retaining a store must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('merchant', $merchant->fresh()->role);
        }
    }

    public function test_user_and_store_relationships_are_configured(): void
    {
        $store = $this->createStore();
        $merchant = $this->createUser('merchant', $store->id);

        $this->assertInstanceOf(Store::class, $merchant->store);
        $this->assertSame($store->id, $merchant->store->id);
        $this->assertTrue($store->users->contains('id', $merchant->id));
    }

    public function test_user_and_store_use_soft_deletes(): void
    {
        $store = $this->createStore();
        $merchant = $this->createUser('merchant', $store->id);

        $merchant->delete();
        $store->delete();

        $this->assertNull(User::find($merchant->id));
        $this->assertNotNull(User::withTrashed()->find($merchant->id)?->deleted_at);
        $this->assertNull(Store::find($store->id));
        $this->assertNotNull(Store::withTrashed()->find($store->id)?->deleted_at);
    }

    public function test_normal_mass_assignment_cannot_set_role_or_store_id(): void
    {
        $store = $this->createStore();

        $user = new User([
            'name' => 'Request user',
            'email' => 'request@example.test',
            'role' => 'super_admin',
            'store_id' => $store->id,
        ]);

        $this->assertNull($user->role);
        $this->assertNull($user->store_id);

        $merchant = $this->createUser('merchant', $store->id);
        $merchant->fill(['role' => 'super_admin', 'store_id' => null]);

        $this->assertSame('merchant', $merchant->role);
        $this->assertSame($store->id, $merchant->store_id);
    }

    public function test_factory_requires_an_explicit_valid_role_state(): void
    {
        try {
            User::factory()->create();
            $this->fail('A factory without a role/store state must not create an invalid user.');
        } catch (LogicException) {
            $this->assertDatabaseCount('users', 0);
        }

        $superAdmin = User::factory()->superAdmin()->create();
        $store = $this->createStore();
        $merchant = User::factory()->merchant($store)->create();

        $this->assertSame('super_admin', $superAdmin->role);
        $this->assertNull($superAdmin->store_id);
        $this->assertSame('merchant', $merchant->role);
        $this->assertSame($store->id, $merchant->store_id);
    }

    public function test_database_seeder_creates_one_super_admin_with_model_events_enabled(): void
    {
        $previousPassword = getenv('TAPZY_SUPER_ADMIN_PASSWORD');
        $seedPassword = Str::random(64);
        $savingEvents = 0;

        putenv('TAPZY_SUPER_ADMIN_PASSWORD='.$seedPassword);
        User::saving(function () use (&$savingEvents): void {
            $savingEvents++;
        });

        try {
            $this->seed(DatabaseSeeder::class);

            $this->assertSame(1, $savingEvents);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('stores', 0);

            $superAdmin = User::query()->sole();
            $this->assertSame('super_admin', $superAdmin->role);
            $this->assertNull($superAdmin->store_id);
            $this->assertTrue(Hash::check($seedPassword, $superAdmin->password));
        } finally {
            if ($previousPassword === false) {
                putenv('TAPZY_SUPER_ADMIN_PASSWORD');
            } else {
                putenv('TAPZY_SUPER_ADMIN_PASSWORD='.$previousPassword);
            }
        }
    }

    public function test_merchant_factory_rejects_unsaved_or_soft_deleted_store(): void
    {
        $unsavedStore = new Store(['name' => 'Unsaved', 'slug' => 'unsaved']);

        try {
            User::factory()->merchant($unsavedStore);
            $this->fail('A merchant factory state must require a persisted store.');
        } catch (LogicException) {
            $this->assertDatabaseCount('users', 0);
        }

        $deletedStore = $this->createStore();
        $deletedStore->delete();

        try {
            User::factory()->merchant($deletedStore);
            $this->fail('A merchant factory state must reject a soft-deleted store.');
        } catch (LogicException) {
            $this->assertDatabaseCount('users', 0);
        }
    }

    private function createStore(): Store
    {
        return Store::create([
            'name' => 'Test Store',
            'slug' => 'test-store',
        ]);
    }

    private function createUser(string $role, ?int $storeId): User
    {
        $user = new User([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => 'secret',
        ]);
        $user->role = $role;
        $user->store_id = $storeId;
        $user->save();

        return $user;
    }
}

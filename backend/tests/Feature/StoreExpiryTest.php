<?php

namespace Tests\Feature;

use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreExpiryTest extends TestCase
{
    private string $originalConnection;

    private string $connectionName;

    private string $tablePrefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        $this->connectionName = 'tapzy_mysql_store_expiry_'.bin2hex(random_bytes(4));
        $this->tablePrefix = 'store_expiry_'.bin2hex(random_bytes(4)).'_';

        $config = config('database.connections.'.$this->originalConnection);
        $config['prefix'] = $this->tablePrefix;

        config(['database.connections.'.$this->connectionName => $config]);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->connectionName);

        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('tapzy_test', $connection->getDatabaseName());
        $this->assertSame('127.0.0.1', $connection->getConfig('host'));
        $this->assertSame('3307', (string) $connection->getConfig('port'));
        $this->assertSame($this->tablePrefix, $connection->getTablePrefix());
        $this->assertSame('Asia/Jakarta', config('app.timezone'));

        Schema::dropIfExists('stores');
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->connectionName) && config('database.connections.'.$this->connectionName) !== null) {
            DB::setDefaultConnection($this->connectionName);

            if (DB::connection()->getTablePrefix() === $this->tablePrefix) {
                DB::purge($this->connectionName);
                Schema::dropIfExists('stores');
            }

            DB::purge($this->connectionName);
            DB::setDefaultConnection($this->originalConnection);
        }

        parent::tearDown();
    }

    public function test_expires_at_is_stored_in_utc_and_exposed_in_jakarta(): void
    {
        $store = Store::create([
            'name' => 'Cast Store',
            'slug' => 'cast-store',
            'expires_at' => '2027-01-01 10:00:00',
        ]);

        $this->assertSame(
            '2027-01-01 03:00:00',
            DB::table('stores')->where('id', $store->id)->value('expires_at')
        );

        $fresh = $store->fresh();
        $this->assertNotNull($fresh);
        $this->assertInstanceOf(CarbonImmutable::class, $fresh->expires_at);
        $this->assertSame('Asia/Jakarta', $fresh->expires_at->getTimezone()->getName());
        $this->assertSame('2027-01-01 10:00:00', $fresh->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_carbon_input_is_converted_to_utc_without_double_conversion(): void
    {
        $input = CarbonImmutable::parse('2027-06-15 08:30:00', 'Asia/Jakarta');

        $store = Store::create([
            'name' => 'Carbon Store',
            'slug' => 'carbon-store',
            'expires_at' => $input,
        ]);

        $this->assertSame(
            '2027-06-15 01:30:00',
            DB::table('stores')->where('id', $store->id)->value('expires_at')
        );

        $fresh = $store->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('2027-06-15 08:30:00', $fresh->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_is_expired_and_is_expiring_soon_boundaries(): void
    {
        $expired = Store::create([
            'name' => 'Expired Store',
            'slug' => 'expired-store',
            'expires_at' => now()->subMinute(),
        ]);
        $this->assertTrue($expired->isExpired());
        $this->assertFalse($expired->isExpiringSoon());

        $soon = Store::create([
            'name' => 'Soon Store',
            'slug' => 'soon-store',
            'expires_at' => now()->addDays(3),
        ]);
        $this->assertFalse($soon->isExpired());
        $this->assertTrue($soon->isExpiringSoon(7));
        $this->assertFalse($soon->isExpiringSoon(1));

        $later = Store::create([
            'name' => 'Later Store',
            'slug' => 'later-store',
            'expires_at' => now()->addDays(30),
        ]);
        $this->assertFalse($later->isExpired());
        $this->assertFalse($later->isExpiringSoon(7));
    }

    public function test_expiry_does_not_change_is_active_automatically(): void
    {
        $store = Store::create([
            'name' => 'Inactive Expired Store',
            'slug' => 'inactive-expired-store',
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($store->isExpired());

        $fresh = $store->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(1, (int) $fresh->is_active);
        $this->assertTrue($fresh->isExpired());
    }

    public function test_expired_scope_includes_null_expiry_and_not_expired_excludes_it(): void
    {
        Schema::dropIfExists('stores');
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $missingExpiry = Store::create([
            'name' => 'Missing Expiry Store',
            'slug' => 'missing-expiry-store',
            'expires_at' => null,
        ]);
        $expired = Store::create([
            'name' => 'Expired Scope Store',
            'slug' => 'expired-scope-store',
            'expires_at' => now()->subMinute(),
        ]);
        $valid = Store::create([
            'name' => 'Valid Scope Store',
            'slug' => 'valid-scope-store',
            'expires_at' => now()->addDay(),
        ]);

        $this->assertSame(
            [$missingExpiry->id, $expired->id],
            Store::query()->expired()->orderBy('id')->pluck('id')->all()
        );
        $this->assertSame(
            [$valid->id],
            Store::query()->notExpired()->orderBy('id')->pluck('id')->all()
        );
    }

    public function test_null_expiry_is_fail_closed(): void
    {
        $store = new Store([
            'name' => 'No Expiry Store',
            'slug' => 'no-expiry-store',
        ]);

        $this->assertNull($store->expires_at);
        $this->assertTrue($store->isExpired());
        $this->assertFalse($store->isExpiringSoon());
    }
}

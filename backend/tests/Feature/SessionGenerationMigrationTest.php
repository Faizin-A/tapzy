<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SessionGenerationMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
    }

    public function test_existing_and_new_users_receive_generation_one_and_rollback_only_drops_new_column(): void
    {
        DB::table('users')->insert(['email' => 'existing@example.test']);
        $migration = require database_path('migrations/2026_11_06_000010_add_session_generation_to_users_table.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'session_generation'));
        $generationColumn = collect(Schema::getColumns('users'))->firstWhere('name', 'session_generation');
        $this->assertNotNull($generationColumn);
        $this->assertFalse($generationColumn['nullable']);
        $this->assertSame('1', trim((string) $generationColumn['default'], " '()"));
        $this->assertStringContainsString('int', strtolower($generationColumn['type']));
        $this->assertSame(1, (int) DB::table('users')->where('email', 'existing@example.test')->value('session_generation'));

        DB::table('users')->insert(['email' => 'new@example.test']);

        $this->assertSame(1, (int) DB::table('users')->where('email', 'new@example.test')->value('session_generation'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'session_generation'));
        $this->assertSame(['id', 'email'], Schema::getColumnListing('users'));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', ['email' => 'existing@example.test']);
        $this->assertDatabaseHas('users', ['email' => 'new@example.test']);
    }
}

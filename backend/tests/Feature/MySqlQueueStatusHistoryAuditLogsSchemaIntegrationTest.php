<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Queue;
use App\Models\QueueStatusHistory;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MySqlQueueStatusHistoryAuditLogsSchemaIntegrationTest extends TestCase
{
    private string $connectionName = 'tapzy_mysql_queue_audit_logs_integration';

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('TAPZY_MYSQL_TEST_DATABASE');

        if ($database !== 'tapzy_test_queue_audit_logs'
            || ! preg_match('/^tapzy_test_[A-Za-z0-9_]+$/', (string) $database)) {
            throw new RuntimeException(
                'Queue/audit integration tests require TAPZY_MYSQL_TEST_DATABASE=tapzy_test_queue_audit_logs.'
            );
        }

        $username = getenv('TAPZY_MYSQL_TEST_USERNAME');

        if (! $username) {
            throw new RuntimeException('Set TAPZY_MYSQL_TEST_USERNAME for the isolated MySQL integration test.');
        }

        config([
            'database.connections.'.$this->connectionName => [
                'driver' => 'mysql',
                'host' => getenv('TAPZY_MYSQL_TEST_HOST') ?: '127.0.0.1',
                'port' => getenv('TAPZY_MYSQL_TEST_PORT') ?: '3306',
                'database' => $database,
                'username' => $username,
                'password' => getenv('TAPZY_MYSQL_TEST_PASSWORD') ?: '',
                'unix_socket' => getenv('TAPZY_MYSQL_TEST_SOCKET') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ],
            'database.default' => $this->connectionName,
        ]);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->connectionName);

        $connection = DB::connection($this->connectionName);
        $actualDatabase = $connection->selectOne('SELECT DATABASE() AS database_name')->database_name;
        $version = $connection->selectOne('SELECT VERSION() AS version')->version;

        if ($actualDatabase !== 'tapzy_test_queue_audit_logs') {
            throw new RuntimeException('Refusing to run queue/audit tests outside tapzy_test_queue_audit_logs.');
        }

        if (! preg_match('/^8\.4\./', $version)) {
            throw new RuntimeException('Queue/audit integration tests require MySQL 8.4; connected version: '.$version);
        }

        foreach ([
            'users', 'stores', 'nfc_cards', 'queue_sessions', 'queues',
            'queue_status_histories', 'audit_logs',
        ] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException(
                    'The isolated tapzy_test_queue_audit_logs database must not already contain prerequisite tables.'
                );
            }
        }
    }

    public function test_mysql_queue_history_and_audit_log_schema_integrity(): void
    {
        $usersMigration = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $storesMigration = require database_path('migrations/2026_11_06_000000_create_stores_table.php');
        $userTenantMigration = require database_path('migrations/2026_11_06_000001_add_tenant_columns_to_users_table.php');
        $cardsMigration = require database_path('migrations/2026_11_06_000002_create_nfc_cards_table.php');
        $sessionsMigration = require database_path('migrations/2026_11_06_000003_create_queue_sessions_table.php');
        $queuesMigration = require database_path('migrations/2026_11_06_000004_create_queues_table.php');
        $historyMigration = require database_path('migrations/2026_11_06_000008_create_queue_status_histories_table.php');
        $auditMigration = require database_path('migrations/2026_11_06_000009_create_audit_logs_table.php');

        try {
            $usersMigration->up();
            $storesMigration->up();
            $userTenantMigration->up();
            $cardsMigration->up();
            $sessionsMigration->up();
            $queuesMigration->up();
            $historyMigration->up();
            $auditMigration->up();

            $this->assertSame([
                'id', 'queue_id', 'previous_status', 'new_status', 'changed_by_user_id', 'notes', 'created_at',
            ], Schema::getColumnListing('queue_status_histories'));
            $this->assertSame([
                'id', 'store_id', 'user_id', 'action', 'entity_type', 'entity_id', 'old_values',
                'new_values', 'ip_address', 'user_agent', 'created_at',
            ], Schema::getColumnListing('audit_logs'));

            $statusEnum = "enum('waiting','called','in_service','skipped','completed','cancelled')";
            $this->assertColumn('queue_status_histories', 'queue_id', 'bigint unsigned', 'NO', null);
            $this->assertColumn('queue_status_histories', 'previous_status', $statusEnum, 'YES', null);
            $this->assertColumn('queue_status_histories', 'new_status', $statusEnum, 'NO', null);
            $this->assertColumn('queue_status_histories', 'changed_by_user_id', 'bigint unsigned', 'YES', null);
            $this->assertColumn('queue_status_histories', 'notes', 'varchar(255)', 'YES', null);
            $this->assertColumn('queue_status_histories', 'created_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP');
            $this->assertFalse(Schema::hasColumn('queue_status_histories', 'updated_at'));
            $this->assertFalse(Schema::hasColumn('queue_status_histories', 'deleted_at'));

            $this->assertColumn('audit_logs', 'store_id', 'bigint unsigned', 'YES', null);
            $this->assertColumn('audit_logs', 'user_id', 'bigint unsigned', 'YES', null);
            $this->assertColumn('audit_logs', 'action', 'varchar(100)', 'NO', null);
            $this->assertColumn('audit_logs', 'entity_type', 'varchar(100)', 'YES', null);
            $this->assertColumn('audit_logs', 'entity_id', 'bigint unsigned', 'YES', null);
            $this->assertColumn('audit_logs', 'old_values', 'json', 'YES', null);
            $this->assertColumn('audit_logs', 'new_values', 'json', 'YES', null);
            $this->assertColumn('audit_logs', 'ip_address', 'varchar(45)', 'YES', null);
            $this->assertColumn('audit_logs', 'user_agent', 'text', 'YES', null);
            $this->assertColumn('audit_logs', 'created_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP');
            $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'));
            $this->assertFalse(Schema::hasColumn('audit_logs', 'deleted_at'));

            $this->assertIndex('queue_status_histories', 'queue_status_histories_queue_id_index', ['queue_id']);
            $this->assertIndex(
                'queue_status_histories',
                'queue_status_histories_changed_by_user_id_index',
                ['changed_by_user_id']
            );
            $this->assertIndex('queue_status_histories', 'queue_status_histories_new_status_index', ['new_status']);
            $this->assertIndex('queue_status_histories', 'queue_status_histories_created_at_index', ['created_at']);
            $this->assertIndex('audit_logs', 'audit_logs_store_id_index', ['store_id']);
            $this->assertIndex('audit_logs', 'audit_logs_user_id_index', ['user_id']);
            $this->assertIndex('audit_logs', 'audit_logs_action_index', ['action']);
            $this->assertIndex('audit_logs', 'audit_logs_entity_id_index', ['entity_id']);
            $this->assertIndex('audit_logs', 'audit_logs_created_at_index', ['created_at']);

            $this->assertForeignKey('queue_status_histories', 'queue_id', 'queues', 'restrict');
            $this->assertForeignKey('queue_status_histories', 'changed_by_user_id', 'users', 'set null');
            $this->assertForeignKey('audit_logs', 'store_id', 'stores', 'set null');
            $this->assertForeignKey('audit_logs', 'user_id', 'users', 'set null');

            $queueStore = $this->createStore('Queue history store');
            $queue = $this->createQueue($queueStore);
            $actor = $this->createSuperAdmin();
            $queueHistory = $this->createQueueHistory($queue, $actor);
            $auditStore = $this->createStore('Audit log store');
            $auditLog = $this->createAuditLog($auditStore, $actor);

            $this->assertNotNull($queueHistory->created_at);
            $this->assertSame(['status' => 'waiting'], $auditLog->old_values);
            $this->assertSame(['status' => 'called'], $auditLog->new_values);
            $this->assertNotNull($auditLog->created_at);

            try {
                DB::table('queues')->where('id', $queue->id)->delete();
                $this->fail('A queue with status history must be protected by ON DELETE RESTRICT.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            DB::table('users')->where('id', $actor->id)->delete();
            $this->assertNull(DB::table('queue_status_histories')->where('id', $queueHistory->id)->value('changed_by_user_id'));
            $this->assertNull(DB::table('audit_logs')->where('id', $auditLog->id)->value('user_id'));
            $this->assertTrue(DB::table('queue_status_histories')->where('id', $queueHistory->id)->exists());
            $this->assertTrue(DB::table('audit_logs')->where('id', $auditLog->id)->exists());

            DB::table('stores')->where('id', $auditStore->id)->delete();
            $this->assertNull(DB::table('audit_logs')->where('id', $auditLog->id)->value('store_id'));
            $this->assertSame(['status' => 'waiting'], AuditLog::findOrFail($auditLog->id)->old_values);
        } finally {
            if (Schema::hasTable('audit_logs')) {
                $auditMigration->down();
            }

            if (Schema::hasTable('queue_status_histories')) {
                $historyMigration->down();
            }

            if (Schema::hasTable('queues')) {
                $queuesMigration->down();
            }

            if (Schema::hasTable('queue_sessions')) {
                $sessionsMigration->down();
            }

            if (Schema::hasTable('nfc_cards')) {
                $cardsMigration->down();
            }

            if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
                $userTenantMigration->down();
            }

            if (Schema::hasTable('stores')) {
                $storesMigration->down();
            }

            if (Schema::hasTable('users')) {
                $usersMigration->down();
            }
        }
    }

    private function assertColumn(string $table, string $column, string $type, string $nullable, ?string $default): void
    {
        $actual = DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        $this->assertNotNull($actual);
        $this->assertSame($type, strtolower($actual->COLUMN_TYPE));
        $this->assertSame($nullable, $actual->IS_NULLABLE);
        $this->assertSame($default, $actual->COLUMN_DEFAULT === null ? null : (string) $actual->COLUMN_DEFAULT);
    }

    /** @param list<string> $columns */
    private function assertIndex(string $table, string $name, array $columns): void
    {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);

        $this->assertNotNull($index, "Expected index {$name} on {$table} to exist.");
        $this->assertSame($columns, $index['columns']);
        $this->assertFalse($index['unique']);
    }

    private function assertForeignKey(string $table, string $column, string $parent, string $onDelete): void
    {
        $foreignKey = collect(Schema::getForeignKeys($table))
            ->first(fn (array $key) => $key['columns'] === [$column]);

        $this->assertNotNull($foreignKey);
        $this->assertSame($parent, $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame($onDelete, $foreignKey['on_delete']);
        $this->assertSame('cascade', $foreignKey['on_update']);
    }

    private function createStore(string $name): Store
    {
        return Store::create([
            'name' => $name,
            'slug' => 'queue-audit-'.bin2hex(random_bytes(6)),
        ]);
    }

    private function createSuperAdmin(): User
    {
        $user = new User([
            'name' => 'Audit Actor',
            'email' => 'audit-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'secret',
        ]);
        $user->role = 'super_admin';
        $user->store_id = null;
        $user->save();

        return $user;
    }

    private function createQueue(Store $store): Queue
    {
        $session = $store->queueSessions()->create([
            'session_code' => 'AUDIT-'.bin2hex(random_bytes(4)),
            'session_date' => '2026-11-06',
            'opened_at' => now(),
        ]);
        $queue = $session->queues()->make();
        $queue->store_id = $store->id;
        $queue->queue_number = 1;
        $queue->formatted_number = 'A-001';
        $queue->source = 'manual';
        $queue->access_token_hash = hash('sha256', random_bytes(32));
        $queue->save();

        return $queue;
    }

    private function createQueueHistory(Queue $queue, User $actor): QueueStatusHistory
    {
        $history = new QueueStatusHistory;
        $history->queue_id = $queue->id;
        $history->previous_status = null;
        $history->new_status = 'waiting';
        $history->changed_by_user_id = $actor->id;
        $history->notes = 'Initial queue state';
        $history->save();

        return $history->refresh();
    }

    private function createAuditLog(Store $store, User $actor): AuditLog
    {
        $auditLog = new AuditLog;
        $auditLog->store_id = $store->id;
        $auditLog->user_id = $actor->id;
        $auditLog->action = 'queue.status.changed';
        $auditLog->entity_type = Queue::class;
        $auditLog->entity_id = 1;
        $auditLog->old_values = ['status' => 'waiting'];
        $auditLog->new_values = ['status' => 'called'];
        $auditLog->ip_address = '127.0.0.1';
        $auditLog->user_agent = 'integration-test-agent';
        $auditLog->save();

        return $auditLog->refresh();
    }
}

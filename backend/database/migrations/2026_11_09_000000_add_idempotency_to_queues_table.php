<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const IDEMPOTENCY_INDEX = 'uq_store_queue_idempotency';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('queues')) {
            return;
        }

        Schema::table('queues', function (Blueprint $table): void {
            if (! Schema::hasColumn('queues', 'idempotency_key_hash')) {
                $table->char('idempotency_key_hash', 64)->nullable()->after('notes');
            }

            if (! Schema::hasColumn('queues', 'request_payload_hash')) {
                $table->char('request_payload_hash', 64)->nullable()->after('idempotency_key_hash');
            }
        });

        if (! Schema::hasIndex('queues', self::IDEMPOTENCY_INDEX)) {
            Schema::table('queues', function (Blueprint $table): void {
                $table->unique(['store_id', 'idempotency_key_hash'], self::IDEMPOTENCY_INDEX);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('queues')) {
            return;
        }

        if (Schema::hasIndex('queues', self::IDEMPOTENCY_INDEX)) {
            Schema::table('queues', function (Blueprint $table): void {
                $table->dropUnique(self::IDEMPOTENCY_INDEX);
            });
        }

        Schema::table('queues', function (Blueprint $table): void {
            if (Schema::hasColumn('queues', 'request_payload_hash')) {
                $table->dropColumn('request_payload_hash');
            }

            if (Schema::hasColumn('queues', 'idempotency_key_hash')) {
                $table->dropColumn('idempotency_key_hash');
            }
        });
    }
};

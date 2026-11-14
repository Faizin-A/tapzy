<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('queues') || Schema::hasColumn('queues', 'access_token_ciphertext')) {
            return;
        }

        Schema::table('queues', function (Blueprint $table): void {
            $table->text('access_token_ciphertext')->nullable()->after('access_token_hash');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('queues') && Schema::hasColumn('queues', 'access_token_ciphertext')) {
            Schema::table('queues', function (Blueprint $table): void {
                $table->dropColumn('access_token_ciphertext');
            });
        }
    }
};

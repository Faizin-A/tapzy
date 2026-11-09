<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transitional expiry value for stores that predate the expiry policy.
     */
    private const LEGACY_EXPIRES_AT = '2099-12-31 23:59:59';

    private const EXPIRES_AT_INDEX = 'stores_expires_at_index';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('stores', 'expires_at')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->dateTime('expires_at')->nullable();
            });
        }

        DB::table('stores')
            ->whereNull('expires_at')
            ->update(['expires_at' => self::LEGACY_EXPIRES_AT]);

        Schema::table('stores', function (Blueprint $table): void {
            $table->dateTime('expires_at')->nullable(false)->change();
        });

        if (! Schema::hasIndex('stores', self::EXPIRES_AT_INDEX)) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->index('expires_at', self::EXPIRES_AT_INDEX);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('stores', self::EXPIRES_AT_INDEX)) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->dropIndex(self::EXPIRES_AT_INDEX);
            });
        }

        if (Schema::hasColumn('stores', 'expires_at')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->dropColumn('expires_at');
            });
        }
    }
};

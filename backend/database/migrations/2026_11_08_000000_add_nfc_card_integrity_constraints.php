<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GLOBAL_CARD_UID_INDEX = 'uq_card_uid_global';

    private const SINGLE_STORE_CARD_INDEX = 'uq_store_single_card';

    private const LEGACY_STORE_CARD_UID_INDEX = 'uq_store_card_uid';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertNoDuplicateGlobalCardUids();
        $this->assertNoDuplicateActiveCardsPerStore();

        if (! Schema::hasColumn('nfc_cards', 'active_store_id')) {
            Schema::table('nfc_cards', function (Blueprint $table): void {
                $table->unsignedBigInteger('active_store_id')
                    ->nullable()
                    ->virtualAs('CASE WHEN deleted_at IS NULL THEN store_id ELSE NULL END');
            });
        }

        if (! Schema::hasIndex('nfc_cards', self::GLOBAL_CARD_UID_INDEX)) {
            Schema::table('nfc_cards', function (Blueprint $table): void {
                $table->unique('card_uid', self::GLOBAL_CARD_UID_INDEX);
            });
        }

        if (! Schema::hasIndex('nfc_cards', self::SINGLE_STORE_CARD_INDEX)) {
            Schema::table('nfc_cards', function (Blueprint $table): void {
                $table->unique('active_store_id', self::SINGLE_STORE_CARD_INDEX);
            });
        }

        if (Schema::hasIndex('nfc_cards', self::LEGACY_STORE_CARD_UID_INDEX)) {
            Schema::table('nfc_cards', function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_STORE_CARD_UID_INDEX);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('nfc_cards', self::SINGLE_STORE_CARD_INDEX)) {
            Schema::table('nfc_cards', function (Blueprint $table): void {
                $table->dropUnique(self::SINGLE_STORE_CARD_INDEX);
            });
        }

        if (Schema::hasIndex('nfc_cards', self::GLOBAL_CARD_UID_INDEX)) {
            Schema::table('nfc_cards', function (Blueprint $table): void {
                $table->dropUnique(self::GLOBAL_CARD_UID_INDEX);
            });
        }

        if (Schema::hasColumn('nfc_cards', 'active_store_id')) {
            Schema::table('nfc_cards', function (Blueprint $table): void {
                $table->dropColumn('active_store_id');
            });
        }

        if (! Schema::hasIndex('nfc_cards', self::LEGACY_STORE_CARD_UID_INDEX)) {
            Schema::table('nfc_cards', function (Blueprint $table): void {
                $table->unique(['store_id', 'card_uid'], self::LEGACY_STORE_CARD_UID_INDEX);
            });
        }
    }

    private function assertNoDuplicateGlobalCardUids(): void
    {
        $hasDuplicates = DB::table('nfc_cards')
            ->select('card_uid')
            ->groupBy('card_uid')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'Migration aborted: duplicate nfc_cards.card_uid values must be resolved manually.'
            );
        }
    }

    private function assertNoDuplicateActiveCardsPerStore(): void
    {
        $hasDuplicates = DB::table('nfc_cards')
            ->whereNull('deleted_at')
            ->select('store_id')
            ->groupBy('store_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'Migration aborted: stores with more than one non-deleted NFC card must be resolved manually.'
            );
        }
    }
};

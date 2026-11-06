<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('card_uid', 100);
            $table->char('public_token_hash', 64)->unique();
            $table->string('label', 100);
            $table->enum('type', ['table', 'queue_kiosk', 'counter'])->default('table');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('store_id');
            $table->index('card_uid');
            $table->index('type');
            $table->index('is_active');
            $table->index('deleted_at');
            $table->unique(['store_id', 'card_uid'], 'uq_store_card_uid');
            $table->index(['store_id', 'is_active'], 'idx_store_card_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_cards');
    }
};

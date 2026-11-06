<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('queue_session_id')
                ->constrained('queue_sessions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('nfc_card_id')
                ->nullable()
                ->constrained('nfc_cards')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedInteger('queue_number');
            $table->string('formatted_number', 20);
            $table->string('customer_name', 100)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->enum('source', ['nfc', 'barcode', 'manual'])->default('nfc');
            $table->char('access_token_hash', 64);
            $table->enum('status', ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled'])
                ->default('waiting');
            $table->timestamp('called_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('store_id');
            $table->index('queue_session_id');
            $table->index('nfc_card_id');
            $table->index('source');
            $table->index('status');
            $table->unique(['queue_session_id', 'queue_number'], 'uq_session_queue_num');
            $table->unique('access_token_hash', 'uq_queue_access_token_hash');
            $table->index(['store_id', 'status', 'created_at'], 'idx_store_queue_status_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queues');
    }
};

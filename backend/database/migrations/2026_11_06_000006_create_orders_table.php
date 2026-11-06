<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code', 50)->unique();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('queue_id')
                ->nullable()
                ->constrained('queues')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('nfc_card_id')
                ->nullable()
                ->constrained('nfc_cards')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('customer_name', 100)->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('discount_amount', 12, 2)->default(0.00);
            $table->decimal('total_price', 12, 2)->default(0.00);
            $table->enum('status', ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'])
                ->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('store_id');
            $table->unique('queue_id', 'uq_orders_queue_id');
            $table->index('nfc_card_id');
            $table->index('status');
            $table->index('completed_at');
            $table->index('deleted_at');
            $table->index(['store_id', 'status', 'created_at'], 'idx_store_order_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0.00);
            $table->string('category', 100)->nullable();
            $table->string('image_url', 255)->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('store_id');
            $table->index('category');
            $table->index('is_available');
            $table->index('is_active');
            $table->index('deleted_at');
            $table->index(['store_id', 'is_active', 'is_available'], 'idx_store_product_catalog');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

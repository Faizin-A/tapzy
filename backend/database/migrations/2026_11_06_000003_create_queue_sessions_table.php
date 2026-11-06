<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')
                ->constrained('stores')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('session_code', 50);
            $table->date('session_date')->index();
            $table->unsignedInteger('last_queue_number')->default(0);
            $table->enum('status', ['active', 'closed'])->default('active')->index();
            $table->unsignedBigInteger('active_store_id')
                ->nullable()
                ->virtualAs("IF(`status` = 'active', `store_id`, NULL)");
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('store_id');
            $table->unique('active_store_id', 'uq_store_single_active_session');
            $table->unique(['store_id', 'session_code'], 'uq_store_session_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_sessions');
    }
};

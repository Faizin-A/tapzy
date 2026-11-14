<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_id')
                ->constrained('queues')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->enum('previous_status', ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled'])
                ->nullable()
                ->default(null);
            $table->enum('new_status', ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled']);
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->foreign('changed_by_user_id', 'qsh_changed_by_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('queue_id');
            $table->index('changed_by_user_id');
            $table->index('new_status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_status_histories');
    }
};

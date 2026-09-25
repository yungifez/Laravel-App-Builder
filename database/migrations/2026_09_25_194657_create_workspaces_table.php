<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('driver');
            $table->string('driver_id')->nullable();
            $table->string('status');
            $table->string('image');
            $table->decimal('cpus', 5, 2);
            $table->unsignedInteger('memory_mb');
            $table->unsignedInteger('pids');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_activity_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};

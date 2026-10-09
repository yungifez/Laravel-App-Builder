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
        Schema::create('box_commands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('runner');
            $table->string('box');
            $table->string('type', 32);
            $table->text('payload')->nullable();
            $table->unsignedInteger('timeout_seconds');
            $table->string('status', 16)->default('queued');
            $table->json('result')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['runner', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('box_commands');
    }
};

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
        Schema::create('run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('run_events');
    }
};

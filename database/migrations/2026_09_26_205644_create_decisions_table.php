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
        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_request_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('driver');
            $table->string('model')->nullable();
            $table->string('choice');
            $table->json('probabilities');
            $table->float('confidence');
            $table->float('threshold');
            $table->boolean('acted')->default(false);
            $table->unsignedInteger('latency_ms');
            $table->timestamps();

            $table->unique(['feature_request_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};

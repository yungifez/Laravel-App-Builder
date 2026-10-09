<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One change made for the context experiment (§26.7): the same request
     * on the same commit, once per way of giving the agent its context.
     * Trials of one round are pairs.
     */
    public function up(): void
    {
        Schema::create('context_trials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->text('prompt');
            $table->string('mode');
            $table->foreignId('feature_request_id')->constrained()->cascadeOnDelete();
            $table->string('outcome');
            $table->json('measures');
            $table->timestamps();

            $table->index(['project_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_trials');
    }
};

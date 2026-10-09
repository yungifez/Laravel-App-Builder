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
        Schema::create('worker_heartbeats', function (Blueprint $table) {
            $table->string('worker')->primary();
            $table->string('connection');
            $table->string('queues');
            $table->timestamp('last_seen_at');
            $table->string('job')->nullable();
            $table->timestamp('job_started_at')->nullable();
            $table->unsignedInteger('job_timeout')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_heartbeats');
    }
};

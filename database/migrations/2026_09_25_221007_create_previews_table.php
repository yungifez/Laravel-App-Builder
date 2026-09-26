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
        Schema::create('previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('host')->unique();
            $table->string('status')->index();
            $table->unsignedInteger('port')->nullable();
            $table->string('upstream_url')->nullable();
            $table->string('grant_hash', 64)->nullable();
            $table->timestamp('grant_expires_at')->nullable();
            $table->string('session_hash', 64)->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('previews');
    }
};

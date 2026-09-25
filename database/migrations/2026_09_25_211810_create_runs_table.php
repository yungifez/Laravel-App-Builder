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
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('driver');
            $table->string('status')->index();
            $table->unsignedBigInteger('fencing_token')->default(0);
            $table->string('lease_owner')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('workspace_revision')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};

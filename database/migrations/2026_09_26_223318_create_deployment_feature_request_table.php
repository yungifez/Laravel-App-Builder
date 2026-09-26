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
        Schema::create('deployment_feature_request', function (Blueprint $table) {
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_request_id')->constrained()->cascadeOnDelete();
            $table->primary(['deployment_id', 'feature_request_id']);
            $table->index('feature_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deployment_feature_request');
    }
};

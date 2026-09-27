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
        Schema::create('test_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('verification_id')->nullable()->constrained()->nullOnDelete();
            $table->json('tests');
            $table->json('files');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_observations');
    }
};

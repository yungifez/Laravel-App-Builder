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
        Schema::create('developer_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The change the owner asks about; null asks about the whole app.
            $table->foreignId('feature_request_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            // The commit the developer's copy of the code is taken from, so
            // the review reads the same code however the app moves on.
            $table->string('revision')->nullable();
            // What the developer reads, written once when the owner asks.
            $table->longText('bundle');
            // One of our own developers answers (an operator).
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('guidance_kept_at')->nullable();
            // The owner took the question back before it was answered.
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('developer_reviews');
    }
};

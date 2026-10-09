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
        // The agent may say a finding is wrong or is what the owner asked
        // for, with its reason. Only the owner's answer lets it stand.
        Schema::create('finding_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('identity');
            $table->text('reason');
            $table->boolean('agreed')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['feature_request_id', 'identity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finding_proposals');
    }
};

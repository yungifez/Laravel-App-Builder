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
        // The idea the owner is working in; null is the main app.
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('experiment_id')->nullable()->constrained()->nullOnDelete();
        });

        // The idea a change or visual edit was made in; null is the main app.
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->foreignId('experiment_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('visual_edits', function (Blueprint $table) {
            $table->foreignId('experiment_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['visual_edits', 'feature_requests', 'projects'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('experiment_id');
            });
        }
    }
};

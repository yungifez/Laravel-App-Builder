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
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->string('base_revision', 64)->nullable()->after('generator');
            $table->string('commit_sha', 64)->nullable()->after('error');
            $table->timestamp('accepted_at')->nullable()->after('commit_sha');
            $table->string('revert_sha', 64)->nullable()->after('accepted_at');
            $table->timestamp('reverted_at')->nullable()->after('revert_sha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->dropColumn(['base_revision', 'commit_sha', 'accepted_at', 'revert_sha', 'reverted_at']);
        });
    }
};

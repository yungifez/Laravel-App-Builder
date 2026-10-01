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
        Schema::table('projects', function (Blueprint $table) {
            // The link that lets anyone holding it try the app: kept
            // encrypted to show the owner again, and found by its hash.
            $table->text('share_token')->nullable();
            $table->string('share_token_hash', 64)->nullable()->unique();
            $table->timestamp('share_expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['share_token_hash']);
            $table->dropColumn(['share_token', 'share_token_hash', 'share_expires_at']);
        });
    }
};

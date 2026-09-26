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
        Schema::table('visual_edits', function (Blueprint $table) {
            $table->string('revert_sha', 64)->nullable();
            $table->timestamp('reverted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visual_edits', function (Blueprint $table) {
            $table->dropColumn(['revert_sha', 'reverted_at']);
        });
    }
};

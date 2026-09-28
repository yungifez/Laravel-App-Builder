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
        Schema::table('workspace_commands', function (Blueprint $table) {
            // No runner took the command, or it never answered: it did not run to
            // an end, so its result says nothing about the code.
            $table->boolean('lost')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_commands', function (Blueprint $table) {
            $table->dropColumn('lost');
        });
    }
};

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
        Schema::table('health_checks', function (Blueprint $table) {
            $table->string('scope', 16)->default('full')->after('commit_sha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_checks', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};

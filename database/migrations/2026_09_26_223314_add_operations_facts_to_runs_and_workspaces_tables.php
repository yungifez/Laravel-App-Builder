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
        Schema::table('runs', function (Blueprint $table) {
            $table->string('config_version', 64)->nullable()->after('driver');
            $table->string('stop_reason')->nullable()->index()->after('error');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('cleanup_failed_at')->nullable();
            $table->text('cleanup_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropIndex(['stop_reason']);
            $table->dropColumn(['config_version', 'stop_reason']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['cleanup_failed_at', 'cleanup_error']);
        });
    }
};

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
        Schema::table('deployments', function (Blueprint $table) {
            // Who can put a failure right: the owner's publishing settings, or us.
            $table->string('error_cause')->nullable()->after('error');
            // What the host or Git said, for Details only.
            $table->text('error_details')->nullable()->after('error_cause');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['error_cause', 'error_details']);
        });
    }
};

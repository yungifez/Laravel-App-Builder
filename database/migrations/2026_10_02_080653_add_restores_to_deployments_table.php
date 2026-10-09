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
            // The earlier publish this one puts back online, for going back.
            $table->foreignId('restores_deployment_id')->nullable()->after('commit_sha')->constrained('deployments')->nullOnDelete();
            // The commit sent to the host, when it is not the one checked:
            // the same files on top of what the host has, so the push never
            // has to force.
            $table->string('release_sha')->nullable()->after('restores_deployment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restores_deployment_id');
            $table->dropColumn('release_sha');
        });
    }
};

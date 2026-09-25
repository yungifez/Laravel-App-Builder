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
            $table->json('plan')->nullable()->after('workspace_revision');
            $table->unsignedInteger('repairs')->default(0)->after('plan');
            $table->json('feedback')->nullable()->after('repairs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['plan', 'repairs', 'feedback']);
        });
    }
};

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
        Schema::table('users', function (Blueprint $table) {
            // A plan an operator gave without payment, for a tester or a
            // partner, until a date or for good.
            $table->string('granted_plan')->nullable();
            $table->timestamp('granted_plan_until')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['granted_plan', 'granted_plan_until']);
        });
    }
};

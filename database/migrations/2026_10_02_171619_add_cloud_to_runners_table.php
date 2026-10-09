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
        Schema::table('runners', function (Blueprint $table) {
            // The cloud that started the machine and its id there. Machines
            // added by hand have neither; only cloud machines are started
            // and deleted by the pool's scaler.
            $table->string('cloud', 32)->nullable()->after('name');
            $table->string('cloud_id')->nullable()->after('cloud');
            $table->unique(['cloud', 'cloud_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runners', function (Blueprint $table) {
            $table->dropUnique(['cloud', 'cloud_id']);
            $table->dropColumn(['cloud', 'cloud_id']);
        });
    }
};

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
            // The box image a cloud machine was started with, so machines on
            // an older one are replaced. Null for machines added by hand.
            $table->string('box_image')->nullable()->after('cloud_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runners', function (Blueprint $table) {
            $table->dropColumn('box_image');
        });
    }
};

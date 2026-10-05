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
        Schema::table('previews', function (Blueprint $table) {
            $table->boolean('no_longer_fits')->default(false)->after('error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('previews', function (Blueprint $table) {
            $table->dropColumn('no_longer_fits');
        });
    }
};

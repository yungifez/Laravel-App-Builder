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
            $table->boolean('watching')->default(false)->after('editable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('previews', function (Blueprint $table) {
            $table->dropColumn('watching');
        });
    }
};

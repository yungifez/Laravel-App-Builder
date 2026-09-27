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
        Schema::table('projects', function (Blueprint $table) {
            // An app started here from the template has never served anyone
            // until it is published; an imported one may already.
            $table->boolean('started_here')->default(false);
            // The owner's choice to keep old data and links working, or not;
            // null leaves it to whether anyone may use the app.
            $table->boolean('keep_old_working')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['started_here', 'keep_old_working']);
        });
    }
};

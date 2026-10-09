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
        Schema::table('model_gateway_grants', function (Blueprint $table) {
            // Our working rules for the run, added to each call on our side,
            // so the box holds only the task. Encrypted by the model.
            $table->text('instructions')->nullable()->after('provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('model_gateway_grants', function (Blueprint $table) {
            $table->dropColumn('instructions');
        });
    }
};

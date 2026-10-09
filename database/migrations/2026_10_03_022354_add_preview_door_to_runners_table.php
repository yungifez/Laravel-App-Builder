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
            // The runner's preview door: one HTTPS port that leads to its
            // previews when no private network joins it to the control
            // plane. The pin is its certificate's public key; the key opens
            // it. Both change each time the runner starts.
            $table->unsignedInteger('preview_door_port')->nullable()->after('service_host');
            $table->string('preview_door_pin')->nullable()->after('preview_door_port');
            $table->text('preview_door_key')->nullable()->after('preview_door_pin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runners', function (Blueprint $table) {
            $table->dropColumn(['preview_door_port', 'preview_door_pin', 'preview_door_key']);
        });
    }
};

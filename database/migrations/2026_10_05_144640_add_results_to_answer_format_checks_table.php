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
        Schema::table('answer_format_checks', function (Blueprint $table) {
            $table->string('agent')->unique();
            $table->string('role')->nullable();
            $table->boolean('accepted');
            $table->string('reason')->nullable();
            $table->json('service_error')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('answer_format_checks', function (Blueprint $table) {
            $table->dropUnique(['agent']);
            $table->dropColumn(['agent', 'role', 'accepted', 'reason', 'service_error', 'message', 'checked_at']);
        });
    }
};

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
            // The account whose monthly AI use the run spends.
            $table->foreignId('user_id')->nullable()->after('provider')->constrained()->nullOnDelete();
            // What was left of it when the grant opened; null has no limit.
            $table->decimal('allowance_usd', 12, 6)->nullable()->after('user_id');
            $table->decimal('cost_usd', 12, 6)->default(0)->after('output_tokens');
            $table->timestamp('refused_at')->nullable()->after('cost_usd');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('model_gateway_grants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['allowance_usd', 'cost_usd', 'refused_at']);
        });
    }
};

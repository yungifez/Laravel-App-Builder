<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each decision says whether a provider after the first had to answer,
     * and its share of what the call cost (architecture §26.9). Decisions
     * made before this were all answered by the first provider.
     */
    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->boolean('fallback')->default(false)->after('model');
            $table->decimal('cost_usd', 12, 6)->nullable()->after('latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropColumn(['fallback', 'cost_usd']);
        });
    }
};

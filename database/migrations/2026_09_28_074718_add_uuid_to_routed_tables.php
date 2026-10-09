<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables whose rows appear in links and requests.
     */
    private const TABLES = ['projects', 'feature_requests', 'experiments', 'previews', 'runs', 'visual_edits', 'verifications'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid()->nullable()->after('id');
            });

            DB::table($name)->update(['uuid' => DB::raw('gen_random_uuid()')]);

            Schema::table($name, function (Blueprint $table) {
                $table->uuid()->nullable(false)->unique()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('uuid');
            });
        }
    }
};

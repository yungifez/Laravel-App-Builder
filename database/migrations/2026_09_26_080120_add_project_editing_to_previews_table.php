<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: a preview belongs to a project and may run the
     * project as it is (with no feature request), for point-and-edit.
     */
    public function up(): void
    {
        Schema::table('previews', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('revision', 64)->nullable()->after('workspace_id');
            $table->boolean('editable')->default(false)->after('revision');
            $table->timestamp('rebuilt_at')->nullable()->after('ready_at');
        });

        DB::table('previews')->update([
            'project_id' => DB::raw('(select project_id from feature_requests where feature_requests.id = previews.feature_request_id)'),
        ]);

        Schema::table('previews', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
            $table->foreignId('feature_request_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('previews')->whereNull('feature_request_id')->delete();

        Schema::table('previews', function (Blueprint $table) {
            $table->foreignId('feature_request_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['revision', 'editable', 'rebuilt_at']);
        });
    }
};

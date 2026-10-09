<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('repository_created_at')->nullable()->after('source_path');
        });

        // Projects whose repository is on disk now had it made already.
        $root = rtrim((string) config('builder.projects.root'), DIRECTORY_SEPARATOR);

        DB::table('projects')->orderBy('id')->select(['id', 'created_at'])->each(function (object $project) use ($root) {
            if (File::isDirectory($root.DIRECTORY_SEPARATOR.$project->id.DIRECTORY_SEPARATOR.'.git')) {
                DB::table('projects')->where('id', $project->id)->update(['repository_created_at' => $project->created_at ?? now()]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('repository_created_at');
        });
    }
};

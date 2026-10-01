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
        // Where the change's own code starts on the branch its copy is
        // designed on: its patch is what changed after this commit.
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->string('design_base')->nullable();
        });

        // The change a design edit was made on, while it waits to be kept.
        Schema::table('visual_edits', function (Blueprint $table) {
            $table->foreignId('feature_request_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visual_edits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feature_request_id');
        });

        Schema::table('feature_requests', function (Blueprint $table) {
            $table->dropColumn('design_base');
        });
    }
};

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
        Schema::create('visual_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file');
            $table->unsignedInteger('line');
            $table->unsignedInteger('column');
            $table->string('tag');
            $table->string('device', 16);
            $table->json('changes');
            $table->text('classes_before');
            $table->text('classes_after');
            $table->string('base_revision', 64);
            $table->string('commit_sha', 64);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visual_edits');
    }
};

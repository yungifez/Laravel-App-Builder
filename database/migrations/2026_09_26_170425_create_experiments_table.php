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
        // An idea the owner tries on its own branch of the project's
        // repository, apart from the main app, until they use it or throw
        // it away.
        Schema::create('experiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('branch')->comment('The Git branch the idea lives on');
            $table->string('base_sha', 64)->comment('The main branch commit the idea started from');
            $table->string('status', 16)->default('open');
            $table->string('merge_sha', 64)->nullable()->comment('The main branch commit that brought the idea in');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('experiments');
    }
};

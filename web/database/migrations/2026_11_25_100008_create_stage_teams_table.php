<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('stage_group_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('seed')->nullable();
            $table->smallInteger('initial_points')->default(0);
            $table->timestampsTz();

            $table->unique(['stage_id', 'team_id']);
            $table->index('team_id');
            $table->index('stage_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_teams');
    }
};

<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->string('context', 16)->default('overall');
            $table->smallInteger('position');
            $table->smallInteger('played')->default(0);
            $table->smallInteger('wins')->default(0);
            $table->smallInteger('draws')->default(0);
            $table->smallInteger('losses')->default(0);
            $table->smallInteger('goals_for')->default(0);
            $table->smallInteger('goals_against')->default(0);
            $table->smallInteger('goal_diff')->default(0);
            $table->smallInteger('points')->default(0);
            $table->string('form', 8)->nullable();
            $table->string('zone_code', 32)->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestampTz('computed_at')->nullable();
            $table->timestampsTz();

            $table->index('team_id');
            $table->index('round_id');
            $table->index('stage_group_id');
        });

        PgSchema::index(
            'standings',
            'standings_slice_unique',
            'stage_id, stage_group_id, round_id, context, team_id',
            unique: true,
            nullsNotDistinct: true,
        );
        PgSchema::index('standings', 'standings_current_index', 'stage_id, context, position', where: 'is_current');
    }

    public function down(): void
    {
        Schema::dropIfExists('standings');
    }
};

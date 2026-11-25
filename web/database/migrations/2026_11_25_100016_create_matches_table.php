<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained();
            $table->foreignId('season_id')->constrained();
            $table->foreignId('stage_id')->constrained();
            $table->foreignId('round_id')->nullable()->constrained();
            $table->foreignId('stage_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tie_id')->nullable()->constrained();
            $table->foreignId('home_team_id')->constrained('teams');
            $table->foreignId('away_team_id')->constrained('teams');
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('referee_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('leg')->nullable();
            $table->timestampTz('kickoff_at');
            $table->string('status', 16)->default('scheduled');
            $table->string('period', 16)->nullable();
            $table->smallInteger('minute')->nullable();
            $table->smallInteger('home_goals')->nullable();
            $table->smallInteger('away_goals')->nullable();
            $table->smallInteger('home_goals_ht')->nullable();
            $table->smallInteger('away_goals_ht')->nullable();
            $table->smallInteger('home_goals_et')->nullable();
            $table->smallInteger('away_goals_et')->nullable();
            $table->smallInteger('home_pens')->nullable();
            $table->smallInteger('away_pens')->nullable();
            $table->boolean('is_neutral_venue')->default(false);
            $table->integer('attendance')->nullable();
            $table->string('weather_condition', 32)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->decimal('wind_speed_ms', 4, 1)->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['kickoff_at', 'id'], 'matches_kickoff_index');
            $table->index(['competition_id', 'kickoff_at', 'id'], 'matches_competition_kickoff_index');
            $table->index(['season_id', 'round_id', 'kickoff_at'], 'matches_season_round_kickoff_index');
            $table->index(['stage_id', 'round_id'], 'matches_stage_round_index');
            $table->index(['tie_id', 'leg'], 'matches_tie_leg_index');
        });

        PgSchema::check('matches', 'matches_distinct_teams', 'home_team_id <> away_team_id');
        PgSchema::check('matches', 'matches_leg_valid', 'leg IS NULL OR leg IN (1, 2)');

        PgSchema::index('matches', 'matches_home_team_finished_index', 'home_team_id, kickoff_at DESC', where: "status = 'finished'");
        PgSchema::index('matches', 'matches_away_team_finished_index', 'away_team_id, kickoff_at DESC', where: "status = 'finished'");
        PgSchema::index(
            'matches',
            'matches_h2h_finished_index',
            'LEAST(home_team_id, away_team_id), GREATEST(home_team_id, away_team_id), kickoff_at DESC',
            where: "status = 'finished'",
            pgOnly: true,
        );
        PgSchema::index('matches', 'matches_live_index', 'kickoff_at, id', where: "status = 'live'");
        PgSchema::index('matches', 'matches_scheduled_index', 'kickoff_at, id', where: "status = 'scheduled'");
        PgSchema::index('matches', 'matches_referee_kickoff_index', 'referee_id, kickoff_at DESC', where: 'referee_id IS NOT NULL');

        PgSchema::fillfactor('matches', 85);
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};

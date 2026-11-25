<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_season_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->constrained();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->smallInteger('appearances')->default(0);
            $table->smallInteger('starts')->default(0);
            $table->integer('minutes')->default(0);
            $table->smallInteger('goals')->default(0);
            $table->smallInteger('assists')->default(0);
            $table->smallInteger('penalties_scored')->default(0);
            $table->smallInteger('shots')->default(0);
            $table->smallInteger('shots_on_target')->default(0);
            $table->decimal('xg', 6, 2)->nullable();
            $table->decimal('xa', 6, 2)->nullable();
            $table->smallInteger('yellow_cards')->default(0);
            $table->smallInteger('red_cards')->default(0);
            $table->decimal('rating_sum', 8, 2)->default(0);
            $table->smallInteger('rated_matches')->default(0);
            $table->smallInteger('motm_count')->default(0);
            $table->smallInteger('clean_sheets')->default(0);
            $table->timestampTz('computed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['season_id', 'player_id', 'team_id']);
            $table->index(['season_id', 'team_id']);
            $table->index('player_id');
            $table->index('competition_id');
            $table->index('team_id');
        });

        PgSchema::index('player_season_stats', 'player_season_stats_goals_index', 'season_id, goals DESC');
        PgSchema::index('player_season_stats', 'player_season_stats_assists_index', 'season_id, assists DESC');
    }

    public function down(): void
    {
        Schema::dropIfExists('player_season_stats');
    }
};

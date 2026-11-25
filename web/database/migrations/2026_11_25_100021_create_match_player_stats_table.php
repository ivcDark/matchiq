<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_player_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('player_id')->constrained();
            $table->smallInteger('minutes')->nullable();
            $table->smallInteger('goals')->default(0);
            $table->smallInteger('assists')->default(0);
            $table->smallInteger('penalties_scored')->default(0);
            $table->smallInteger('shots')->nullable();
            $table->smallInteger('shots_on_target')->nullable();
            $table->decimal('xg', 5, 2)->nullable();
            $table->decimal('xa', 5, 2)->nullable();
            $table->smallInteger('yellow_cards')->default(0);
            $table->smallInteger('red_cards')->default(0);
            $table->decimal('rating', 4, 2)->nullable();
            $table->boolean('is_motm')->default(false);
            $table->jsonb('extra')->nullable();
            $table->timestampsTz();

            $table->unique(['match_id', 'player_id']);
            $table->index(['player_id', 'match_id']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_player_stats');
    }
};

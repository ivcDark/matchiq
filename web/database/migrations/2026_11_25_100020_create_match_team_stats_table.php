<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_team_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->string('period', 16)->default('full_time');
            $table->decimal('xg', 5, 2)->nullable();
            $table->decimal('xga', 5, 2)->nullable();
            $table->smallInteger('shots')->nullable();
            $table->smallInteger('shots_on_target')->nullable();
            $table->smallInteger('corners')->nullable();
            $table->smallInteger('fouls')->nullable();
            $table->smallInteger('offsides')->nullable();
            $table->smallInteger('yellow_cards')->nullable();
            $table->smallInteger('red_cards')->nullable();
            $table->decimal('possession_pct', 5, 2)->nullable();
            $table->smallInteger('passes')->nullable();
            $table->decimal('pass_accuracy_pct', 5, 2)->nullable();
            $table->smallInteger('aerials_won')->nullable();
            $table->smallInteger('saves')->nullable();
            $table->jsonb('extra')->nullable();
            $table->timestampsTz();

            $table->unique(['match_id', 'team_id', 'period']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_team_stats');
    }
};

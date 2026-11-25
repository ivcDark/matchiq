<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_scorelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_prediction_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('home_goals');
            $table->smallInteger('away_goals');
            $table->decimal('probability', 5, 4);
            $table->timestampsTz();

            $table->unique(['match_prediction_id', 'home_goals', 'away_goals'], 'prediction_scorelines_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_scorelines');
    }
};

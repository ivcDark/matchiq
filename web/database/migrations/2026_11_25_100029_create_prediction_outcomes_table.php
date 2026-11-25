<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_prediction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_type_id')->constrained();
            $table->string('outcome_key', 32);
            $table->decimal('line', 5, 2)->nullable();
            $table->decimal('probability', 5, 4);
            $table->boolean('is_hit')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['match_prediction_id', 'market_type_id'], 'prediction_outcomes_prediction_market_index');
            $table->index('market_type_id');
        });

        PgSchema::index(
            'prediction_outcomes',
            'prediction_outcomes_slot_unique',
            'match_prediction_id, market_type_id, line, outcome_key',
            unique: true,
            nullsNotDistinct: true,
        );
        PgSchema::check('prediction_outcomes', 'prediction_outcomes_probability_range', 'probability >= 0 AND probability <= 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_outcomes');
    }
};

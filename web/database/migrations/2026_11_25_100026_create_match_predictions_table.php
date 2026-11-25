<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained();
            $table->string('kind', 16)->default('pre_match');
            $table->boolean('is_current')->default(false);
            $table->decimal('expected_home_goals', 5, 3)->nullable();
            $table->decimal('expected_away_goals', 5, 3)->nullable();
            $table->decimal('p_home', 5, 4)->nullable();
            $table->decimal('p_draw', 5, 4)->nullable();
            $table->decimal('p_away', 5, 4)->nullable();
            $table->timestampTz('generated_at');
            $table->timestampTz('locked_at')->nullable();
            $table->timestampsTz();

            $table->index(['match_id', 'kind']);
            $table->index('model_version_id');
        });

        PgSchema::index('match_predictions', 'match_predictions_current_unique', 'match_id', unique: true, where: 'is_current');
    }

    public function down(): void
    {
        Schema::dropIfExists('match_predictions');
    }
};

<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16);
            $table->smallInteger('stage_order')->default(1);
            $table->smallInteger('cycles')->nullable();
            $table->smallInteger('matches_per_team')->nullable();
            $table->boolean('has_standings')->default(false);
            $table->smallInteger('default_legs')->nullable();
            $table->boolean('has_third_place_match')->default(false);
            $table->jsonb('rules')->default('{}');
            $table->timestampsTz();

            $table->unique(['season_id', 'stage_order']);
        });

        PgSchema::check('stages', 'stages_default_legs_valid', 'default_legs IS NULL OR default_legs IN (1, 2)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
    }
};

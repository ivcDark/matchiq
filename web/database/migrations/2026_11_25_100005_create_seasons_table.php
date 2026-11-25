<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('slug');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->smallInteger('rounds_total')->nullable();
            $table->timestampsTz();

            $table->unique(['competition_id', 'slug']);
        });

        PgSchema::index('seasons', 'seasons_current_per_competition_unique', 'competition_id', unique: true, where: 'is_current');
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};

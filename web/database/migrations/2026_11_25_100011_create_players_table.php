<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained();
            $table->string('slug')->unique();
            $table->string('name');
            $table->jsonb('name_i18n')->default('{}');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('position', 2)->nullable();
            $table->smallInteger('height_cm')->nullable();
            $table->smallInteger('weight_kg')->nullable();
            $table->string('preferred_foot', 8)->nullable();
            $table->string('photo_url')->nullable();
            $table->timestampsTz();

            $table->index('country_id');
        });

        PgSchema::i18nChecks('players', 'name_i18n');
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};

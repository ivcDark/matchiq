<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained();
            $table->string('name');
            $table->jsonb('name_i18n')->default('{}');
            $table->string('city')->nullable();
            $table->jsonb('city_i18n')->default('{}');
            $table->integer('capacity')->nullable();
            $table->string('surface', 32)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->timestampsTz();

            $table->index('country_id');
        });

        PgSchema::i18nChecks('venues', 'name_i18n', 'city_i18n');
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};

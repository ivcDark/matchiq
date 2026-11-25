<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained();
            $table->foreignId('home_venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->foreignId('parent_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('type', 16)->default('club');
            $table->string('name');
            $table->jsonb('name_i18n')->default('{}');
            $table->string('short_name')->nullable();
            $table->jsonb('short_name_i18n')->default('{}');
            $table->string('city')->nullable();
            $table->jsonb('city_i18n')->default('{}');
            $table->smallInteger('founded_year')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 16)->nullable();
            $table->string('secondary_color', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('country_id');
            $table->index('home_venue_id');
            $table->index('parent_team_id');
        });

        PgSchema::i18nChecks('teams', 'name_i18n', 'short_name_i18n', 'city_i18n');
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};

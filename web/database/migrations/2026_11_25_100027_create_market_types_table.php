<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('category', 16);
            $table->string('name');
            $table->jsonb('name_i18n')->default('{}');
            $table->string('period', 16)->default('full_time');
            $table->string('subject', 16)->default('match');
            $table->boolean('has_line')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_popular')->default(false);
            $table->timestampsTz();

            $table->index(['category', 'sort_order']);
        });

        PgSchema::i18nChecks('market_types', 'name_i18n');
    }

    public function down(): void
    {
        Schema::dropIfExists('market_types');
    }
};

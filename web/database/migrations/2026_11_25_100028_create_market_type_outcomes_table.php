<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_type_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_type_id')->constrained()->cascadeOnDelete();
            $table->string('outcome_key', 32);
            $table->string('label');
            $table->jsonb('label_i18n')->default('{}');
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['market_type_id', 'outcome_key']);
        });

        PgSchema::i18nChecks('market_type_outcomes', 'label_i18n');
    }

    public function down(): void
    {
        Schema::dropIfExists('market_type_outcomes');
    }
};

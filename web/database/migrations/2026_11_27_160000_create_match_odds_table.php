<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Коэффициенты букмекеров на матч из SStats /Games/list: последнее значение по рынку и исходу.
 * `source_market_id` — id рынка SStats (1 — 1X2, 5 — тотал и т.д.), с `market_types` пока не сопоставляется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_odds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_provider_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_market_id');
            $table->string('market_name')->nullable();
            $table->string('outcome', 64);
            $table->decimal('value', 8, 3);
            $table->timestampsTz();

            $table->unique(['match_id', 'source_provider_id', 'source_market_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_odds');
    }
};

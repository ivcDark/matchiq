<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained();
            $table->string('name');
            $table->jsonb('name_i18n')->default('{}');
            $table->string('city')->nullable();
            $table->timestampsTz();

            $table->index('country_id');
        });

        PgSchema::i18nChecks('referees', 'name_i18n');
    }

    public function down(): void
    {
        Schema::dropIfExists('referees');
    }
};

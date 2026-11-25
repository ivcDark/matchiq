<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('kind', 16)->default('country');
            $table->string('name');
            $table->jsonb('name_i18n')->default('{}');
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        PgSchema::i18nChecks('countries', 'name_i18n');
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};

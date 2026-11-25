<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained();
            $table->string('slug')->unique();
            $table->string('name');
            $table->jsonb('name_i18n')->default('{}');
            $table->string('short_name')->nullable();
            $table->jsonb('short_name_i18n')->default('{}');
            $table->string('type', 16);
            $table->string('scope', 16)->default('domestic');
            $table->string('participant_type', 16)->default('club');
            $table->smallInteger('tier')->nullable();
            $table->string('status', 16)->default('hidden');
            $table->smallInteger('sort_order')->default(0);
            $table->string('logo_url')->nullable();
            $table->timestampsTz();

            $table->index('country_id');
            $table->index(['status', 'sort_order']);
        });

        PgSchema::i18nChecks('competitions', 'name_i18n', 'short_name_i18n');
    }

    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};

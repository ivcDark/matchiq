<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_provider_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('priority')->default(100);
            $table->boolean('is_enabled')->default(true);
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();

            $table->unique(['competition_id', 'source_provider_id']);
            $table->index('source_provider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_sources');
    }
};

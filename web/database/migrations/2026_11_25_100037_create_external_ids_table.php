<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_provider_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->string('external_id');
            $table->string('external_url')->nullable();
            $table->string('matched_by', 16)->default('auto');
            $table->timestampTz('last_seen_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->unique(['source_provider_id', 'entity_type', 'external_id'], 'external_ids_source_entity_external_unique');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_ids');
    }
};

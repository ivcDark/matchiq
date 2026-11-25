<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->default('running');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->jsonb('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['source_provider_id', 'started_at']);
            $table->index('competition_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
    }
};

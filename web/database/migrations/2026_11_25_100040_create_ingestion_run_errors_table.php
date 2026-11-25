<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_run_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingestion_run_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 32)->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('phase', 16);
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('ingestion_run_id');
            $table->index(['entity_type', 'external_id']);
        });

        Schema::table('external_ids', function (Blueprint $table) {
            $table->foreignId('last_ingestion_run_id')
                ->nullable()
                ->after('last_seen_at')
                ->constrained('ingestion_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('external_ids', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_ingestion_run_id');
        });

        Schema::dropIfExists('ingestion_run_errors');
    }
};

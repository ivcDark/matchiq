<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_prediction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 10);
            $table->text('preview_text');
            $table->jsonb('facts')->nullable();
            $table->string('generator')->nullable();
            $table->timestampTz('generated_at');
            $table->timestampsTz();

            $table->index(['match_id', 'locale']);
            $table->index('match_prediction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_previews');
    }
};

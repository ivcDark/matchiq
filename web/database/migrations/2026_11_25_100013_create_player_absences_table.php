<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('reason')->nullable();
            $table->date('since')->nullable();
            $table->date('expected_return')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['team_id', 'is_active']);
            $table->index(['player_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_absences');
    }
};

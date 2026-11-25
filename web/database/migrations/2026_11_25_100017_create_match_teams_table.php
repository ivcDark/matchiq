<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained();
            $table->foreignId('coach_id')->nullable()->constrained()->nullOnDelete();
            $table->string('side', 8);
            $table->string('formation', 16)->nullable();
            $table->decimal('rating', 4, 2)->nullable();
            $table->timestampsTz();

            $table->unique(['match_id', 'side']);
            $table->unique(['match_id', 'team_id']);
            $table->index('team_id');
            $table->index('coach_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_teams');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standing_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('from_position');
            $table->smallInteger('to_position');
            $table->string('code', 32);
            $table->string('label');
            $table->string('color', 16)->nullable();
            $table->timestampsTz();

            $table->index(['stage_id', 'from_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standing_zones');
    }
};

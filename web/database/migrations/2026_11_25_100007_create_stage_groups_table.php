<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->smallInteger('group_order')->default(1);
            $table->timestampsTz();

            $table->unique(['stage_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_groups');
    }
};

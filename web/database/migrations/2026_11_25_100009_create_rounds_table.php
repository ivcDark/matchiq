<?php

use App\Support\Database\PgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('number');
            $table->string('type', 16);
            $table->string('code', 16)->nullable();
            $table->string('name')->nullable();
            $table->smallInteger('legs')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestampsTz();

            $table->unique(['stage_id', 'number']);
        });

        PgSchema::check('rounds', 'rounds_legs_valid', 'legs IS NULL OR legs IN (1, 2)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rounds');
    }
};

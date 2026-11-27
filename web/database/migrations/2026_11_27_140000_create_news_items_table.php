<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Промо-новости витрины. Сейчас блок на главной; полноценная редакция — отдельно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('tag', 32);
            $table->string('title');
            $table->timestampTz('published_at');
            $table->timestampsTz();

            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};

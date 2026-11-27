<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Раздел «Новости»: картинка, анонс для карточки и полный текст статьи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->string('image_url', 512)->nullable()->after('title');
            $table->text('excerpt')->nullable()->after('image_url');
            $table->text('body')->nullable()->after('excerpt');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'excerpt', 'body']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            // Remove or make user_id nullable
            // $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('article_title');
            $table->string('article_type');
            $table->string('article_short_desc', 500);
            $table->text('article_long_desc');
            $table->string('article_image')->nullable();
            $table->string('article_video_url')->nullable();
            $table->string('article_document')->nullable();
            $table->string('article_status', 20)->default('deactive');
            $table->string('slug')->unique();
            $table->json('social_media_platforms')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('social_media_published')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('blogs');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->string('social_media_post_id')->nullable()->after('social_media_published');
            $table->json('social_media_post_ids')->nullable()->after('social_media_post_id');
            $table->string('social_media_facebook_post_id')->nullable()->after('social_media_post_ids');
            $table->string('social_media_instagram_post_id')->nullable()->after('social_media_facebook_post_id');
            $table->string('social_media_twitter_post_id')->nullable()->after('social_media_instagram_post_id');
            $table->string('social_media_linkedin_post_id')->nullable()->after('social_media_twitter_post_id');
        });
    }

    public function down()
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropColumn([
                'social_media_post_id',
                'social_media_post_ids',
                'social_media_facebook_post_id',
                'social_media_instagram_post_id',
                'social_media_twitter_post_id',
                'social_media_linkedin_post_id'
            ]);
        });
    }
};
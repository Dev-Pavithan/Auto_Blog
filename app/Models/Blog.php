<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Blog extends Model
{
    use HasFactory;

    protected $fillable = [
        'article_title',
        'article_type',
        'article_short_desc',
        'article_long_desc',
        'article_image',
        'article_video_url',
        'article_document',
        'article_status',
        'slug',
        'social_media_platforms',
        'social_media_published',
        'social_media_post_id',
        'social_media_post_ids',
        'social_media_facebook_post_id',
        'social_media_instagram_post_id',
        'social_media_twitter_post_id',
        'social_media_linkedin_post_id',
        'published_at'
    ];

    protected $casts = [
        'social_media_platforms' => 'array',
        'social_media_post_ids' => 'array',
        'social_media_published' => 'boolean',
        'published_at' => 'datetime'
    ];

    // Add these accessors for convenience
    public function getFacebookPostIdAttribute()
    {
        return $this->social_media_facebook_post_id;
    }

    public function getInstagramPostIdAttribute()
    {
        return $this->social_media_instagram_post_id;
    }

    public function getTwitterPostIdAttribute()
    {
        return $this->social_media_twitter_post_id;
    }

    public function getLinkedinPostIdAttribute()
    {
        return $this->social_media_linkedin_post_id;
    }
}
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
        'published_at',
        'social_media_published'
    ];

    protected $casts = [
        'social_media_platforms' => 'array',
        'published_at' => 'datetime',
        'social_media_published' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
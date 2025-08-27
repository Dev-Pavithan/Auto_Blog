<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialMediaLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'blog_id',
        'platform',
        'platform_post_id',
        'success',
        'response',
        'error'
    ];

    protected $casts = [
        'response' => 'array',
        'success' => 'boolean'
    ];

    public function blog()
    {
        return $this->belongsTo(Blog::class);
    }
}
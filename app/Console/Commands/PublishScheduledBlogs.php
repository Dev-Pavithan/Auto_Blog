<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Jobs\PublishBlogToSocialMedia;
use Illuminate\Console\Command;

class PublishScheduledBlogs extends Command
{
    protected $signature = 'publish:scheduled-blogs';
    protected $description = 'Publish scheduled blogs';

    public function handle()
    {
        $blogs = Blog::where('status', 'scheduled')
            ->where('publish_time', '<=', now())
            ->get();
            
        foreach ($blogs as $blog) {
            PublishBlogToSocialMedia::dispatch($blog);
            $blog->update(['status' => 'published']);
        }
        
        $this->info("Published {$blogs->count()} scheduled blogs.");
    }
}
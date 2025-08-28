<?php

use App\Models\Blog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\SocialPostController;

Route::prefix('social-posts')->controller(SocialPostController::class)->group(function () {
    Route::get('/', 'index');                                   // list
    Route::get('{platform}/{postId}', 'show');                  // view
    Route::put('{platform}/{postId}', 'update');                // edit message
    Route::delete('{platform}/{postId}', 'destroy');            // delete
    Route::post('republish/{blog}', 'republish');               // republish a blog
});

Route::post('/social-posts/boost/{platform}/{postId}', [SocialPostController::class, 'boost']);
Route::post('/social-posts/share/{platform}/{postId}', [SocialPostController::class, 'share']);
Route::post('/social-posts/comment/{platform}/{postId}', [SocialPostController::class, 'comment']);

// Blog routes - all publicly accessible
Route::get('/blogs', [BlogController::class, 'index']);
Route::get('/blogs/all', [BlogController::class, 'getAllBlogs']);
Route::get('/blogs/{id}', [BlogController::class, 'show']);
Route::get('/blogs/status/{status}', [BlogController::class, 'byStatus']);
Route::get('/social-media-platforms', [BlogController::class, 'getSocialMediaPlatforms']);
Route::post('/blogs', [BlogController::class, 'store']);
Route::put('/blogs/{id}', [BlogController::class, 'update']);
Route::delete('/blogs/{id}', [BlogController::class, 'destroy']);
Route::post('/blogs/{id}/document', [BlogController::class, 'updateDocument']);
Route::get('/blogs/{id}/social-media-status', [BlogController::class, 'checkSocialMediaStatus']);
Route::post('/blogs/{id}/retry-social-media', [BlogController::class, 'retrySocialMediaPublishing']);

// Route to serve documents publicly
Route::get('/documents/{filename}', function ($filename) {
    $path = storage_path('app/public/documents/' . $filename);
    
    if (!file_exists($path)) {
        abort(404, 'Document not found');
    }
    
    // Set proper headers for file download
    return response()->file($path, [
        'Content-Type' => 'application/octet-stream',
        'Content-Disposition' => 'inline; filename="' . $filename . '"'
    ]);
})->where('filename', '.*');

// Test route to check document access
Route::get('/test-document/{filename}', function ($filename) {
    $path = storage_path('app/public/documents/' . $filename);
    
    if (!file_exists($path)) {
        return response()->json(['error' => 'Document not found', 'filename' => $filename], 404);
    }
    
    $fileSize = filesize($path);
    $fileType = mime_content_type($path);
    
    return response()->json([
        'filename' => $filename,
        'exists' => true,
        'size' => $fileSize,
        'type' => $fileType,
        'path' => $path,
        'download_url' => url('/api/documents/' . $filename)
    ]);
})->where('filename', '.*');

// Image routes - all publicly accessible
Route::apiResource('images', ImageController::class);

Route::get('/test-social', function (App\Services\SocialMediaService $socialService) {
    $result = $socialService->verifyCredentials('facebook');
    return response()->json($result);
});

// Add this to your routes/api.php
Route::get('/debug-facebook-token', function () {
    $accessToken = config('services.facebook.access_token');
    
    $response = Http::get("https://graph.facebook.com/debug_token", [
        'input_token' => $accessToken,
        'access_token' => $accessToken
    ]);

    if ($response->failed()) {
        return response()->json(['error' => 'Token debug failed', 'details' => $response->body()], 400);
    }

    $data = $response->json();
    return response()->json([
        'permissions' => $data['data']['scopes'] ?? [],
        'is_valid' => $data['data']['is_valid'] ?? false,
        'expires_at' => $data['data']['expires_at'] ?? null,
        'user_id' => $data['data']['user_id'] ?? null
    ]);
});


// Add this to routes/api.php
Route::get('/facebook-pages', function () {
    $accessToken = config('services.facebook.access_token');
    
    $response = Http::get("https://graph.facebook.com/me/accounts", [
        'access_token' => $accessToken
    ]);

    if ($response->failed()) {
        return response()->json(['error' => 'Failed to fetch pages', 'details' => $response->body()], 400);
    }

    $data = $response->json();
    return response()->json($data['data'] ?? []);
});

// Test route to manually trigger social media publishing
Route::post('/test-publish/{blogId}', function ($blogId, App\Services\SocialMediaService $socialService) {
    $blog = App\Models\Blog::find($blogId);
    
    if (!$blog) {
        return response()->json(['error' => 'Blog not found'], 404);
    }
    
    $platforms = json_decode($blog->social_media_platforms, true) ?: ['facebook'];
    
    Log::info("Manual test publishing for blog ID: {$blogId} to Facebook");
    
    // Generate URL that works for social media (avoid localhost)
    $socialMediaUrl = url('/blog/' . $blog->slug);
    if (str_contains($socialMediaUrl, 'localhost')) {
        // For development, use a placeholder or skip URL
        $socialMediaUrl = null;
    }
    
    // Generate proper document URL for social media
    $documentUrl = null;
    if ($blog->article_document && !filter_var($blog->article_document, FILTER_VALIDATE_URL)) {
        // Convert storage path to public URL
        $filename = basename($blog->article_document);
        $documentUrl = url('/api/documents/' . $filename);
    } else {
        $documentUrl = $blog->article_document;
    }
    
    $result = $socialService->publish('facebook', [
        'title' => $blog->article_title,
        'type' => $blog->article_type,
        'description' => $blog->article_short_desc,
        'content' => $blog->article_long_desc,
        'image' => $blog->article_image,
        'video_url' => $blog->article_video_url,
        'document' => $documentUrl,
        'url' => $socialMediaUrl,
        'slug' => $blog->slug
    ]);
    
    return response()->json([
        'success' => $result,
        'blog_id' => $blogId,
        'platforms' => $platforms,
        'result' => $result,
        'test_url' => url('/blog/' . $blog->slug)
    ]);
});
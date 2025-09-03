<?php

use App\Models\Blog;
use Illuminate\Support\Facades\Log;
use App\Services\SocialMediaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\SocialMediaPostController;

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


// Social Media Posts Management
Route::get('/social-posts', [SocialMediaPostController::class, 'index']);
Route::get('/social-posts/{platform}/{postId}', [SocialMediaPostController::class, 'show']);
Route::put('/social-posts/{platform}/{postId}', [SocialMediaPostController::class, 'update']);
Route::post('/social-posts/comment/{platform}/{postId}', [SocialMediaPostController::class, 'addComment']);
Route::delete('/social-posts/{platform}/{postId}', [SocialMediaPostController::class, 'destroy']);

// Image routes - all publicly accessible
Route::apiResource('images', ImageController::class);

// Debug and test routes
Route::get('/test-social', function (App\Services\SocialMediaService $socialService) {
    $result = $socialService->verifyCredentials('facebook');
    return response()->json($result);
});

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

Route::get('/debug/facebook', function (App\Services\SocialMediaService $socialService) {
    try {
        // Debug token
        $tokenDebug = $socialService->debugFacebookToken();
        
        // Get pages
        $pages = $socialService->getFacebookPages();
        
        // Verify credentials
        $credentials = $socialService->verifyCredentials('facebook');
        
        return response()->json([
            'token_debug' => $tokenDebug,
            'pages' => $pages,
            'credentials' => $credentials,
            'config' => [
                'page_id' => config('services.facebook.page_id'),
                'has_token' => !empty(config('services.facebook.access_token')) && 
                              config('services.facebook.access_token') !== 'your_facebook_access_token'
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});
Route::post('/test-facebook-integration', function () {
    try {
        $accessToken = config('services.facebook.access_token');
        $pageId = config('services.facebook.page_id');
        
        if (!$accessToken || !$pageId) {
            return response()->json([
                'success' => false,
                'error' => 'Missing Facebook configuration'
            ], 400);
        }

        // Test 1: Debug token
        $debugResponse = Http::get("https://graph.facebook.com/debug_token", [
            'input_token' => $accessToken,
            'access_token' => $accessToken
        ]);
        
        if ($debugResponse->failed()) {
            return response()->json([
                'success' => false,
                'error' => 'Token debug failed',
                'response' => $debugResponse->body()
            ], 400);
        }
        
        $debugData = $debugResponse->json()['data'];
        
        // Test 2: Get page access token
        $pageTokenResponse = Http::get("https://graph.facebook.com/v23.0/{$pageId}", [
            'fields' => 'access_token',
            'access_token' => $accessToken
        ]);
        
        if ($pageTokenResponse->failed()) {
            return response()->json([
                'success' => false,
                'error' => 'Page token failed',
                'response' => $pageTokenResponse->body()
            ], 400);
        }
        
        $pageTokenData = $pageTokenResponse->json();
        $pageAccessToken = $pageTokenData['access_token'] ?? null;
        
        return response()->json([
            'success' => true,
            'token_debug' => $debugData,
            'page_token_obtained' => !empty($pageAccessToken),
            'config' => [
                'page_id' => $pageId,
                'has_access_token' => !empty($accessToken)
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ], 500);
    }
});


Route::get('/facebook-pages', function () {
    $token = request('token') ?: config('services.facebook.access_token');
    if (!$token) {
        return response()->json(['error' => 'Token not configured'], 400);
    }

    // Detect token type
    $debug = Http::get('https://graph.facebook.com/v23.0/debug_token', [
        'input_token' => $token,
        'access_token' => $token,
    ]);

    if ($debug->failed()) {
        return response()->json(['error' => 'debug_token failed', 'details' => $debug->body()], 400);
    }

    $data = $debug->json()['data'] ?? [];
    $type = $data['type'] ?? 'UNKNOWN';

    if ($type === 'USER') {
        // USER token → list pages via /me/accounts
        $resp = Http::get('https://graph.facebook.com/v23.0/me/accounts', [
            'access_token' => $token,
            'fields' => 'id,name,access_token,tasks,category',
        ]);
        return $resp->successful()
            ? response()->json($resp->json()['data'] ?? [])
            : response()->json(['error' => 'Failed to fetch pages', 'details' => $resp->body()], 400);
    }

    if ($type === 'PAGE') {
        // PAGE token → return THIS page’s info (no /accounts edge exists)
        // profile_id is the page id for PAGE tokens
        $pageId = $data['profile_id'] ?? null;
        if (!$pageId) {
            return response()->json(['error' => 'Could not determine page id from PAGE token'], 400);
        }

        $pageInfo = Http::get("https://graph.facebook.com/v23.0/{$pageId}", [
            'access_token' => $token,
            'fields' => 'id,name,category',
        ]);

        if ($pageInfo->failed()) {
            return response()->json(['error' => 'Failed to read page info', 'details' => $pageInfo->body()], 400);
        }

        // Return in the same general shape as /me/accounts would
        $json = $pageInfo->json();
        $json['access_token'] = $token; // this PAGE token can be used to post
        $json['tasks'] = ['CREATE_CONTENT','MANAGE']; // not strictly accurate; for UI continuity
        return response()->json([$json]);
    }

    return response()->json(['error' => "Unsupported token type: {$type}"], 400);
});


Route::get('/debug/facebook-token-details', function () {
    $accessToken = request('token') ?: config('services.facebook.access_token');
    if (!$accessToken) return response()->json(['error' => 'Token not configured'], 400);

    $debug = Http::get('https://graph.facebook.com/debug_token', [
        'input_token' => $accessToken,
        'access_token' => $accessToken,
    ]);
    return $debug->successful()
        ? response()->json($debug->json())
        : response()->json(['error' => 'debug_token failed', 'body' => $debug->body()], 400);
});

Route::get('/facebook-pages', function () {
    $accessToken = request('token') ?: config('services.facebook.access_token');
    if (!$accessToken) return response()->json(['error' => 'Token not configured'], 400);

    $resp = Http::get('https://graph.facebook.com/v23.0/me/accounts', [
        'access_token' => $accessToken,
        'fields' => 'id,name,access_token,tasks',
    ]);
    return $resp->successful()
        ? response()->json($resp->json()['data'] ?? [])
        : response()->json(['error' => 'Failed to fetch pages', 'details' => $resp->body()], 400);
});


Route::post('/test-facebook-post', function () {
    try {
        $accessToken = config('services.facebook.access_token');
        $pageId = config('services.facebook.page_id');
        
        // Test the Facebook API call directly
        $response = Http::post("https://graph.facebook.com/{$pageId}/feed", [
            'message' => 'Test post from Laravel API - ' . now()->toDateTimeString(),
            'access_token' => $accessToken
        ]);
        
        if ($response->failed()) {
            $error = $response->json();
            return response()->json([
                'success' => false,
                'error' => $error,
                'details' => 'Failed to post to Facebook'
            ], 400);
        }
        
        $postData = $response->json();
        return response()->json([
            'success' => true,
            'post_id' => $postData['id'] ?? null,
            'message' => 'Post created successfully'
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'details' => 'Exception occurred'
        ], 500);
    }
});

Route::post('/debug-publish', function (App\Services\SocialMediaService $socialService) {
    $blog = App\Models\Blog::find(1); // Your test blog
    
    $result = $socialService->publish('facebook', [
        'title' => $blog->article_title,
        'description' => $blog->article_short_desc,
        'image' => $blog->article_image,
        'url' => url('/blog/' . $blog->slug)
    ]);
    
    return response()->json([
        'success' => (bool)$result,
        'result' => $result,
        'blog_data' => [
            'title' => $blog->article_title,
            'description' => $blog->article_short_desc,
            'image' => $blog->article_image,
            'url' => url('/blog/' . $blog->slug)
        ]
    ]);
});

Route::post('/test-facebook-post-direct', function () {
    try {
        $accessToken = config('services.facebook.access_token');
        
        if (!$accessToken) {
            return response()->json([
                'success' => false,
                'error' => 'Facebook access token not configured'
            ], 400);
        }

        // Debug token first
        $debugResponse = Http::get("https://graph.facebook.com/debug_token", [
            'input_token' => $accessToken,
            'access_token' => $accessToken
        ]);
        
        if ($debugResponse->failed()) {
            return response()->json([
                'success' => false,
                'error' => 'Token debug failed',
                'response' => $debugResponse->body()
            ], 400);
        }
        
        $debugData = $debugResponse->json()['data'];
        
        // For PAGE tokens, use profile_id. For USER tokens, use user_id.
        $pageId = ($debugData['type'] === 'PAGE') 
            ? $debugData['profile_id'] 
            : $debugData['user_id'];
        
        if (!$pageId) {
            return response()->json([
                'success' => false,
                'error' => 'Could not determine page ID',
                'debug_data' => $debugData
            ], 400);
        }

        // Test the Facebook API call directly
        $response = Http::post("https://graph.facebook.com/v23.0/{$pageId}/feed", [
            'message' => 'Direct test post from Laravel API - ' . now()->toDateTimeString(),
            'access_token' => $accessToken
        ]);
        
        if ($response->failed()) {
            $error = $response->json();
            return response()->json([
                'success' => false,
                'error' => $error,
                'debug_data' => $debugData,
                'details' => 'Failed to post to Facebook'
            ], 400);
        }
        
        $postData = $response->json();
        return response()->json([
            'success' => true,
            'post_id' => $postData['id'] ?? null,
            'debug_data' => $debugData,
            'message' => 'Post created successfully'
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'details' => 'Exception occurred'
        ], 500);
    }
});

Route::post('/test-blog-publish', function () {
    try {
        $blog = App\Models\Blog::find(2); // Use blog ID 2
        if (!$blog) {
            return response()->json([
                'success' => false,
                'error' => 'Blog not found'
            ], 404);
        }
        
        $socialService = app(App\Services\SocialMediaService::class);
        
        $result = $socialService->publish('facebook', [
            'title' => $blog->article_title,
            'description' => $blog->article_short_desc,
            'image' => $blog->article_image,
            'url' => url('/blog/' . $blog->slug)
        ]);
        
        return response()->json([
            'success' => (bool)$result,
            'result' => $result,
            'blog_data' => [
                'title' => $blog->article_title,
                'description' => $blog->article_short_desc,
                'image' => $blog->article_image,
                'url' => url('/blog/' . $blog->slug),
                'slug' => $blog->slug
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'details' => 'Exception occurred'
        ], 500);
    }
});


Route::post('/debug-content', function () {
    $blog = App\Models\Blog::find(2);
    
    return response()->json([
        'blog_content' => [
            'title' => $blog->article_title,
            'description' => $blog->article_short_desc,
            'image' => $blog->article_image,
            'url' => url('/blog/' . $blog->slug),
            'slug' => $blog->slug,
            'message' => $blog->article_title . "\n\n" . $blog->article_short_desc . "\n\nRead more: " . url('/blog/' . $blog->slug)
        ]
    ]);
});

Route::post('/test-simple-content', function () {
    try {
        $socialService = app(App\Services\SocialMediaService::class);
        
        $result = $socialService->publish('facebook', [
            'title' => 'Simple Test Title',
            'description' => 'Simple test description',
            'url' => 'https://example.com'
        ]);
        
        return response()->json([
            'success' => (bool)$result,
            'result' => $result
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ], 500);
    }
});
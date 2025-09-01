<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\ImageController;
use Illuminate\Support\Facades\Route;

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
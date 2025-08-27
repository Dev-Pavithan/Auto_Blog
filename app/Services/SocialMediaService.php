<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SocialMediaService
{
    public function publish(string $platform, array $content)
    {
        try {
            switch ($platform) {
                case 'facebook':
                    return $this->publishToFacebook($content);
                
                case 'instagram':
                    return $this->publishToInstagram($content);
                
                case 'linkedin':
                    return $this->publishToLinkedIn($content);
                
                default:
                    Log::warning("Unsupported platform: {$platform}");
                    return false;
            }
        } catch (\Exception $e) {
            Log::error("Failed to publish to {$platform}: " . $e->getMessage());
            return false;
        }
    }

    private function publishToFacebook(array $content)
{
    $configuredPageId = config('services.facebook.page_id');
    $configuredToken  = config('services.facebook.access_token');

    try {
        // Resolve (and cache) a Page access token; works with either a page token or a user token.
        $pageAccessToken = Cache::remember('facebook_page_token', 3600, function () use ($configuredToken, $configuredPageId) {
            // Fast path: if configured token is already a Page token for the target page, use it.
            try {
                if ($configuredToken && $configuredPageId) {
                    $pageCheck = Http::get("https://graph.facebook.com/{$configuredPageId}", [
                        'access_token' => $configuredToken,
                        'fields'       => 'id,name',
                    ]);
                    if ($pageCheck->successful() && ($pageCheck->json()['id'] ?? null) === $configuredPageId) {
                        Log::info('Configured token is a Page token for the configured page; using directly.');
                        return $configuredToken;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Direct page token check failed; falling back to /me/accounts: ' . $e->getMessage());
            }

            // User token flow
            Log::info('Fetching Facebook pages via /me/accounts to resolve a page access token');
            $pagesResponse = Http::get("https://graph.facebook.com/me/accounts", [
                'access_token' => $configuredToken,
                'fields'       => 'id,name,access_token,category,tasks',
            ]);

            if ($pagesResponse->failed()) {
                Log::error('Failed to fetch pages: ' . $pagesResponse->body());
                Log::error('Status: ' . $pagesResponse->status());
                return null;
            }

            $pages = $pagesResponse->json()['data'] ?? [];
            if (empty($pages)) {
                Log::error('No Facebook pages found for this user');
                return null;
            }

            if ($configuredPageId) {
                foreach ($pages as $page) {
                    if (($page['id'] ?? null) === $configuredPageId) {
                        Log::info("Resolved page token for configured page: {$page['name']} ({$page['id']})");
                        return $page['access_token'] ?? null;
                    }
                }
                Log::error("Configured page ID {$configuredPageId} not found in user's pages");
                Log::info('Available page IDs: ' . json_encode(array_column($pages, 'id')));
                return null;
            }

            // Fallback: first page if no page ID configured
            Log::info("No page ID configured; using first page: {$pages[0]['name']} ({$pages[0]['id']})");
            return $pages[0]['access_token'] ?? null;
        });

        if (!$pageAccessToken) {
            Log::error('No valid Facebook page access token available');
            return false;
        }

        $pageId = $configuredPageId;
        if (!$pageId) {
            Log::error('Facebook page ID not configured');
            return false;
        }

        Log::info("Posting to Facebook Page ID: {$pageId}");

        // Build the post message
        $message = $this->formatFacebookPost($content);

        // Build a production link (skip if localhost/dev)
        $linkUrl = null;
        if (!empty($content['url'])) {
            if (str_contains($content['url'], 'localhost')) {
                $linkUrl = "https://example.com/blog/" . ($content['slug'] ?? 'placeholder');
                Log::info("Using placeholder URL for development: {$linkUrl}");
            } else {
                $linkUrl = $content['url'];
                Log::info("Including URL in Facebook post: {$linkUrl}");
            }
        } else {
            Log::info("No URL provided for Facebook post");
        }

        // Normalize image URL (absolute, public)
        $imageUrl = null;
        if (!empty($content['image'])) {
            $img = $content['image'];
            if (Str::startsWith($img, ['/storage', 'storage'])) {
                $img = url($img);
            }
            $imageUrl = $img;
        }

        // ---- Preferred path: upload photo (unpublished) then attach to feed post ----
        if ($imageUrl && !str_contains($imageUrl, 'localhost')) {
            try {
                Log::info("Uploading photo to Facebook (unpublished) via form encoding");
                $photoUploadResponse = Http::asForm()->post("https://graph.facebook.com/{$pageId}/photos", [
                    'url'          => $imageUrl,
                    'published'    => 'false',      // MUST be string in form encoding
                    'access_token' => $pageAccessToken,
                ]);

                if ($photoUploadResponse->successful() && isset($photoUploadResponse['id'])) {
                    $mediaId = $photoUploadResponse['id'];
                    Log::info("Photo uploaded. media_fbid: {$mediaId}");

                    // Try object_attachment (simplest for single image)
                    $feedResponse = Http::asForm()->post("https://graph.facebook.com/{$pageId}/feed", [
                        'message'           => $message,
                        'object_attachment' => $mediaId,
                        'access_token'      => $pageAccessToken,
                    ]);

                    // If object_attachment fails, fall back to attached_media[0]
                    if ($feedResponse->failed()) {
                        Log::warning('object_attachment failed; trying attached_media[0]: ' . $feedResponse->body());
                        $feedResponse = Http::asForm()->post("https://graph.facebook.com/{$pageId}/feed", [
                            'message'           => $message,
                            'attached_media[0]' => json_encode(['media_fbid' => $mediaId]),
                            'access_token'      => $pageAccessToken,
                        ]);
                    }

                    if ($feedResponse->successful()) {
                        $created = $feedResponse->json();
                        Log::info('Facebook Page post (with media) created', $created);
                        return $created['id'] ?? true;
                    }

                    // Last resort: publish as a photo with caption (ensures image appears)
                    Log::error('Creating feed post with uploaded media failed: ' . $feedResponse->body() . ' — trying direct photo post with caption.');
                    $photoPost = Http::asForm()->post("https://graph.facebook.com/{$pageId}/photos", [
                        'url'          => $imageUrl,
                        'caption'      => $message,
                        'published'    => 'true',
                        'access_token' => $pageAccessToken,
                    ]);

                    if ($photoPost->successful()) {
                        $created = $photoPost->json();
                        Log::info('Facebook photo post created as fallback', $created);
                        // Photo responses often return 'post_id'
                        return $created['post_id'] ?? true;
                    }

                    Log::error('Fallback photo post failed: ' . $photoPost->body());
                } else {
                    Log::error('Photo upload failed: ' . $photoUploadResponse->body());
                }
            } catch (\Exception $e) {
                Log::error('Exception while uploading/attaching photo to Facebook: ' . $e->getMessage());
            }
        }

        // ---- Text/link fallback (no image or uploads failed) ----
        $postData = [
            'message'      => $message,
            'access_token' => $pageAccessToken,
        ];
        if (!empty($linkUrl)) {
            // Do not include 'link' when attaching media; safe here in text-only fallback
            $postData['link'] = $linkUrl;
        }

        Log::info("Posting text/link fallback to Facebook with form encoding");
        $response = Http::asForm()->post("https://graph.facebook.com/{$pageId}/feed", $postData);

        if ($response->failed()) {
            $error = $response->json();
            Log::error('Facebook Page API error (text/link fallback): ' . json_encode($error));
            Log::error('Response status: ' . $response->status());
            Log::error('Response body: ' . $response->body());
            if (isset($error['error']['code']) && (int) $error['error']['code'] === 190) {
                Cache::forget('facebook_page_token'); // force refresh next call
            }
            return false;
        }

        $created = $response->json();
        Log::info('Facebook Page post created successfully (text/link fallback): ', $created);
        return $created['id'] ?? true;

    } catch (\Exception $e) {
        Log::error('publishToFacebook exception: ' . $e->getMessage());
        return false;
    }
}


    private function getPageIdFromToken($accessToken)
    {
        $response = Http::get("https://graph.facebook.com/me", [
            'access_token' => $accessToken,
            'fields' => 'id'
        ]);

        if ($response->successful()) {
            return $response->json()['id'];
        }

        return null;
    }

    private function publishToInstagram(array $content)
    {
        // For Instagram Business accounts only
        $pageId = config('services.facebook.page_id');
        $userAccessToken = config('services.facebook.access_token');

        if (!$pageId) {
            Log::warning('Instagram publishing requires a Facebook Page ID');
            return false;
        }

        // First, check if the page is connected to an Instagram account
        $instagramAccountResponse = Http::get("https://graph.facebook.com/{$pageId}", [
            'access_token' => $userAccessToken,
            'fields' => 'instagram_business_account'
        ]);

        if ($instagramAccountResponse->failed()) {
            Log::error('Failed to fetch Instagram business account: ' . $instagramAccountResponse->body());
            return false;
        }

        $instagramData = $instagramAccountResponse->json();
        $instagramAccountId = $instagramData['instagram_business_account']['id'] ?? null;

        if (!$instagramAccountId) {
            Log::warning('No Instagram Business account connected to this Facebook Page');
            return false;
        }

        Log::info("Publishing to Instagram Business Account: {$instagramAccountId}");

        // Note: Instagram publishing requires additional permissions and steps
        // This is a simplified example
        return false;
    }

    private function publishToLinkedIn(array $content)
    {
        $accessToken = config('services.linkedin.access_token');
        
        if (!$accessToken || $accessToken === 'your_linkedin_access_token') {
            Log::warning('LinkedIn access token not configured');
            return false;
        }

        try {
            // Get user profile URN
            $profileResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
            ])->get('https://api.linkedin.com/v2/me');

            if ($profileResponse->failed()) {
                Log::error('LinkedIn profile fetch failed: ' . $profileResponse->body());
                return false;
            }

            $profileData = $profileResponse->json();
            $userUrn = $profileData['id'];

            // Create the post
            $postData = [
                'author' => "urn:li:person:{$userUrn}",
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => [
                            'text' => $content['title'] . "\n\n" . 
                                    ($content['description'] ?? '') . "\n\n" . 
                                    ($content['url'] ?? '')
                        ],
                        'shareMediaCategory' => 'NONE'
                    ]
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
                ]
            ];

            $postResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])->post("https://api.linkedin.com/v2/ugcPosts", $postData);

            if ($postResponse->failed()) {
                Log::error('LinkedIn post failed: ' . $postResponse->body());
                return false;
            }

            $responseData = $postResponse->json();
            Log::info('LinkedIn post created successfully: ', $responseData);
            
            return $responseData['id'] ?? true;

        } catch (\Exception $e) {
            Log::error('LinkedIn publishing failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify social media credentials
     */
    public function verifyCredentials(string $platform)
    {
        try {
            switch ($platform) {
                case 'facebook':
                    // Accept both user tokens and page tokens
                    $token = config('services.facebook.access_token');
                    $configuredPageId = config('services.facebook.page_id');
                    
                    // Request minimal fields to work for both user and page tokens
                    $response = Http::get("https://graph.facebook.com/me", [
                        'access_token' => $token,
                        'fields' => 'id,name'
                    ]);
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        
                        // Try to fetch pages if token is a user token; ignore failures for page tokens
                        try {
                        $pagesResponse = Http::get("https://graph.facebook.com/me/accounts", [
                                'access_token' => $token,
                                'fields' => 'id,name,access_token,tasks'
                        ]);
                        if ($pagesResponse->successful()) {
                            $data['pages'] = $pagesResponse->json()['data'] ?? [];
                            } else {
                                Log::info('Skipping /me/accounts pages fetch (likely a page token): ' . $pagesResponse->body());
                            }
                        } catch (\Exception $e) {
                            Log::info('Pages fetch skipped (page token likely).');
                        }
                        
                        // If token is a page token and matches configured page, accept
                        if ($configuredPageId && ($data['id'] ?? null) === $configuredPageId) {
                            $data['token_type'] = 'page_token';
                        }
                        
                        return $data;
                    }
                    
                    return false;
                    
                case 'linkedin':
                    $accessToken = config('services.linkedin.access_token');
                    if (!$accessToken || $accessToken === 'your_linkedin_access_token') {
                        return false;
                    }
                    
                    $response = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $accessToken,
                    ])->get('https://api.linkedin.com/v2/me');
                    
                    return $response->successful() ? $response->json() : false;
                    
                default:
                    return false;
            }
        } catch (\Exception $e) {
            Log::error("Credential verification failed for {$platform}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Debug Facebook token permissions and validity
     */
    public function debugFacebookToken()
    {
        $accessToken = config('services.facebook.access_token');
        
        $response = Http::get("https://graph.facebook.com/debug_token", [
            'input_token' => $accessToken,
            'access_token' => $accessToken
        ]);

        if ($response->successful()) {
            return $response->json()['data'] ?? [];
        }

        Log::error('Facebook token debug failed: ' . $response->body());
        return false;
    }

    /**
     * Get Facebook pages for the authenticated user
     */
    public function getFacebookPages()
    {
        $accessToken = config('services.facebook.access_token');
        
        $response = Http::get("https://graph.facebook.com/me/accounts", [
            'access_token' => $accessToken,
            'fields' => 'id,name,access_token,category,category_list,tasks'
        ]);

        if ($response->successful()) {
            return $response->json()['data'] ?? [];
        }

        Log::error('Failed to fetch Facebook pages: ' . $response->body());
        return [];
    }

    /**
     * Generate a production-ready URL for social media posts
     */
    private function generateProductionUrl($slug)
    {
        $productionUrl = config('services.facebook.app_url');
        
        // If we have a production URL configured, use it
        if ($productionUrl && !str_contains($productionUrl, 'localhost')) {
            return rtrim($productionUrl, '/') . '/blog/' . $slug;
        }
        
        // For development, return null to skip URL in Facebook posts
        return null;
    }

    /**
 * Format blog content for Facebook using only DB-provided fields.
 */
private function formatFacebookPost(array $content)
{
    $lines = [];

    // Title
    if (!empty($content['title'])) {
        $lines[] = $content['title'];
    }

    // Type (value only)
    if (!empty($content['type'])) {
        $lines[] = ucfirst($content['type']);
    }

    // Short description
    if (!empty($content['description'])) {
        $lines[] = $content['description'];
    }

    // Long content preview (first 300 chars, stripped of HTML)
    if (!empty($content['content'])) {
        $preview = Str::limit(trim(strip_tags($content['content'])), 300, '…');
        $lines[] = $preview;
    }

    // URL (omit localhost)
    if (!empty($content['url']) && !str_contains($content['url'], 'localhost')) {
        $lines[] = $content['url'];
    }

    // Join with a blank line between sections
    return implode("\n\n", array_filter($lines, fn ($v) => trim($v) !== ''));
}

    

}
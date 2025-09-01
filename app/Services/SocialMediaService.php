<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
        $userAccessToken = config('services.facebook.access_token');
        
        // Get page access token (cached for efficiency)
        $pageAccessToken = Cache::remember('facebook_page_token', 3600, function () use ($userAccessToken) {
            $pagesResponse = Http::get("https://graph.facebook.com/me/accounts", [
                'access_token' => $userAccessToken
            ]);

            if ($pagesResponse->failed()) {
                Log::error('Failed to fetch Facebook pages: ' . $pagesResponse->body());
                return null;
            }

            $pages = $pagesResponse->json()['data'] ?? [];
            
            if (empty($pages)) {
                Log::error('No Facebook pages found for this user');
                return null;
            }

            // Use the first page or find by ID if specified
            $pageId = config('services.facebook.page_id');
            if ($pageId) {
                foreach ($pages as $page) {
                    if ($page['id'] === $pageId) {
                        return $page['access_token'];
                    }
                }
            }

            return $pages[0]['access_token'];
        });

        if (!$pageAccessToken) {
            Log::error('No valid Facebook page access token available');
            return false;
        }

        $pageId = config('services.facebook.page_id') ?: $this->getPageIdFromToken($pageAccessToken);

        Log::info("Posting to Facebook Page ID: {$pageId}");

        // Prepare the post content
        $message = $content['title'] . "\n\n" . 
                  ($content['description'] ?? '') . "\n\n" . 
                  ($content['url'] ? "Read more: " . $content['url'] : '');

        // Post to the page
        $response = Http::post("https://graph.facebook.com/{$pageId}/feed", [
            'message' => $message,
            'link' => $content['url'] ?? null,
            'access_token' => $pageAccessToken
        ]);

        if ($response->failed()) {
            $error = $response->json();
            Log::error('Facebook Page API error: ' . json_encode($error));
            
            // If token is invalid, clear cache
            if (isset($error['error']['code']) && $error['error']['code'] === 190) {
                Cache::forget('facebook_page_token');
            }
            
            return false;
        }

        $postData = $response->json();
        Log::info('Facebook Page post created successfully: ', $postData);
        
        return $postData['id'] ?? true;
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
                    $response = Http::get("https://graph.facebook.com/me", [
                        'access_token' => config('services.facebook.access_token'),
                        'fields' => 'id,name,email'
                    ]);
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        
                        // Also get pages to verify page access
                        $pagesResponse = Http::get("https://graph.facebook.com/me/accounts", [
                            'access_token' => config('services.facebook.access_token')
                        ]);
                        
                        if ($pagesResponse->successful()) {
                            $data['pages'] = $pagesResponse->json()['data'] ?? [];
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
}
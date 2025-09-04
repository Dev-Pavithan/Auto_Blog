<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        Log::info('Starting Facebook publish with content:', $content);
        
        $accessToken = config('services.facebook.access_token');
        $pageId = config('services.facebook.page_id');

        if (!$accessToken || !$pageId) {
            Log::warning('Facebook access token or page ID not configured');
            return false;
        }

        // Debug the token to understand its type
        $debugResponse = Http::get('https://graph.facebook.com/debug_token', [
            'input_token' => $accessToken,
            'access_token' => $accessToken,
        ]);

        if ($debugResponse->failed()) {
            Log::error('Facebook token debug failed: ' . $debugResponse->body());
            return false;
        }

        $debugData = $debugResponse->json()['data'] ?? [];
        
        if (empty($debugData) || !($debugData['is_valid'] ?? false)) {
            Log::error('Facebook token is invalid');
            return false;
        }

        // Build the post message with full content
        $message = $this->buildFacebookMessage($content);

        Log::info("Facebook post content:", [
            'message_length' => strlen($message),
            'target_page_id' => $pageId
        ]);

        // Handle image upload to Facebook
        $imageAttachment = null;
        if (!empty($content['image'])) {
            $imageAttachment = $this->uploadImageToFacebook($content['image'], $pageId, $accessToken);
        }

        $payload = [
            'message' => mb_substr($message, 0, 5000),
            'access_token' => $accessToken,
        ];

        // Add image if uploaded successfully
        if ($imageAttachment) {
            $payload['attached_media'] = json_encode([['media_fbid' => $imageAttachment]]);
        }

        // Add link if provided and no image was uploaded
        if (!empty($content['url']) && !$imageAttachment) {
            $payload['link'] = $content['url'];
        }

        Log::info("Posting to Facebook page {$pageId}");

        try {
            $response = Http::post("https://graph.facebook.com/v23.0/{$pageId}/feed", $payload);

            if ($response->failed()) {
                $error = $response->json();
                Log::error('Facebook post failed: ' . json_encode($error));
                Log::error('Request payload: ' . json_encode($payload));
                return false;
            }

            $postData = $response->json();
            $postId = $postData['id'] ?? null;
            
            if ($postId) {
                Log::info("Facebook post created successfully: {$postId}");
                return $postId;
            }

            Log::info("Facebook post created successfully (no ID returned)");
            return true;

        } catch (\Exception $e) {
            Log::error('Exception during Facebook post: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Upload image to Facebook
     */
    private function uploadImageToFacebook($imageUrl, $pageId, $accessToken)
    {
        try {
            // Download the image
            $imageData = file_get_contents($imageUrl);
            $tempFile = tempnam(sys_get_temp_dir(), 'fb_image_');
            file_put_contents($tempFile, $imageData);

            // Upload to Facebook
            $response = Http::attach(
                'source', file_get_contents($tempFile), 'image.jpg'
            )->post("https://graph.facebook.com/v23.0/{$pageId}/photos", [
                'access_token' => $accessToken,
                'published' => false // Don't publish as separate post
            ]);

            // Clean up temp file
            unlink($tempFile);

            if ($response->successful()) {
                $data = $response->json();
                return $data['id'] ?? null;
            }

            Log::error('Facebook image upload failed: ' . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::error('Image upload failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build Facebook message with full content, video URL, and document link
     */
    private function buildFacebookMessage(array $content): string
    {
        $message = "";
        
        // Add title
        if (!empty($content['title'])) {
            $message .= $content['title'] . "\n\n";
        }
        
        // Add short description
        if (!empty($content['short_desc'])) {
            $message .= $content['short_desc'] . "\n\n";
        }
        
        // Add long description (full content)
        if (!empty($content['long_desc'])) {
            // Clean up the content by removing HTML tags and extra newlines
            $cleanContent = strip_tags($content['long_desc']);
            $cleanContent = preg_replace('/\s+/', ' ', $cleanContent);
            $cleanContent = trim($cleanContent);
            
            // Add the full content to the message
            $message .= $cleanContent . "\n\n";
        }
        
        // Add video URL if available
        if (!empty($content['video_url'])) {
            $message .= "Watch the video: " . $content['video_url'] . "\n\n";
        }
        
        // Add document download link if available
        if (!empty($content['document'])) {
            $message .= "Download document: " . $content['document'] . "\n\n";
        }

        return trim($message);
    }

private function publishToInstagram(array $content)
{
    $pageId = config('services.facebook.page_id');
    $accessToken = config('services.facebook.access_token');

    if (!$accessToken || !$pageId) {
        Log::warning('Instagram requires Facebook access token and page ID');
        return false;
    }

    try {
        // Get Instagram Business Account ID connected to the Facebook Page
        $instagramAccountResponse = Http::get("https://graph.facebook.com/v23.0/{$pageId}", [
            'access_token' => $accessToken,
            'fields' => 'instagram_business_account{id,name,username}'
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

        // Build Instagram caption
        $caption = $this->buildInstagramCaption($content);

        // Step 1: Create media container
        $mediaParams = [
            'access_token' => $accessToken,
            'caption' => $caption,
        ];

        // Add image if available - use a direct URL that Facebook can access
        if (!empty($content['image'])) {
            $mediaParams['image_url'] = $this->getAccessibleImageUrl($content['image']);
        } else {
            Log::error('Instagram post requires an image');
            return false;
        }

        $mediaResponse = Http::post("https://graph.facebook.com/v23.0/{$instagramAccountId}/media", $mediaParams);

        if ($mediaResponse->failed()) {
            Log::error('Instagram media creation failed: ' . $mediaResponse->body());
            Log::error('Media params: ' . json_encode($mediaParams));
            return false;
        }

        $mediaData = $mediaResponse->json();
        $mediaId = $mediaData['id'] ?? null;

        if (!$mediaId) {
            Log::error('No media ID returned from Instagram');
            return false;
        }

        // Wait a moment for media processing
        sleep(5);

        // Step 2: Publish the media
        $publishResponse = Http::post("https://graph.facebook.com/v23.0/{$instagramAccountId}/media_publish", [
            'access_token' => $accessToken,
            'creation_id' => $mediaId,
        ]);

        if ($publishResponse->successful()) {
            $publishData = $publishResponse->json();
            $postId = $publishData['id'] ?? null;
            Log::info("Instagram post created successfully: {$postId}");
            return $postId;
        }

        Log::error('Instagram publish failed: ' . $publishResponse->body());
        return false;

    } catch (\Exception $e) {
        Log::error('Exception during Instagram post: ' . $e->getMessage());
        return false;
    }
}

private function getAccessibleImageUrl($url)
{
    // Pinterest URLs might not be accessible by Facebook's servers
    // You might need to download and re-upload to a public server
    if (str_contains($url, 'pinterest.com') || str_contains($url, 'pinimg.com')) {
        // Consider downloading and storing locally, then providing a public URL
        Log::warning('Pinterest image URL detected - may not be accessible by Facebook');
    }
    return $url;
}

    /**
     * Build Instagram caption (shorter version)
     */
    private function buildInstagramCaption(array $content): string
    {
        $caption = "";
        
        // Add title
        if (!empty($content['title'])) {
            $caption .= $content['title'] . "\n\n";
        }
        
        // Add short description
        if (!empty($content['short_desc'])) {
            $caption .= $content['short_desc'] . "\n\n";
        }
        
        // Truncate long description for Instagram
        if (!empty($content['long_desc'])) {
            $cleanContent = strip_tags($content['long_desc']);
            $cleanContent = preg_replace('/\s+/', ' ', $cleanContent);
            $cleanContent = trim($cleanContent);
            
            // Instagram has a 2200 character limit for captions
            if (strlen($cleanContent) > 1000) {
                $cleanContent = substr($cleanContent, 0, 1000) . '...';
            }
            
            $caption .= $cleanContent . "\n\n";
        }

        // Add link in bio mention (since Instagram doesn't allow clickable links in captions)
        if (!empty($content['url'])) {
            $caption .= "🔗 Link in bio\n\n";
        }

        // Add relevant hashtags
        $caption .= "#blog #content #news";

        return trim($caption);
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
                        return $response->json();
                    }
                    
                    return false;
                    
                default:
                    return false;
            }
        } catch (\Exception $e) {
            Log::error("Credential verification failed for {$platform}: " . $e->getMessage());
            return false;
        }
    }
}
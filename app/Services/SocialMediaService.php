<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SocialMediaService
{
    /**
     * Publish content to social media platform
     */
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

    /**
     * Publish to Facebook
     */
    private function publishToFacebook(array $content)
    {
        $pageId = config('services.facebook.page_id');
        $pageAccessToken = $this->resolveFacebookPageAccessToken();

        if (!$pageAccessToken) {
            Log::error('No valid Facebook page access token available');
            return false;
        }

        if (!$pageId) {
            Log::error('Facebook page ID not configured');
            return false;
        }

        Log::info("Posting to Facebook Page ID: {$pageId}");

        // Build the post message - include ALL content
        $message = $this->formatFacebookPostComplete($content);

        // Build URL - always include if available
        $linkUrl = !empty($content['url']) ? $this->makeAbsoluteUrl($content['url']) : null;

        // Handle image
        $imageUrl = null;
        if (!empty($content['image'])) {
            $img = $content['image'];
            if (Str::startsWith($img, ['/storage', 'storage', '/api'])) {
                $img = $this->makeAbsoluteUrl($img);
            }
            $imageUrl = $img;
        }

        try {
            // Try to post with image if available
            if ($imageUrl) {
                $result = $this->publishFacebookPostWithImage($pageId, $pageAccessToken, $message, $imageUrl, $linkUrl);
                if ($result) return $result;
            }

            // Fallback to text post with link
            return $this->publishFacebookTextPost($pageId, $pageAccessToken, $message, $linkUrl);

        } catch (\Exception $e) {
            Log::error('Facebook publishing error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Publish Facebook post with image
     */
    private function publishFacebookPostWithImage($pageId, $accessToken, $message, $imageUrl, $linkUrl = null)
    {
        try {
            // Upload photo
            $photoResponse = Http::asForm()->post("https://graph.facebook.com/{$pageId}/photos", [
                'url' => $imageUrl,
                'published' => 'false',
                'access_token' => $accessToken,
            ]);

            if ($photoResponse->successful() && isset($photoResponse['id'])) {
                $mediaId = $photoResponse['id'];

                // Create post with attached media
                $postData = [
                    'message' => $message,
                    'attached_media' => json_encode([['media_fbid' => $mediaId]]),
                    'access_token' => $accessToken,
                ];

                // Always include link if available
                if ($linkUrl) {
                    $postData['link'] = $linkUrl;
                }

                $postResponse = Http::asForm()->post("https://graph.facebook.com/{$pageId}/feed", $postData);

                if ($postResponse->successful()) {
                    return $postResponse->json()['id'];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Facebook image post failed: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Publish Facebook text post
     */
    private function publishFacebookTextPost($pageId, $accessToken, $message, $linkUrl = null)
    {
        $postData = [
            'message' => $message, // contains real newlines now
            'access_token' => $accessToken,
        ];

        // Always include link if available
        if ($linkUrl) {
            $postData['link'] = $linkUrl;
        }

        $response = Http::asForm()->post("https://graph.facebook.com/{$pageId}/feed", $postData);

        if ($response->successful()) {
            return $response->json()['id'];
        }

        Log::error('Facebook text post failed: ' . $response->body());
        return false;
    }

    /**
     * Format Facebook post content - COMPLETE VERSION with all information
     * Also converts any literal "\n" in incoming text to real line breaks.
     */
    private function formatFacebookPostComplete(array $content)
    {
        $lines = [];

        // Title
        if (!empty($content['title'])) {
            $lines[] = $this->normalizeText($content['title']);
        }

        // Type
        if (!empty($content['type'])) {
            $lines[] = $this->normalizeText(ucfirst($content['type']));
        }

        // Short description
        if (!empty($content['description'])) {
            $lines[] = $this->normalizeText($content['description']);
        }

        // Long content - include as much as possible
        if (!empty($content['content'])) {
            $cleanedContent = strip_tags((string) $content['content']);
            $cleanedContent = html_entity_decode($cleanedContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cleanedContent = $this->normalizeText($cleanedContent);

            // Include more content (increased character limit)
            $contentExtract = $this->extractMeaningfulContent($cleanedContent, 5000);
            if ($contentExtract) {
                $lines[] = $contentExtract;
            }
        }

        // Video URL (as-is)
        if (!empty($content['video_url'])) {
            $lines[] = "Watch video: " . $this->formatUrlForDisplay($content['video_url']);
        }

        // Document URL on its own line (absolute)
        if (!empty($content['document'])) {
            $docUrl = $this->makeAbsoluteUrl($content['document']);
            $lines[] = "Download document: " . $this->formatUrlForDisplay($docUrl);
        }

        // Main article URL
        if (!empty($content['url'])) {
            $lines[] = "Read full article: " . $this->formatUrlForDisplay($this->makeAbsoluteUrl($content['url']));
        }

        // Join with real blank lines between sections
        $finalMessage = implode("\n\n", array_filter($lines, fn($v) => $v !== '' && $v !== null));

        // Keep within Facebook's hard limit
        if (strlen($finalMessage) > 63000) {
            $finalMessage = substr($finalMessage, 0, 62997) . '...';
        }

        return $finalMessage;
    }

    /**
     * Convert literal escapes to real characters and tidy whitespace/newlines
     */
    private function normalizeText(string $text): string
    {
        // Convert literal sequences \r\n, \n, \r, \t to real characters
        $text = str_replace(
            ['\\r\\n', '\\n\\r', '\\n', '\\r', '\\t'],
            ["\n", "\n", "\n", "\n", "    "],
            $text
        );

        // Normalize any CR to LF
        $text = preg_replace("/\r\n|\r/", "\n", $text);

        // Trim spaces on each line
        $text = preg_replace("/[ \t]+/", ' ', $text);

        // Collapse 3+ newlines to exactly 2 (paragraph spacing)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // Trim overall
        return trim($text);
    }

    /**
     * Make relative app URLs absolute for Facebook (images/docs)
     */
    private function makeAbsoluteUrl(string $url): string
    {
        // If it's already absolute, return as-is
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        // Use Laravel helper to build absolute URL
        return url($url);
    }

    /**
     * Format URL for clean display in Facebook posts
     */
    private function formatUrlForDisplay($url)
    {
        // If it's YouTube, return as is
        if (str_contains($url, 'youtu.be') || str_contains($url, 'youtube.com')) {
            return $url;
        }

        // For API document links, just show the URL (Facebook will make it clickable)
        if (str_contains($url, '/api/documents/')) {
            return $url;
        }

        return $url;
    }

    /**
     * Extract meaningful content while respecting paragraph boundaries
     */
    private function extractMeaningfulContent($content, $maxLength = 2000)
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        $truncated = substr($content, 0, $maxLength);

        // Find good breakpoints
        $lastParagraph   = strrpos($truncated, "\n\n");
        $lastPeriod      = strrpos($truncated, '. ');
        $lastQuestion    = strrpos($truncated, '? ');
        $lastExclamation = strrpos($truncated, '! ');

        $candidates = array_filter([$lastParagraph, $lastPeriod, $lastQuestion, $lastExclamation], fn($v) => $v !== false);

        if (!empty($candidates)) {
            $lastBreak = max($candidates);
            // If we broke on sentence punctuation, include the space after it
            if (in_array($lastBreak, [$lastPeriod, $lastQuestion, $lastExclamation], true)) {
                $lastBreak += 1;
            }
            return substr($content, 0, $lastBreak);
        }

        return substr($content, 0, $maxLength) . '...';
    }

    /**
     * Resolve Facebook page access token
     */
    private function resolveFacebookPageAccessToken()
    {
        return Cache::remember('facebook_page_token', 3600, function () {
            $configuredToken = config('services.facebook.access_token');
            $configuredPageId = config('services.facebook.page_id');

            $response = Http::get("https://graph.facebook.com/me/accounts", [
                'access_token' => $configuredToken,
                'fields' => 'id,name,access_token'
            ]);

            if ($response->successful()) {
                $pages = $response->json()['data'] ?? [];

                foreach ($pages as $page) {
                    if ($configuredPageId && $page['id'] === $configuredPageId) {
                        return $page['access_token'];
                    }
                }

                if (!empty($pages)) {
                    return $pages[0]['access_token']; // FIX: correct indexing
                }
            }

            Log::error('Failed to get Facebook page access token');
            return null;
        });
    }

    /**
     * List posts on a platform
     */
    public function listPosts(string $platform, int $limit = 25, ?string $after = null)
    {
        if ($platform !== 'facebook') {
            Log::warning("listPosts not implemented for {$platform}");
            return false;
        }

        $pageId = config('services.facebook.page_id');
        $token  = $this->resolveFacebookPageAccessToken();

        if (!$pageId || !$token) {
            return false;
        }

        $params = [
            'access_token' => $token,
            'fields' => 'id,permalink_url,created_time,message,full_picture,status_type',
            'limit' => $limit,
        ];

        if ($after) {
            $params['after'] = $after;
        }

        $response = Http::get("https://graph.facebook.com/{$pageId}/published_posts", $params);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Facebook listPosts failed: ' . $response->body());
        return false;
    }

    /**
     * Get a single post
     */
    public function getPost(string $platform, string $postId)
    {
        if ($platform !== 'facebook') {
            Log::warning("getPost not implemented for {$platform}");
            return false;
        }

        $token = $this->resolveFacebookPageAccessToken();
        if (!$token) {
            return false;
        }

        $response = Http::get("https://graph.facebook.com/{$postId}", [
            'access_token' => $token,
            'fields' => 'id,permalink_url,created_time,message,full_picture'
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Facebook getPost failed: ' . $response->body());
        return false;
    }

    /**
     * Update post message
     */
    public function updatePostMessage(string $platform, string $postId, string $message)
    {
        if ($platform !== 'facebook') {
            Log::warning("updatePostMessage not implemented for {$platform}");
            return false;
        }

        $token = $this->resolveFacebookPageAccessToken();
        if (!$token) {
            return false;
        }

        $message = $this->normalizeText($message);

        $response = Http::asForm()->post("https://graph.facebook.com/{$postId}", [
            'access_token' => $token,
            'message' => $message
        ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('Facebook updatePostMessage failed: ' . $response->body());
        return false;
    }

    /**
     * Delete a post
     */
    public function deletePost(string $platform, string $postId)
    {
        if ($platform !== 'facebook') {
            Log::warning("deletePost not implemented for {$platform}");
            return false;
        }

        $token = $this->resolveFacebookPageAccessToken();
        if (!$token) {
            return false;
        }

        $response = Http::delete("https://graph.facebook.com/{$postId}", [
            'access_token' => $token
        ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('Facebook deletePost failed: ' . $response->body()); // FIX
        return false;
    }

    /**
     * Boost a post
     */
    public function boostPost(string $platform, string $postId)
    {
        if ($platform !== 'facebook') {
            Log::warning("boostPost not implemented for {$platform}");
            return false;
        }

        $token = $this->resolveFacebookPageAccessToken();
        if (!$token) {
            return false;
        }

        $response = Http::post("https://graph.facebook.com/{$postId}/boosted_posts", [
            'access_token' => $token,
            'boost_method' => 'AUTOMATIC'
        ]);

        return $response->successful();
    }

    /**
     * Comment on a post
     */
    public function commentOnPost(string $platform, string $postId, string $comment)
    {
        if ($platform !== 'facebook') {
            Log::warning("commentOnPost not implemented for {$platform}");
            return false;
        }

        $token = $this->resolveFacebookPageAccessToken();
        if (!$token) {
            return false;
        }

        $comment = $this->normalizeText($comment);

        $response = Http::post("https://graph.facebook.com/{$postId}/comments", [
            'access_token' => $token,
            'message' => $comment
        ]);

        return $response->successful();
    }

    /**
     * Share a post to another platform
     */
    public function sharePost(string $sourcePlatform, string $postId, string $targetPlatform, string $message = null)
    {
        // Get the original post
        $post = $this->getPost($sourcePlatform, $postId);
        if (!$post) {
            return false;
        }

        // Prepare content for the target platform
        $content = [
            'message' => $message ?? ($post['message'] ?? ''),
            'title' => 'Shared Post',
        ];

        // Publish to target platform
        return $this->publish($targetPlatform, $content) !== false;
    }

    /**
     * Verify credentials
     */
    public function verifyCredentials(string $platform)
    {
        if ($platform !== 'facebook') {
            return false;
        }

        $token = config('services.facebook.access_token');

        $response = Http::get("https://graph.facebook.com/me", [
            'access_token' => $token,
            'fields' => 'id,name'
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        return false;
    }

    // Instagram and LinkedIn stubs
    private function publishToInstagram(array $content) { return false; }
    private function publishToLinkedIn(array $content) { return false; }
}

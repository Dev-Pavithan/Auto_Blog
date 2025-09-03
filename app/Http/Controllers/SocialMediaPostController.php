<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SocialMediaPostController extends Controller
{
    protected $facebookConfig;
    protected $instagramConfig;
    protected $linkedinConfig;

    public function __construct()
    {
        $this->facebookConfig = [
            'access_token' => config('services.facebook.access_token'),
            'page_id' => config('services.facebook.page_id')
        ];

        $this->instagramConfig = [
            'access_token' => config('services.instagram.access_token'),
            'account_id' => config('services.instagram.account_id')
        ];

        $this->linkedinConfig = [
            'access_token' => config('services.linkedin.access_token')
        ];
    }

    /**
     * Get social media posts with filtering
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'platform' => 'required|in:facebook,instagram,linkedin',
            'limit' => 'nullable|integer|min:1|max:100',
            'since' => 'nullable|date',
            'until' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $platform = $request->platform;
        $limit = $request->limit ?? 10;

        try {
            switch ($platform) {
                case 'facebook':
                    $posts = $this->getFacebookPosts($limit, $request->since, $request->until);
                    break;
                
                case 'instagram':
                    $posts = $this->getInstagramPosts($limit, $request->since, $request->until);
                    break;
                
                case 'linkedin':
                    $posts = $this->getLinkedInPosts($limit, $request->since, $request->until);
                    break;
                
                default:
                    return response()->json(['error' => 'Unsupported platform'], 400);
            }

            return response()->json([
                'platform' => $platform,
                'posts' => $posts,
                'total' => count($posts)
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to fetch {$platform} posts: " . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch posts',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific social media post
     */
    public function show($platform, $postId)
    {
        try {
            switch ($platform) {
                case 'facebook':
                    $post = $this->getFacebookPost($postId);
                    break;
                
                case 'instagram':
                    $post = $this->getInstagramPost($postId);
                    break;
                
                case 'linkedin':
                    $post = $this->getLinkedInPost($postId);
                    break;
                
                default:
                    return response()->json(['error' => 'Unsupported platform'], 400);
            }

            return response()->json([
                'platform' => $platform,
                'post' => $post
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to fetch {$platform} post {$postId}: " . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch post',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update social media post
     */
    public function update(Request $request, $platform, $postId)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:5000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            switch ($platform) {
                case 'facebook':
                    $result = $this->updateFacebookPost($postId, $request->message);
                    break;
                
                case 'instagram':
                    $result = $this->updateInstagramPost($postId, $request->message);
                    break;
                
                case 'linkedin':
                    $result = $this->updateLinkedInPost($postId, $request->message);
                    break;
                
                default:
                    return response()->json(['error' => 'Unsupported platform'], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Post updated successfully',
                'platform' => $platform,
                'post_id' => $postId,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to update {$platform} post {$postId}: " . $e->getMessage());
            return response()->json([
                'error' => 'Failed to update post',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add comment to social media post
     */
    public function addComment(Request $request, $platform, $postId)
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            switch ($platform) {
                case 'facebook':
                    $result = $this->addFacebookComment($postId, $request->comment);
                    break;
                
                case 'instagram':
                    $result = $this->addInstagramComment($postId, $request->comment);
                    break;
                
                case 'linkedin':
                    $result = $this->addLinkedInComment($postId, $request->comment);
                    break;
                
                default:
                    return response()->json(['error' => 'Unsupported platform'], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Comment added successfully',
                'platform' => $platform,
                'post_id' => $postId,
                'comment_id' => $result['id'] ?? null,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to add comment to {$platform} post {$postId}: " . $e->getMessage());
            return response()->json([
                'error' => 'Failed to add comment',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete social media post
     */
    public function destroy($platform, $postId)
    {
        try {
            switch ($platform) {
                case 'facebook':
                    $result = $this->deleteFacebookPost($postId);
                    break;
                
                case 'instagram':
                    $result = $this->deleteInstagramPost($postId);
                    break;
                
                case 'linkedin':
                    $result = $this->deleteLinkedInPost($postId);
                    break;
                
                default:
                    return response()->json(['error' => 'Unsupported platform'], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Post deleted successfully',
                'platform' => $platform,
                'post_id' => $postId,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to delete {$platform} post {$postId}: " . $e->getMessage());
            return response()->json([
                'error' => 'Failed to delete post',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Facebook Methods
    private function getFacebookPosts($limit, $since = null, $until = null)
    {
        $params = [
            'access_token' => $this->facebookConfig['access_token'],
            'fields' => 'id,message,created_time,updated_time,likes.limit(1).summary(true),comments.limit(1).summary(true),shares,permalink_url',
            'limit' => $limit
        ];

        if ($since) $params['since'] = $since;
        if ($until) $params['until'] = $until;

        $response = Http::get("https://graph.facebook.com/v23.0/{$this->facebookConfig['page_id']}/posts", $params);
        
        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json()['data'] ?? [];
    }

    private function getFacebookPost($postId)
    {
        $response = Http::get("https://graph.facebook.com/v23.0/{$postId}", [
            'access_token' => $this->facebookConfig['access_token'],
            'fields' => 'id,message,created_time,updated_time,likes.summary(true),comments.summary(true),shares,permalink_url'
        ]);
        
        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json();
    }

    private function updateFacebookPost($postId, $message)
    {
        $response = Http::post("https://graph.facebook.com/v23.0/{$postId}", [
            'access_token' => $this->facebookConfig['access_token'],
            'message' => $message
        ]);
        
        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json();
    }

    private function addFacebookComment($postId, $comment)
    {
        $response = Http::post("https://graph.facebook.com/v23.0/{$postId}/comments", [
            'access_token' => $this->facebookConfig['access_token'],
            'message' => $comment
        ]);
        
        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json();
    }

    private function deleteFacebookPost($postId)
    {
        $response = Http::delete("https://graph.facebook.com/v23.0/{$postId}", [
            'access_token' => $this->facebookConfig['access_token']
        ]);
        
        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json();
    }

    // Instagram Methods (Placeholder - requires Business Account)
    private function getInstagramPosts($limit, $since = null, $until = null)
    {
        // Instagram API requires Business Account and additional setup
        return ['message' => 'Instagram API integration requires Business Account setup'];
    }

    private function getInstagramPost($postId)
    {
        return ['message' => 'Instagram API integration requires Business Account setup'];
    }

    private function updateInstagramPost($postId, $message)
    {
        return ['message' => 'Instagram posts cannot be updated via API'];
    }

    private function addInstagramComment($postId, $comment)
    {
        return ['message' => 'Instagram API integration requires Business Account setup'];
    }

    private function deleteInstagramPost($postId)
    {
        return ['message' => 'Instagram API integration requires Business Account setup'];
    }

    // LinkedIn Methods (Placeholder - requires additional setup)
    private function getLinkedInPosts($limit, $since = null, $until = null)
    {
        // LinkedIn API requires organization access and additional setup
        return ['message' => 'LinkedIn API integration requires Organization access setup'];
    }

    private function getLinkedInPost($postId)
    {
        return ['message' => 'LinkedIn API integration requires Organization access setup'];
    }

    private function updateLinkedInPost($postId, $message)
    {
        return ['message' => 'LinkedIn posts cannot be updated via API'];
    }

    private function addLinkedInComment($postId, $comment)
    {
        return ['message' => 'LinkedIn API integration requires Organization access setup'];
    }

    private function deleteLinkedInPost($postId)
    {
        return ['message' => 'LinkedIn API integration requires Organization access setup'];
    }
}
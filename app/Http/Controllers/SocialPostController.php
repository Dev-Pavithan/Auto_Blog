<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Services\SocialMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SocialPostController extends Controller
{
    /**
     * List recent posts on a platform (cursor paginated).
     * GET /social-posts?platform=facebook&limit=25&after=CURSOR
     */
    public function index(Request $request, SocialMediaService $social)
    {
        $validator = Validator::make($request->all(), [
            'platform' => 'sometimes|string|in:facebook,instagram,twitter,linkedin',
            'limit' => 'sometimes|integer|min:1|max:100',
            'after' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $platform = $request->input('platform', 'facebook');
        $limit = (int) $request->input('limit', 25);
        $after = $request->input('after');

        $result = $social->listPosts($platform, $limit, $after);
        
        if ($result === false) {
            return response()->json(['message' => 'Failed to fetch posts'], 422);
        }

        return response()->json($result);
    }

    /**
     * Show one post by platform + post id.
     * GET /social-posts/{platform}/{postId}
     */
    public function show(string $platform, string $postId, SocialMediaService $social)
    {
        // Validate platform parameter
        if (!in_array($platform, ['facebook', 'instagram', 'twitter', 'linkedin'])) {
            return response()->json(['message' => 'Invalid platform'], 400);
        }

        $post = $social->getPost($platform, $postId);
        
        if (!$post) {
            return response()->json(['message' => 'Post not found'], 404);
        }

        return response()->json($post);
    }

    /**
     * Update a post's message (where the platform allows edits).
     * PUT /social-posts/{platform}/{postId}
     * body: { "message": "new text..." }
     */
    public function update(Request $request, string $platform, string $postId, SocialMediaService $social)
    {
        // Validate platform parameter
        if (!in_array($platform, ['facebook', 'instagram', 'twitter', 'linkedin'])) {
            return response()->json(['message' => 'Invalid platform'], 400);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:63000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $ok = $social->updatePostMessage($platform, $postId, $request->message);
        
        return $ok
            ? response()->json(['message' => 'Post updated successfully'])
            : response()->json(['message' => 'Update failed'], 422);
    }

    /**
     * Delete a post and update database status.
     * DELETE /social-posts/{platform}/{postId}
     */
    public function destroy(string $platform, string $postId, SocialMediaService $social)
    {
        // Validate platform parameter
        if (!in_array($platform, ['facebook', 'instagram', 'twitter', 'linkedin'])) {
            return response()->json(['message' => 'Invalid platform'], 400);
        }

        // Use transaction to ensure both operations succeed or fail together
        DB::beginTransaction();

        try {
            // First, find the blog post associated with this social media post
            $blog = Blog::where('social_media_post_id', $postId)
                      ->orWhere('social_media_facebook_post_id', $postId)
                      ->orWhere('social_media_instagram_post_id', $postId)
                      ->orWhere('social_media_twitter_post_id', $postId)
                      ->orWhere('social_media_linkedin_post_id', $postId)
                      ->orWhereJsonContains('social_media_post_ids', $postId)
                      ->first();

            // Delete the post from social media
            $ok = $social->deletePost($platform, $postId);
            
            if (!$ok) {
                DB::rollBack();
                return response()->json(['message' => 'Delete failed on social media platform'], 422);
            }

            // If we found a related blog post, update its status
            if ($blog) {
                $updateData = [
                    'article_status' => 'deactivated',
                    'social_media_published' => false
                ];

                // Clear the specific platform post ID
                switch ($platform) {
                    case 'facebook':
                        $updateData['social_media_facebook_post_id'] = null;
                        break;
                    case 'instagram':
                        $updateData['social_media_instagram_post_id'] = null;
                        break;
                    case 'twitter':
                        $updateData['social_media_twitter_post_id'] = null;
                        break;
                    case 'linkedin':
                        $updateData['social_media_linkedin_post_id'] = null;
                        break;
                }

                // Remove from the array of post IDs
                if ($blog->social_media_post_ids) {
                    $postIds = $blog->social_media_post_ids;
                    if (is_array($postIds)) {
                        $postIds = array_filter($postIds, function($id) use ($postId) {
                            return $id !== $postId;
                        });
                        $updateData['social_media_post_ids'] = !empty($postIds) ? $postIds : null;
                    }
                }

                $blog->update($updateData);
            }

            DB::commit();
            
            return response()->json([
                'message' => 'Post deleted successfully and database updated',
                'blog_updated' => $blog ? true : false,
                'blog_id' => $blog ? $blog->id : null
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting post and updating database: ' . $e->getMessage());
            return response()->json(['message' => 'Delete operation failed'], 500);
        }
    }

    /**
     * Re-publish a blog to a platform.
     * POST /social-posts/republish/{blog}?platform=facebook
     */
    public function republish(Request $request, Blog $blog, SocialMediaService $social)
    {
        $validator = Validator::make($request->all(), [
            'platform' => 'sometimes|string|in:facebook,instagram,twitter,linkedin'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $platform = $request->input('platform', 'facebook');

        $payload = [
            'title' => $blog->article_title,
            'type' => $blog->article_type,
            'description' => $blog->article_short_desc,
            'content' => $blog->article_long_desc,
            'image' => $blog->article_image,
            'video_url' => $blog->article_video_url,
            'document' => $blog->article_document,
            'url' => url('/blog/' . $blog->slug),
            'slug' => $blog->slug,
        ];

        $postId = $social->publish($platform, $payload);

        if (!$postId) {
            return response()->json(['message' => 'Republish failed'], 422);
        }

        // Update the blog with the new post ID
        $this->addPostIdToBlog($blog, $postId, $platform);

        return response()->json([
            'message' => 'Blog republished successfully', 
            'post_id' => $postId
        ]);
    }

    /**
     * Add post ID to blog's social media post IDs
     */
    private function addPostIdToBlog(Blog $blog, string $postId, string $platform)
    {
        $updateData = [
            'social_media_published' => true,
            'article_status' => 'published'
        ];

        // Store platform-specific post ID
        switch ($platform) {
            case 'facebook':
                $updateData['social_media_facebook_post_id'] = $postId;
                break;
            case 'instagram':
                $updateData['social_media_instagram_post_id'] = $postId;
                break;
            case 'twitter':
                $updateData['social_media_twitter_post_id'] = $postId;
                break;
            case 'linkedin':
                $updateData['social_media_linkedin_post_id'] = $postId;
                break;
        }

        // Add to the array of all post IDs
        $postIds = $blog->social_media_post_ids ?? [];
        if (!in_array($postId, $postIds)) {
            $postIds[] = $postId;
            $updateData['social_media_post_ids'] = $postIds;
        }

        $blog->update($updateData);
    }
    
    /**
     * Boost a post (increase its reach/visibility)
     * POST /social-posts/boost/{platform}/{postId}
     */
    public function boost(string $platform, string $postId, SocialMediaService $social)
    {
        // Validate platform parameter
        if (!in_array($platform, ['facebook', 'instagram', 'twitter', 'linkedin'])) {
            return response()->json(['message' => 'Invalid platform'], 400);
        }

        $ok = $social->boostPost($platform, $postId);
        
        return $ok
            ? response()->json(['message' => 'Post boosted successfully'])
            : response()->json(['message' => 'Boost failed'], 422);
    }
    
    /**
     * Share a post to another platform or user
     * POST /social-posts/share/{platform}/{postId}
     */
    public function share(Request $request, string $platform, string $postId, SocialMediaService $social)
    {
        // Validate platform parameter
        if (!in_array($platform, ['facebook', 'instagram', 'twitter', 'linkedin'])) {
            return response()->json(['message' => 'Invalid platform'], 400);
        }

        $validator = Validator::make($request->all(), [
            'target_platform' => 'required|string|in:facebook,instagram,twitter,linkedin',
            'message' => 'sometimes|string|max:63000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $ok = $social->sharePost($platform, $postId, $request->target_platform, $request->message);
        
        return $ok
            ? response()->json(['message' => 'Post shared successfully'])
            : response()->json(['message' => 'Share failed'], 422);
    }
    
    /**
     * Comment on a post
     * POST /social-posts/comment/{platform}/{postId}
     */
    public function comment(Request $request, string $platform, string $postId, SocialMediaService $social)
    {
        // Validate platform parameter
        if (!in_array($platform, ['facebook', 'instagram', 'twitter', 'linkedin'])) {
            return response()->json(['message' => 'Invalid platform'], 400);
        }

        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $ok = $social->commentOnPost($platform, $postId, $request->comment);
        
        return $ok
            ? response()->json(['message' => 'Comment added successfully'])
            : response()->json(['message' => 'Comment failed'], 422);
    }
}
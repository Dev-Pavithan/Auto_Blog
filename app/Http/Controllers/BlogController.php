<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Services\SocialMediaService;

class BlogController extends Controller
{
    // Define the allowed status transitions
    private $statusTransitions = [
        'deactive' => ['active'],
        'active' => ['published'],
        'published' => [] // No transitions allowed after published
    ];

    // Define available social media platforms
    private $socialMediaPlatforms = [
        'facebook', 'instagram', 'linkedin'
    ];

    // Facebook character limits for validation
    private $facebookLimits = [
        'title' => 255,
        'description' => 500,
        'content' => 63000
    ];

    protected $socialMediaService;

    public function __construct(SocialMediaService $socialMediaService)
    {
        $this->socialMediaService = $socialMediaService;
    }

    /**
     * Display a listing of all blogs (paginated).
     */
    public function index(Request $request)
    {
        $query = Blog::query();
        
        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('article_status', $request->status);
        } else {
            // Default to showing only published blogs for public access
            $query->where('article_status', 'published');
        }
        
        // Search by title if provided
        if ($request->has('search')) {
            $query->where('article_title', 'like', '%' . $request->search . '%');
        }
        
        // Order by latest first
        $blogs = $query->orderBy('created_at', 'desc')->paginate(15);
        
        return response()->json($blogs);
    }

    /**
     * Get all blogs without pagination (for your /blogs/all route)
     */
    public function getAllBlogs(Request $request)
    {
        $query = Blog::query();
        
        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('article_status', $request->status);
        }
        
        // Search by title if provided
        if ($request->has('search')) {
            $query->where('article_title', 'like', '%' . $request->search . '%');
        }
        
        // Order by latest first
        $blogs = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json($blogs);
    }

    /**
     * Display the specified blog post.
     */
    public function show($id)
    {
        $blog = Blog::find($id);
        
        if (!$blog) {
            return response()->json([
                'message' => 'Blog not found'
            ], 404);
        }
        
        return response()->json($blog);
    }

    /**
     * Store a newly created blog post.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'article_title' => 'required|string|max:' . $this->facebookLimits['title'],
            'article_type' => 'required|string',
            'article_short_desc' => 'required|string|max:' . $this->facebookLimits['description'],
            'article_long_desc' => 'required|string|max:' . $this->facebookLimits['content'],
            'article_image' => 'nullable|url',
            'article_video_url' => 'nullable|url',
            'article_document' => 'nullable|file|mimes:pdf,doc,docx,txt,ppt,pptx,xls,xlsx|max:10240',
            'article_status' => 'required|in:deactive,active,published',
            'social_media_platforms' => 'nullable|array',
            'social_media_platforms.*' => 'in:' . implode(',', $this->socialMediaPlatforms)
        ], [
            'article_title.max' => 'The title must not exceed ' . $this->facebookLimits['title'] . ' characters for Facebook compatibility.',
            'article_short_desc.max' => 'The short description must not exceed ' . $this->facebookLimits['description'] . ' characters for Facebook compatibility.',
            'article_long_desc.max' => 'The content must not exceed ' . $this->facebookLimits['content'] . ' characters for Facebook compatibility.',
            'article_document.file' => 'The article document must be a file upload.',
            'article_document.mimes' => 'The document must be a PDF, Word, Text, PowerPoint, or Excel file.',
            'article_document.max' => 'The document must not exceed 10MB in size.',
            'social_media_platforms.*.in' => 'Invalid social media platform. Allowed: ' . implode(', ', $this->socialMediaPlatforms)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        // Additional validation for social media publishing
        if ($request->article_status === 'published' && $request->has('social_media_platforms')) {
            $validationResult = $this->validateForSocialMedia($request);
            if ($validationResult !== true) {
                return $validationResult;
            }
        }

        $slug = Str::slug($request->article_title);
        $counter = 1;
        
        while (Blog::where('slug', $slug)->exists()) {
            $slug = Str::slug($request->article_title) . '-' . $counter;
            $counter++;
        }

        // Handle file upload - ONLY if a file was actually provided
        $articleDocumentPath = null;
        if ($request->hasFile('article_document')) {
            $articleDocumentPath = $this->handleDocumentUpload($request->file('article_document'));
        } else if ($request->filled('article_document') && filter_var($request->article_document, FILTER_VALIDATE_URL)) {
            // If it's a URL, store it directly
            $articleDocumentPath = $request->article_document;
        }
        // If no document is provided, articleDocumentPath remains null

        $blogData = [
            'article_title' => $request->article_title,
            'article_type' => $request->article_type,
            'article_short_desc' => $request->article_short_desc,
            'article_long_desc' => $request->article_long_desc,
            'article_image' => $request->article_image,
            'article_video_url' => $request->article_video_url,
            'article_document' => $articleDocumentPath, // This will be null if no document
            'article_status' => $request->article_status,
            'slug' => $slug,
        ];

        // Add social media platforms if provided
        $filteredPlatforms = [];
        if ($request->has('social_media_platforms')) {
            $filteredPlatforms = array_intersect($request->social_media_platforms, $this->socialMediaPlatforms);
            $blogData['social_media_platforms'] = json_encode(array_values($filteredPlatforms));
        }

        $blog = Blog::create($blogData);

        // If status is published, publish to social media
        if ($request->article_status === 'published' && !empty($filteredPlatforms)) {
            Log::info("New blog created with published status. Attempting to publish to social media platforms: " . implode(', ', $filteredPlatforms));
            
            $publishSuccess = $this->publishToSocialMedia($blog, $filteredPlatforms);
            
            if ($publishSuccess) {
                // Update the blog with social media publishing success
                $blog->update([
                    'published_at' => now(),
                    'social_media_published' => true
                ]);
                Log::info("New blog ID {$blog->id} successfully published to social media");
            } else {
                // Revert status to active if social media publishing fails
                $blog->update([
                    'article_status' => 'active',
                    'social_media_published' => false
                ]);
                Log::error("New blog ID {$blog->id} social media publishing failed. Status reverted to active.");
            }
        }

        return response()->json($blog, 201);
    }

    /**
     * Validate blog content for social media publishing
     */
    private function validateForSocialMedia(Request $request)
    {
        $errors = [];

        // Check if title is empty
        if (empty(trim($request->article_title))) {
            $errors['article_title'] = ['Title is required for social media publishing'];
        }

        // Check if description is empty
        if (empty(trim($request->article_short_desc))) {
            $errors['article_short_desc'] = ['Short description is required for social media publishing'];
        }

        // Check if content is empty
        if (empty(trim(strip_tags($request->article_long_desc)))) {
            $errors['article_long_desc'] = ['Content is required for social media publishing'];
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Social media publishing validation failed',
                'errors' => $errors
            ], 422);
        }

        return true;
    }

    /**
     * Update the specified blog post.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'article_title' => 'sometimes|required|string|max:' . $this->facebookLimits['title'],
            'article_type' => 'sometimes|required|string|max:255',
            'article_short_desc' => 'sometimes|required|string|max:' . $this->facebookLimits['description'],
            'article_long_desc' => 'sometimes|required|string|max:' . $this->facebookLimits['content'],
            'article_image' => 'sometimes|nullable|url',
            'article_video_url' => 'sometimes|nullable|url',
            'article_document' => 'sometimes|nullable|file|mimes:pdf,doc,docx,txt,ppt,pptx,xls,xlsx|max:10240',
            'article_status' => 'sometimes|required|in:deactive,active,published',
            'social_media_platforms' => 'nullable|array',
            'social_media_platforms.*' => 'in:' . implode(',', $this->socialMediaPlatforms)
        ], [
            'article_title.max' => 'The title must not exceed ' . $this->facebookLimits['title'] . ' characters for Facebook compatibility.',
            'article_short_desc.max' => 'The short description must not exceed ' . $this->facebookLimits['description'] . ' characters for Facebook compatibility.',
            'article_long_desc.max' => 'The content must not exceed ' . $this->facebookLimits['content'] . ' characters for Facebook compatibility.',
            'article_document.file' => 'The article document must be a file upload.',
            'article_document.mimes' => 'The document must be a PDF, Word, Text, PowerPoint, or Excel file.',
            'article_document.max' => 'The document must not exceed 10MB in size.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $blog = Blog::findOrFail($id);
        
        // Check if status is being changed to published
        $isPublishing = $request->has('article_status') && 
                       $request->article_status === 'published' && 
                       $blog->article_status !== 'published';

        // Validate status transition
        if ($request->has('article_status') && $request->article_status !== $blog->article_status) {
            if (!$this->isValidStatusTransition($blog->article_status, $request->article_status)) {
                return response()->json([
                    'message' => 'Invalid status transition. Allowed transitions: ' . 
                                $this->getAllowedTransitions($blog->article_status)
                ], 422);
            }
        }
        
        $validData = $request->only([
            'article_title',
            'article_type',
            'article_short_desc',
            'article_long_desc',
            'article_image',
            'article_video_url',
            'article_status'
        ]);
        
        // Handle document upload if provided
        if ($request->hasFile('article_document')) {
            // Delete old document if it exists and is a local file
            if ($blog->article_document && !filter_var($blog->article_document, FILTER_VALIDATE_URL)) {
                $this->deleteDocument($blog->article_document);
            }
            
            // Upload new document
            $articleDocumentPath = $this->handleDocumentUpload($request->file('article_document'));
            $validData['article_document'] = $articleDocumentPath;
        } else if ($request->has('article_document') && $request->article_document === null) {
            // Handle document removal
            if ($blog->article_document && !filter_var($blog->article_document, FILTER_VALIDATE_URL)) {
                $this->deleteDocument($blog->article_document);
            }
            $validData['article_document'] = null;
        }
        
        // Update slug if title changes
        if ($request->has('article_title') && $request->article_title !== $blog->article_title) {
            $slug = Str::slug($request->article_title);
            $counter = 1;
            
            while (Blog::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = Str::slug($request->article_title) . '-' . $counter;
                $counter++;
            }
            
            $validData['slug'] = $slug;
        }
        
        // Handle social media platforms
        $socialMediaPlatforms = [];
        if ($request->has('social_media_platforms')) {
            $socialMediaPlatforms = array_intersect($request->social_media_platforms, $this->socialMediaPlatforms);
            $validData['social_media_platforms'] = json_encode(array_values($socialMediaPlatforms));
        }

        // If status changed to published, try to publish to social media first
        if ($isPublishing) {
            // Get social media platforms from the blog if not provided in request
            if (empty($socialMediaPlatforms)) {
                $existingPlatforms = json_decode($blog->social_media_platforms, true) ?: [];
                $socialMediaPlatforms = $existingPlatforms;
            }
            
            if (!empty($socialMediaPlatforms)) {
                Log::info("Attempting to publish blog ID {$blog->id} to social media platforms: " . implode(', ', $socialMediaPlatforms));
                
                // Use the blog data for validation, not request data
                $validationResult = $this->validateBlogForSocialMedia($blog, $validData);
                if ($validationResult !== true) {
                    return $validationResult;
                }
                
                $publishSuccess = $this->publishToSocialMedia($blog, $socialMediaPlatforms);
                
                if (!$publishSuccess) {
                    Log::error("Social media publishing failed for blog ID {$blog->id}. Status will not be changed to published.");
                    return response()->json([
                        'message' => 'Failed to publish to social media. Article status not updated. Please check your social media credentials and try again.',
                        'error' => 'social_media_publishing_failed'
                    ], 422);
                }
                
                // Only update status and timestamps if social media publishing was successful
                Log::info("Social media publishing successful for blog ID {$blog->id}. Updating status to published.");
                $validData['published_at'] = now();
                $validData['social_media_published'] = true;
            } else {
                // No social media platforms configured, but still allow publishing
                Log::info("Blog ID {$blog->id} has no social media platforms configured. Allowing status change to published.");
                $validData['published_at'] = now();
                $validData['social_media_published'] = false;
            }
        }

        $blog->update($validData);
        
        return response()->json([
            'message' => 'Blog updated successfully',
            'data' => $blog
        ]);
    }

    /**
     * Validate blog content for social media publishing
     */
    private function validateBlogForSocialMedia(Blog $blog, array $updateData = [])
    {
        $errors = [];

        // Use updated data if available, otherwise use blog data
        $title = $updateData['article_title'] ?? $blog->article_title;
        $description = $updateData['article_short_desc'] ?? $blog->article_short_desc;
        $content = $updateData['article_long_desc'] ?? $blog->article_long_desc;

        // Check if title is empty
        if (empty(trim($title))) {
            $errors['article_title'] = ['Title is required for social media publishing'];
        }

        // Check if description is empty
        if (empty(trim($description))) {
            $errors['article_short_desc'] = ['Short description is required for social media publishing'];
        }

        // Check if content is empty
        if (empty(trim(strip_tags($content)))) {
            $errors['article_long_desc'] = ['Content is required for social media publishing'];
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Social media publishing validation failed',
                'errors' => $errors
            ], 422);
        }

        return true;
    }

    /**
     * Verify social media credentials
     */
    private function verifySocialMediaCredentials(array $platforms)
    {
        foreach ($platforms as $platform) {
            $verification = $this->socialMediaService->verifyCredentials($platform);
            if (!$verification) {
                Log::error("Failed to verify credentials for platform: {$platform}");
                return false;
            }
            Log::info("Verified credentials for {$platform}: " . json_encode($verification));
        }
        return true;
    }

    /**
     * Publish to social media
     */
    private function publishToSocialMedia(Blog $blog, array $platforms)
    {
        try {
            Log::info("Starting social media publishing for blog ID: {$blog->id} to platforms: " . implode(', ', $platforms));
            
            // Verify credentials first
            if (!$this->verifySocialMediaCredentials($platforms)) {
                Log::error('Social media credential verification failed');
                return false;
            }

            $successCount = 0;
            
            foreach ($platforms as $platform) {
                Log::info("Attempting to publish to {$platform} for blog: {$blog->id}");
                
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
                
                $result = $this->socialMediaService->publish($platform, [
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
                
                if ($result) {
                    $successCount++;
                    Log::info("Successfully published to {$platform} for blog: {$blog->id}. Result: " . json_encode($result));
                } else {
                    Log::warning("Failed to publish to {$platform} for blog: {$blog->id}");
                }
            }

            Log::info("Social media publishing completed. Success count: {$successCount} out of " . count($platforms));
            return $successCount > 0;

        } catch (\Exception $e) {
            Log::error('Social media publishing failed: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Update the document of the specified blog post.
     */
    public function updateDocument(Request $request, $id)
    {
        $request->validate([
            'article_document' => 'required|file|mimes:pdf,doc,docx,txt,ppt,pptx,xls,xlsx|max:10240'
        ], [
            'article_document.required' => 'Document file is required.',
            'article_document.file' => 'The article document must be a file upload.',
            'article_document.mimes' => 'The document must be a PDF, Word, Text, PowerPoint, or Excel file.',
            'article_document.max' => 'The document must not exceed 10MB in size.'
        ]);

        $blog = Blog::findOrFail($id);

        // Delete old document if it exists and is a local file
        if ($blog->article_document && !filter_var($blog->article_document, FILTER_VALIDATE_URL)) {
            $this->deleteDocument($blog->article_document);
        }

        // Upload new document
        $articleDocumentPath = $this->handleDocumentUpload($request->file('article_document'));

        $blog->update([
            'article_document' => $articleDocumentPath
        ]);

        return response()->json([
            'message' => 'Document updated successfully',
            'data' => $blog
        ]);
    }

    /**
     * Remove the specified blog post.
     */
    public function destroy($id)
    {
        $blog = Blog::find($id);
        
        if (!$blog) {
            return response()->json([
                'message' => 'Blog not found'
            ], 404);
        }

        if ($blog->article_document && !filter_var($blog->article_document, FILTER_VALIDATE_URL)) {
            $this->deleteDocument($blog->article_document);
        }

        $blog->delete();

        return response()->json([
            'message' => 'Blog deleted successfully'
        ]);
    }

    /**
     * Get blogs by status.
     */
    public function byStatus(Request $request)
    {
        $status = $request->query('status');
        
        if (!$status) {
            return response()->json([
                'message' => 'Status parameter is required'
            ], 400);
        }
        
        $blogs = Blog::where('article_status', $status)
            .orderBy('created_at', 'desc')
            ->paginate(10);
            
        return response()->json($blogs);
    }

    /**
     * Get available social media platforms.
     */
    public function getSocialMediaPlatforms()
    {
        return response()->json([
            'platforms' => $this->socialMediaPlatforms
        ]);
    }

    /**
     * Check social media publishing status for a specific blog
     */
    public function checkSocialMediaStatus($id)
    {
        $blog = Blog::findOrFail($id);
        
        $platforms = json_decode($blog->social_media_platforms, true) ?: [];
        
        $status = [
            'blog_id' => $blog->id,
            'article_status' => $blog->article_status,
            'social_media_published' => $blog->social_media_published,
            'configured_platforms' => $platforms,
            'published_at' => $blog->published_at,
            'can_publish' => $blog->article_status === 'published' && !$blog->social_media_published && !empty($platforms)
        ];
        
        return response()->json($status);
    }

    /**
     * Retry social media publishing for a specific blog
     */
    public function retrySocialMediaPublishing($id)
    {
        $blog = Blog::findOrFail($id);
        
        if ($blog->article_status !== 'published') {
            return response()->json([
                'message' => 'Blog must be published to retry social media publishing',
                'error' => 'invalid_status'
            ], 422);
        }
        
        if ($blog->social_media_published) {
            return response()->json([
                'message' => 'Blog is already published to social media',
                'error' => 'already_published'
            ], 422);
        }
        
        $platforms = json_decode($blog->social_media_platforms, true) ?: [];
        
        if (empty($platforms)) {
            return response()->json([
                'message' => 'No social media platforms configured for this blog',
                'error' => 'no_platforms'
            ], 422);
        }
        
        Log::info("Retrying social media publishing for blog ID {$blog->id} to platforms: " . implode(', ', $platforms));
        
        $publishSuccess = $this->publishToSocialMedia($blog, $platforms);
        
        if ($publishSuccess) {
            $blog->update([
                'social_media_published' => true
            ]);
            
            return response()->json([
                'message' => 'Social media publishing successful',
                'data' => $blog
            ]);
        } else {
            return response()->json([
                'message' => 'Social media publishing failed. Please check your credentials.',
                'error' => 'publishing_failed'
            ], 422);
        }
    }

    /**
     * Handle document file upload.
     */
    private function handleDocumentUpload($file)
    {
        $fileName = time() . '_' . $file->getClientOriginalName();
        $filePath = $file->storeAs('documents', $fileName, 'public');
        
        // Return the API URL format instead of storage URL
        return url('/api/documents/' . $fileName);
    }

    /**
     * Delete document file from storage.
     */
    private function deleteDocument($documentPath)
    {
        try {
            // Extract the filename from the URL
            $filename = basename($documentPath);
            
            if (Storage::disk('public')->exists('documents/' . $filename)) {
                Storage::disk('public')->delete('documents/' . $filename);
                Log::info("Deleted document: documents/{$filename}");
            } else {
                Log::warning("Document file not found: documents/{$filename}");
            }
        } catch (\Exception $e) {
            Log::error("Error deleting document: " . $e->getMessage());
        }
    }

    /**
     * Check if a status transition is valid.
     */
    private function isValidStatusTransition($currentStatus, $newStatus)
    {
        return in_array($newStatus, $this->statusTransitions[$currentStatus]);
    }

    /**
     * Get allowed transitions for a status.
     */
    private function getAllowedTransitions($currentStatus)
    {
        return implode(', ', $this->statusTransitions[$currentStatus]);
    }

    /**
     * Serve document files via API endpoint
     */
    public function serveDocument($filename)
    {
        $filePath = 'documents/' . $filename;
        
        if (!Storage::disk('public')->exists($filePath)) {
            abort(404, 'File not found');
        }
        
        return response()->file(Storage::disk('public')->path($filePath));
    }
}
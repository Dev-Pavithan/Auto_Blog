<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Services\SocialMediaService;
use Illuminate\Support\Facades\Log;

class BlogController extends Controller
{
    // Define the allowed status transitions
    private $statusTransitions = [
        'deactive' => ['active'],
        'active' => ['published'],
        'published' => [] // No transitions allowed after published
    ];

    // Define available social media platforms (only Facebook remains)
    private $socialMediaPlatforms = [
    'facebook', 'instagram'
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
            'article_title' => 'required|string|max:255',
            'article_type' => 'required|string',
            'article_short_desc' => 'required|string|max:500',
            'article_long_desc' => 'required|string',
            'article_image' => 'nullable|url',
            'article_video_url' => 'nullable|url',
            'article_document' => 'nullable|file|mimes:pdf,doc,docx,txt,ppt,pptx,xls,xlsx|max:10240',
            'article_status' => 'required|in:deactive,active,published',
            'social_media_platforms' => 'nullable|array',
            'social_media_platforms.*' => 'in:' . implode(',', $this->socialMediaPlatforms)
        ], [
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

        $slug = Str::slug($request->article_title);
        $counter = 1;
        
        while (Blog::where('slug', $slug)->exists()) {
            $slug = Str::slug($request->article_title) . '-' . $counter;
            $counter++;
        }

        // Handle file upload
        $articleDocumentPath = null;
        if ($request->hasFile('article_document')) {
            $articleDocumentPath = $this->handleDocumentUpload($request->file('article_document'));
        } else if ($request->filled('article_document') && filter_var($request->article_document, FILTER_VALIDATE_URL)) {
            $articleDocumentPath = $request->article_document;
        }

        $blogData = [
            'article_title' => $request->article_title,
            'article_type' => $request->article_type,
            'article_short_desc' => $request->article_short_desc,
            'article_long_desc' => $request->article_long_desc,
            'article_image' => $request->article_image,
            'article_video_url' => $request->article_video_url,
            'article_document' => $articleDocumentPath,
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
            $this->publishToSocialMedia($blog, $filteredPlatforms);
        }

        return response()->json($blog, 201);
    }

    /**
     * Update the specified blog post.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'article_title' => 'sometimes|required|string|max:255',
            'article_type' => 'sometimes|required|string|max:255',
            'article_short_desc' => 'sometimes|required|string|max:500',
            'article_long_desc' => 'sometimes|required|string',
            'article_image' => 'sometimes|nullable|url',
            'article_video_url' => 'sometimes|nullable|url',
            'article_status' => 'sometimes|required|in:deactive,active,published',
            'social_media_platforms' => 'nullable|array',
            'social_media_platforms.*' => 'in:' . implode(',', $this->socialMediaPlatforms)
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
        } else {
            // If platforms not provided in request, use existing ones
            $socialMediaPlatforms = json_decode($blog->social_media_platforms, true) ?? [];
        }

        // If status changed to published, try to publish to social media
        if ($isPublishing && !empty($socialMediaPlatforms)) {
            $publishSuccess = $this->publishToSocialMedia($blog, $socialMediaPlatforms);
            
            if (!$publishSuccess) {
                return response()->json([
                    'message' => 'Failed to publish to social media. Status not updated.'
                ], 422);
            }
            
            // Add publishing timestamp if successful
            $validData['published_at'] = now();
            $validData['social_media_published'] = true;
        }

        $blog->update($validData);
        
        return response()->json([
            'message' => 'Blog updated successfully',
            'data' => $blog
        ]);
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
            ->orderBy('created_at', 'desc')
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
     * Handle document file upload.
     */
    private function handleDocumentUpload($file)
    {
        $fileName = time() . '_' . $file->getClientOriginalName();
        $filePath = $file->storeAs('documents', $fileName, 'public');
        return Storage::url($filePath);
    }

    /**
     * Delete document file from storage.
     */
    private function deleteDocument($documentPath)
    {
        $filePath = str_replace('/storage/', '', $documentPath);
        Storage::disk('public')->delete($filePath);
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



// And update the publishToSocialMedia method:
private function publishToSocialMedia(Blog $blog, array $platforms)
{
    try {
        $successCount = 0;
        $postIds = [];
        
        Log::info("Starting social media publishing for blog: {$blog->id}");
        
        $publicUrl = 'https://your-actual-domain.com/blog/' . $blog->slug;
        
        foreach ($platforms as $platform) {
            Log::info("Publishing to {$platform} for blog: {$blog->id}");
            
            $result = $this->socialMediaService->publish($platform, [
                'title' => $blog->article_title,
                'type' => $blog->article_type,
                'short_desc' => $blog->article_short_desc,
                'long_desc' => $blog->article_long_desc,
                'image' => $blog->article_image,
                'video_url' => $blog->article_video_url,
                'document' => $blog->article_document,
                'url' => $publicUrl
            ]);
            
            if ($result) {
                $successCount++;
                Log::info("Successfully published to {$platform} for blog: {$blog->id}");
                
                $postIds[$platform] = is_string($result) ? $result : 'success';
                
                $postIdField = "social_media_{$platform}_post_id";
                if (isset($blog->$postIdField)) {
                    $blog->$postIdField = $result;
                }
            } else {
                Log::warning("Failed to publish to {$platform} for blog: {$blog->id}");
            }
        }

        $updateData = [
            'social_media_published' => $successCount > 0,
            'social_media_post_ids' => json_encode($postIds)
        ];
        
        if ($successCount > 0) {
            $updateData['published_at'] = now();
        }

        $blog->update($updateData);

        return $successCount > 0;

    } catch (\Exception $e) {
        Log::error('Social media publishing failed: ' . $e->getMessage());
        $blog->update(['social_media_published' => false]);
        return false;
    }
}
}
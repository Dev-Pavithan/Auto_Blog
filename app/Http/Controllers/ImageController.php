<?php

namespace App\Http\Controllers;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ImageController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $images = Image::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);
        
        return response()->json($images);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $image = Image::where('user_id', $user->id)->find($id);
        
        if (!$image) {
            return response()->json([
                'message' => 'Image not found'
            ], 404);
        }
        
        return response()->json($image);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            
            // Generate a unique filename
            $filename = uniqid() . '_' . time() . '.' . $file->getClientOriginalExtension();
            
            // Store the file
            $path = $file->storeAs('images', $filename, 'public');
            
            // Create image record
            $image = Image::create([
                'user_id' => $user->id,
                'filename' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $path,
                'url' => Storage::disk('public')->url($path)
            ]);

            return response()->json($image, 201);
        }

        return response()->json([
            'message' => 'No image file provided'
        ], 400);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $image = Image::where('user_id', $user->id)->find($id);
        
        if (!$image) {
            return response()->json([
                'message' => 'Image not found'
            ], 404);
        }

        // Delete the file from storage
        Storage::disk('public')->delete($image->path);

        // Delete the database record
        $image->delete();

        return response()->json([
            'message' => 'Image deleted successfully'
        ]);
    }
}
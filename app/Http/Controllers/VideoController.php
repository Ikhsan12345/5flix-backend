<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    public function __construct()
    {
        // Proteksi admin untuk CUD operations
        $this->middleware('auth:sanctum')->only(['store', 'update', 'destroy']);
    }

    public function index()
    {
        return Video::all();
    }

    public function show($id)
    {
        return Video::findOrFail($id);
    }

    public function store(Request $request)
    {
        try {
            // Manual admin check
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            Log::info('=== VIDEO STORE START ===', [
                'user_id' => $request->user()->id,
                'user_role' => $request->user()->role
            ]);

            $rules = [
                'title' => 'required|string|max:255',
                'genre' => 'required|string|max:100',
                'description' => 'nullable|string',
                'duration' => 'required|integer|min:1',
                'year' => 'required|integer|min:1900|max:2030',
                'is_featured' => 'nullable|in:0,1',
                'thumbnail' => 'required|file|image|mimes:jpg,jpeg,png|max:2048',
                'video' => 'required|file|mimes:mp4,mkv,avi|max:102400',
            ];

            $validated = $request->validate($rules);

            $thumbnailUrl = null;
            $videoUrl = null;

            if ($request->hasFile('thumbnail')) {
                $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'b2');
                $thumbnailUrl = Storage::disk('b2')->url($thumbnailPath);
            }

            if ($request->hasFile('video')) {
                $videoPath = $request->file('video')->store('videos', 'b2');
                $videoUrl = Storage::disk('b2')->url($videoPath);
            }

            $videoData = [
                'title' => $request->title,
                'genre' => $request->genre,
                'description' => $request->description,
                'duration' => (int)$request->duration,
                'year' => (int)$request->year,
                'is_featured' => $request->is_featured == '1' ? true : false,
                'thumbnail_url' => $thumbnailUrl,
                'video_url' => $videoUrl,
            ];

            $video = Video::create($videoData);
            Log::info('Video created successfully', ['video_id' => $video->id]);

            return response()->json([
                'success' => true,
                'message' => 'Video berhasil diupload',
                'data' => $video
            ], 201);

        } catch (\Exception $e) {
            Log::error('Store error:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        // Set response headers untuk debugging
        header('Content-Type: application/json');

        try {
            Log::info('=== UPDATE REQUEST START ===');
            Log::info('Video ID:', ['id' => $id, 'type' => gettype($id)]);
            Log::info('Request method:', ['method' => $request->method()]);
            Log::info('Request headers:', $request->headers->all());
            Log::info('Raw input:', $request->all());
            Log::info('JSON input:', $request->json() ? $request->json()->all() : 'No JSON');

            // Manual admin check dengan detail logging
            $user = $request->user();
            if (!$user) {
                Log::warning('No authenticated user found');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.'
                ], 401);
            }

            Log::info('Authenticated user:', [
                'id' => $user->id,
                'username' => $user->username,
                'role' => $user->role
            ]);

            if ($user->role !== 'admin') {
                Log::warning('Non-admin user attempted update', [
                    'user_id' => $user->id,
                    'role' => $user->role
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            // Validate ID
            if (!is_numeric($id) || $id <= 0) {
                Log::error('Invalid ID provided:', ['id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid video ID format'
                ], 400);
            }

            // Find video with detailed error handling
            try {
                $video = Video::findOrFail($id);
                Log::info('Video found:', [
                    'id' => $video->id,
                    'title' => $video->title,
                    'current_data' => $video->toArray()
                ]);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                Log::error('Video not found:', ['id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => "Video dengan ID {$id} tidak ditemukan"
                ], 404);
            }

            // Check if request has any data to update
            $hasData = false;
            $inputFields = ['title', 'genre', 'description', 'duration', 'year', 'is_featured'];
            foreach ($inputFields as $field) {
                if ($request->has($field)) {
                    $hasData = true;
                    Log::info("Field {$field} present:", ['value' => $request->input($field)]);
                    break;
                }
            }

            if (!$hasData && !$request->hasFile('thumbnail') && !$request->hasFile('video')) {
                Log::info('No data provided for update');
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data yang dikirim untuk diupdate'
                ], 400);
            }

            // More permissive validation rules
            $rules = [];
            $messages = [];

            if ($request->has('title')) {
                $rules['title'] = 'required|string|max:255';
                $messages['title.required'] = 'Judul tidak boleh kosong';
                $messages['title.string'] = 'Judul harus berupa teks';
                $messages['title.max'] = 'Judul maksimal 255 karakter';
            }

            if ($request->has('genre')) {
                $rules['genre'] = 'required|string|max:100';
                $messages['genre.required'] = 'Genre tidak boleh kosong';
            }

            if ($request->has('description')) {
                $rules['description'] = 'nullable|string';
            }

            if ($request->has('duration')) {
                $rules['duration'] = 'required|integer|min:1';
                $messages['duration.required'] = 'Durasi tidak boleh kosong';
                $messages['duration.integer'] = 'Durasi harus berupa angka';
                $messages['duration.min'] = 'Durasi minimal 1 menit';
            }

            if ($request->has('year')) {
                $rules['year'] = 'required|integer|min:1900|max:2030';
                $messages['year.required'] = 'Tahun tidak boleh kosong';
                $messages['year.integer'] = 'Tahun harus berupa angka';
                $messages['year.min'] = 'Tahun minimal 1900';
                $messages['year.max'] = 'Tahun maksimal 2030';
            }

            if ($request->has('is_featured')) {
                $rules['is_featured'] = 'boolean';
            }

            if ($request->hasFile('thumbnail')) {
                $rules['thumbnail'] = 'file|image|mimes:jpg,jpeg,png|max:2048';
            }

            if ($request->hasFile('video')) {
                $rules['video'] = 'file|mimes:mp4,mkv,avi|max:102400';
            }

            Log::info('Validation rules:', $rules);

            try {
                $validated = $request->validate($rules, $messages);
                Log::info('Validation passed:', $validated);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Validation failed:', [
                    'errors' => $e->errors(),
                    'input' => $request->all()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak valid',
                    'errors' => $e->errors()
                ], 422);
            }

            // Prepare update data safely
            $updateData = [];

            if ($request->has('title') && !empty(trim($request->input('title')))) {
                $updateData['title'] = trim($request->input('title'));
            }

            if ($request->has('genre') && !empty(trim($request->input('genre')))) {
                $updateData['genre'] = trim($request->input('genre'));
            }

            if ($request->has('description')) {
                $updateData['description'] = $request->input('description') ? trim($request->input('description')) : null;
            }

            if ($request->has('duration')) {
                $duration = $request->input('duration');
                if (is_numeric($duration) && $duration > 0) {
                    $updateData['duration'] = (int)$duration;
                }
            }

            if ($request->has('year')) {
                $year = $request->input('year');
                if (is_numeric($year) && $year >= 1900 && $year <= 2030) {
                    $updateData['year'] = (int)$year;
                }
            }

            if ($request->has('is_featured')) {
                // Handle boolean more carefully
                $isFeatured = $request->input('is_featured');
                if ($isFeatured === true || $isFeatured === 'true' || $isFeatured === 1 || $isFeatured === '1') {
                    $updateData['is_featured'] = true;
                } else {
                    $updateData['is_featured'] = false;
                }
            }

            Log::info('Prepared update data:', $updateData);

            // Handle file uploads with error handling
            if ($request->hasFile('thumbnail')) {
                Log::info('Processing thumbnail upload...');
                try {
                    // Delete old thumbnail
                    if ($video->thumbnail_url) {
                        $oldPath = parse_url($video->thumbnail_url, PHP_URL_PATH);
                        $oldPath = ltrim($oldPath, '/');
                        Storage::disk('b2')->delete($oldPath);
                        Log::info('Old thumbnail deleted');
                    }

                    // Upload new thumbnail
                    $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'b2');
                    $updateData['thumbnail_url'] = Storage::disk('b2')->url($thumbnailPath);
                    Log::info('New thumbnail uploaded:', ['url' => $updateData['thumbnail_url']]);
                } catch (\Exception $e) {
                    Log::error('Thumbnail upload failed:', ['error' => $e->getMessage()]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal upload thumbnail: ' . $e->getMessage()
                    ], 500);
                }
            }

            if ($request->hasFile('video')) {
                Log::info('Processing video upload...');
                try {
                    // Delete old video
                    if ($video->video_url) {
                        $oldPath = parse_url($video->video_url, PHP_URL_PATH);
                        $oldPath = ltrim($oldPath, '/');
                        Storage::disk('b2')->delete($oldPath);
                        Log::info('Old video deleted');
                    }

                    // Upload new video
                    $videoPath = $request->file('video')->store('videos', 'b2');
                    $updateData['video_url'] = Storage::disk('b2')->url($videoPath);
                    Log::info('New video uploaded:', ['url' => $updateData['video_url']]);
                } catch (\Exception $e) {
                    Log::error('Video upload failed:', ['error' => $e->getMessage()]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal upload video: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Update database if there's data to update
            if (!empty($updateData)) {
                Log::info('Performing database update:', $updateData);
                try {
                    $updateResult = $video->update($updateData);
                    Log::info('Database update result:', ['success' => $updateResult]);

                    if ($updateResult) {
                        $video->refresh();
                        Log::info('Video refreshed successfully');
                    }
                } catch (\Exception $e) {
                    Log::error('Database update failed:', [
                        'error' => $e->getMessage(),
                        'data' => $updateData
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal update database: ' . $e->getMessage()
                    ], 500);
                }
            }

            Log::info('=== UPDATE COMPLETED SUCCESSFULLY ===');

            return response()->json([
                'success' => true,
                'message' => 'Video berhasil diupdate',
                'data' => $video->fresh(), // Use fresh() to get latest data
                'updated_fields' => array_keys($updateData)
            ]);

        } catch (\Exception $e) {
            Log::error('=== UNEXPECTED UPDATE ERROR ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'video_id' => $id ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
                'error_details' => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            // Manual admin check
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $video = Video::findOrFail($id);

            // Hapus files dari B2
            if ($video->video_url) {
                $videoPath = parse_url($video->video_url, PHP_URL_PATH);
                $videoPath = ltrim($videoPath, '/');
                Storage::disk('b2')->delete($videoPath);
                Log::info("Video file deleted: {$videoPath}");
            }

            if ($video->thumbnail_url) {
                $thumbnailPath = parse_url($video->thumbnail_url, PHP_URL_PATH);
                $thumbnailPath = ltrim($thumbnailPath, '/');
                Storage::disk('b2')->delete($thumbnailPath);
                Log::info("Thumbnail file deleted: {$thumbnailPath}");
            }

            // Hapus dari database
            $video->delete();
            Log::info("Video deleted from database: {$id}");

            return response()->json([
                'success' => true,
                'message' => 'Video berhasil dihapus'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Video tidak ditemukan'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Delete error:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal hapus video: ' . $e->getMessage()
            ], 500);
        }
    }
}
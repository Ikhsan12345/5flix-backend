<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class VideoController extends Controller
{
    public function index()
    {
        try {
            $videos = Cache::remember('videos.all', 300, function () {
                return Video::select(['id', 'title', 'genre', 'thumbnail_url', 'duration', 'year', 'is_featured'])
                           ->orderBy('created_at', 'desc')
                           ->get();
            });

            return response()->json([
                'success' => true,
                'data' => $videos
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $video = Cache::remember("video.{$id}", 300, function () use ($id) {
                return Video::findOrFail($id);
            });

            return response()->json([
                'success' => true,
                'data' => $video
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Video tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'genre' => 'required|string|max:100',
                'description' => 'nullable|string',
                'duration' => 'required|integer|min:1',
                'year' => 'required|integer|min:1900|max:2030',
                'is_featured' => 'nullable|in:0,1',
                'thumbnail' => 'required|file|image|mimes:jpg,jpeg,png|max:2048',
                'video' => 'required|file|mimes:mp4,mkv,avi|max:102400',
            ]);

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

            $video = Video::create([
                'title' => $request->title,
                'genre' => $request->genre,
                'description' => $request->description,
                'duration' => (int)$request->duration,
                'year' => (int)$request->year,
                'is_featured' => $request->is_featured == '1',
                'thumbnail_url' => $thumbnailUrl,
                'video_url' => $videoUrl,
            ]);

            // Clear cache
            Cache::forget('videos.all');

            return response()->json([
                'success' => true,
                'message' => 'Video berhasil diupload',
                'data' => $video
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    private function deleteFileFromB2($fileUrl)
    {
        if (!$fileUrl) return false;

        try {
            $parsedUrl = parse_url($fileUrl);

            // Handle Backblaze friendly URL
            if (isset($parsedUrl['host']) && strpos($parsedUrl['host'], 'backblazeb2.com') !== false) {
                $pathParts = explode('/', $parsedUrl['path']);
                if (count($pathParts) >= 4 && $pathParts[1] === 'file') {
                    $filePath = implode('/', array_slice($pathParts, 3));
                    return Storage::disk('b2')->delete($filePath);
                }
            }

            // Handle S3-compatible endpoint
            if (isset($parsedUrl['path'])) {
                $filePath = ltrim($parsedUrl['path'], '/');
                $bucketName = env('B2_BUCKET');

                if ($bucketName && strpos($filePath, $bucketName . '/') === 0) {
                    $filePath = substr($filePath, strlen($bucketName) + 1);
                }

                return Storage::disk('b2')->delete($filePath);
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function update(Request $request, $id)
    {
        try {
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $video = Video::findOrFail($id);
            $allInputs = $request->all();
            $allFiles = $request->allFiles();

            if (empty($allInputs) && empty($allFiles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data untuk diupdate'
                ], 400);
            }

            $rules = [];
            $updateData = [];
            $textFields = ['title', 'genre', 'description', 'duration', 'year', 'is_featured'];

            // Handle text fields
            foreach ($textFields as $field) {
                if (array_key_exists($field, $allInputs)) {
                    $value = $allInputs[$field];

                    switch ($field) {
                        case 'title':
                            if ($value && trim($value) !== '') {
                                $rules['title'] = 'required|string|max:255';
                                $updateData['title'] = trim($value);
                            }
                            break;
                        case 'genre':
                            if ($value && trim($value) !== '') {
                                $rules['genre'] = 'required|string|max:100';
                                $updateData['genre'] = trim($value);
                            }
                            break;
                        case 'description':
                            $rules['description'] = 'nullable|string';
                            $updateData['description'] = $value ? trim($value) : null;
                            break;
                        case 'duration':
                            if (is_numeric($value) && $value > 0) {
                                $rules['duration'] = 'required|integer|min:1';
                                $updateData['duration'] = (int)$value;
                            }
                            break;
                        case 'year':
                            if (is_numeric($value) && $value >= 1900 && $value <= 2030) {
                                $rules['year'] = 'required|integer|min:1900|max:2030';
                                $updateData['year'] = (int)$value;
                            }
                            break;
                        case 'is_featured':
                            $rules['is_featured'] = 'nullable|boolean';
                            $updateData['is_featured'] = in_array($value, [true, 'true', 1, '1'], true);
                            break;
                    }
                }
            }

            // Handle file uploads
            if ($request->hasFile('thumbnail')) {
                $rules['thumbnail'] = 'file|image|mimes:jpg,jpeg,png|max:2048';
            }
            if ($request->hasFile('video')) {
                $rules['video'] = 'file|mimes:mp4,mkv,avi|max:102400';
            }

            // Validate
            if (!empty($rules)) {
                $request->validate($rules);
            }

            // Handle thumbnail replacement
            if ($request->hasFile('thumbnail')) {
                $this->deleteFileFromB2($video->thumbnail_url);
                $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'b2');
                $updateData['thumbnail_url'] = Storage::disk('b2')->url($thumbnailPath);
            }

            // Handle video replacement
            if ($request->hasFile('video')) {
                $this->deleteFileFromB2($video->video_url);
                $videoPath = $request->file('video')->store('videos', 'b2');
                $updateData['video_url'] = Storage::disk('b2')->url($videoPath);
            }

            if (empty($updateData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak valid'
                ], 400);
            }

            $video->update($updateData);

            // Clear cache
            Cache::forget('videos.all');
            Cache::forget("video.{$id}");

            return response()->json([
                'success' => true,
                'message' => 'Video berhasil diupdate',
                'data' => $video->fresh()
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Video tidak ditemukan'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $video = Video::findOrFail($id);

            // Delete files from B2
            $this->deleteFileFromB2($video->video_url);
            $this->deleteFileFromB2($video->thumbnail_url);

            // Delete from database
            $video->delete();

            // Clear cache
            Cache::forget('videos.all');
            Cache::forget("video.{$id}");

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
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    public function download(Request $request, $id)
    {
        try {
            $video = Video::findOrFail($id);

            // Validate user access if needed
            if (!$request->user()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            // Return video download URL
            return response()->json([
                'success' => true,
                'data' => [
                    'video_id' => $video->id,
                    'title' => $video->title,
                    'download_url' => $video->video_url,
                    'thumbnail_url' => $video->thumbnail_url,
                    'duration' => $video->duration,
                    'genre' => $video->genre,
                    'year' => $video->year
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Video tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }
}
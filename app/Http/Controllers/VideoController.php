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
        // Remove the middleware call from constructor
    }

    public function index()
    {
        try {
            $videos = Video::all();
            return response()->json([
                'success' => true,
                'data' => $videos
            ]);
        } catch (\Exception $e) {
            Log::error('Index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $video = Video::findOrFail($id);
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
            Log::error('Show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
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

            // Validasi form-data untuk teks dan file
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

            // Mengambil file dari form-data
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

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error:', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Store error:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper function untuk menghapus file dari B2 berdasarkan URL
     */
    private function deleteFileFromB2($fileUrl)
    {
        if (!$fileUrl) {
            return false;
        }

        try {
            // Parse URL untuk mendapatkan path file
            $parsedUrl = parse_url($fileUrl);

            Log::info('Attempting to delete file from B2:', [
                'url' => $fileUrl,
                'parsed' => $parsedUrl
            ]);

            // Method 1: Jika menggunakan friendly URL (f005.backblazeb2.com)
            if (isset($parsedUrl['host']) && strpos($parsedUrl['host'], 'backblazeb2.com') !== false) {
                // Extract path dari friendly URL
                // Contoh: https://f005.backblazeb2.com/file/bucket-name/thumbnails/filename.jpg
                $pathParts = explode('/', $parsedUrl['path']);
                if (count($pathParts) >= 4 && $pathParts[1] === 'file') {
                    // Skip /file/bucket-name/ dan ambil sisanya
                    $filePath = implode('/', array_slice($pathParts, 3));
                    Log::info('Extracted file path from friendly URL:', ['path' => $filePath]);

                    $deleted = Storage::disk('b2')->delete($filePath);
                    Log::info('Delete result:', ['success' => $deleted, 'path' => $filePath]);
                    return $deleted;
                }
            }

            // Method 2: Jika menggunakan S3-compatible endpoint
            else if (isset($parsedUrl['path'])) {
                // Remove leading slash dan bucket name jika ada
                $filePath = ltrim($parsedUrl['path'], '/');

                // Jika path dimulai dengan nama bucket, hapus
                $bucketName = env('B2_BUCKET');
                if ($bucketName && strpos($filePath, $bucketName . '/') === 0) {
                    $filePath = substr($filePath, strlen($bucketName) + 1);
                }

                Log::info('Extracted file path from S3 URL:', ['path' => $filePath]);

                $deleted = Storage::disk('b2')->delete($filePath);
                Log::info('Delete result:', ['success' => $deleted, 'path' => $filePath]);
                return $deleted;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Error deleting file from B2:', [
                'url' => $fileUrl,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function update(Request $request, $id)
    {
        try {
            Log::info('=== UPDATE REQUEST START ===');
            Log::info('Video ID:', ['id' => $id]);
            Log::info('Request Method:', ['method' => $request->method()]);

            // Manual admin check
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.'
                ], 401);
            }

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            // Validate ID
            if (!is_numeric($id) || $id <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid video ID format'
                ], 400);
            }

            // Find video
            try {
                $video = Video::findOrFail($id);
                Log::info('Video found:', ['id' => $video->id, 'title' => $video->title]);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                return response()->json([
                    'success' => false,
                    'message' => "Video dengan ID {$id} tidak ditemukan"
                ], 404);
            }

            // Deteksi data dengan lebih robust
            $hasAnyData = false;
            $allInputs = $request->all();
            $allFiles = $request->allFiles();

            Log::info('All inputs received:', $allInputs);
            Log::info('All files received:', array_keys($allFiles));

            // Cek apakah ada input text fields
            $textFields = ['title', 'genre', 'description', 'duration', 'year', 'is_featured'];
            foreach ($textFields as $field) {
                if (array_key_exists($field, $allInputs)) {
                    $hasAnyData = true;
                    break;
                }
            }

            // Cek apakah ada file uploads
            if (!empty($allFiles)) {
                $hasAnyData = true;
            }

            if (!$hasAnyData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data yang dikirim untuk diupdate. Kirim minimal satu field untuk diupdate.'
                ], 400);
            }

            // Validation rules
            $rules = [];
            $updateData = [];

            // Handle text fields
            foreach ($textFields as $field) {
                if (array_key_exists($field, $allInputs)) {
                    $value = $allInputs[$field];

                    switch ($field) {
                        case 'title':
                            if ($value !== null && trim($value) !== '') {
                                $rules['title'] = 'required|string|max:255';
                                $updateData['title'] = trim($value);
                            }
                            break;

                        case 'genre':
                            if ($value !== null && trim($value) !== '') {
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
                try {
                    $validated = $request->validate($rules);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Data tidak valid',
                        'errors' => $e->errors()
                    ], 422);
                }
            }

            // Handle thumbnail upload
            if ($request->hasFile('thumbnail')) {
                Log::info('Processing thumbnail upload...');
                try {
                    // PERBAIKAN: Hapus thumbnail lama sebelum upload baru
                    $oldThumbnailDeleted = $this->deleteFileFromB2($video->thumbnail_url);
                    Log::info('Old thumbnail deletion result:', ['success' => $oldThumbnailDeleted]);

                    // Upload thumbnail baru
                    $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'b2');
                    $updateData['thumbnail_url'] = Storage::disk('b2')->url($thumbnailPath);
                    Log::info('New thumbnail uploaded successfully');

                } catch (\Exception $e) {
                    Log::error('Thumbnail upload failed:', ['error' => $e->getMessage()]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal upload thumbnail: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Handle video upload
            if ($request->hasFile('video')) {
                Log::info('Processing video upload...');
                try {
                    // PERBAIKAN: Hapus video lama sebelum upload baru
                    $oldVideoDeleted = $this->deleteFileFromB2($video->video_url);
                    Log::info('Old video deletion result:', ['success' => $oldVideoDeleted]);

                    // Upload video baru
                    $videoPath = $request->file('video')->store('videos', 'b2');
                    $updateData['video_url'] = Storage::disk('b2')->url($videoPath);
                    Log::info('New video uploaded successfully');

                } catch (\Exception $e) {
                    Log::error('Video upload failed:', ['error' => $e->getMessage()]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal upload video: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Update database
            if (!empty($updateData)) {
                Log::info('Data yang akan diupdate:', $updateData);
                try {
                    $updateResult = $video->update($updateData);

                    if ($updateResult) {
                        $video->refresh();
                        Log::info('Video berhasil diupdate');
                    }
                } catch (\Exception $e) {
                    Log::error('Gagal update database:', ['error' => $e->getMessage()]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal update database: ' . $e->getMessage()
                    ], 500);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Data yang dikirim tidak valid atau kosong'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Video berhasil diupdate',
                'data' => $video->fresh(),
                'updated_fields' => array_keys($updateData)
            ]);

        } catch (\Exception $e) {
            Log::error('=== UNEXPECTED UPDATE ERROR ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'video_id' => $id ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
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

            // PERBAIKAN: Gunakan helper function untuk hapus file
            $videoDeleted = $this->deleteFileFromB2($video->video_url);
            $thumbnailDeleted = $this->deleteFileFromB2($video->thumbnail_url);

            Log::info("File deletion results:", [
                'video_deleted' => $videoDeleted,
                'thumbnail_deleted' => $thumbnailDeleted
            ]);

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

    public function debugUpdate(Request $request, $id)
    {
        return response()->json([
            'method' => $request->method(),
            'video_id' => $id,
            'all_data' => $request->all(),
            'files' => $request->allFiles(),
            'headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type'),
            'user' => $request->user() ? [
                'id' => $request->user()->id,
                'role' => $request->user()->role
            ] : null,
        ]);
    }
}
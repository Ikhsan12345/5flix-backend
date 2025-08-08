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
        // Middleware should be applied in routes, not in controller constructor like this
        // The correct way is in the routes file or use $this->middleware() properly
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



    public function update(Request $request, $id)
    {
        try {
            Log::info('=== UPDATE REQUEST START ===');
            Log::info('Video ID:', ['id' => $id]);

            // Manual admin check
            $user = $request->user();
            if (!$user) {
                Log::warning('No authenticated user found');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.'
                ], 401);
            }

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

            // Find video
            try {
                $video = Video::findOrFail($id);
                Log::info('Video found:', ['id' => $video->id, 'title' => $video->title]);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                Log::error('Video not found:', ['id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => "Video dengan ID {$id} tidak ditemukan"
                ], 404);
            }

            // Validation rules - hanya validasi field yang ada
            $rules = [];
            $messages = [];
            $hasAnyData = false; // Flag untuk cek apakah ada data yang dikirim

            // Cek semua kemungkinan input dengan cara yang lebih robust
            $allPossibleFields = ['title', 'genre', 'description', 'duration', 'year', 'is_featured'];

            Log::info('Checking for fields...');
            foreach ($allPossibleFields as $field) {
                $hasField = $request->has($field);
                $value = $request->input($field);
                $filled = $request->filled($field);

                Log::info("Field check: {$field}", [
                    'has' => $hasField,
                    'value' => $value,
                    'filled' => $filled,
                    'type' => gettype($value)
                ]);

                if ($hasField || $filled) {
                    $hasAnyData = true;
                    Log::info("Field ditemukan: {$field}", ['value' => $value]);
                }
            }

            // Cek file uploads
            if ($request->hasFile('thumbnail') || $request->hasFile('video')) {
                $hasAnyData = true;
                Log::info('File uploads detected');
            }

            // Jika tidak ada data sama sekali
            if (!$hasAnyData) {
                Log::warning('No update data provided');
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data yang dikirim untuk diupdate. Kirim minimal satu field untuk diupdate.'
                ], 400);
            }

            // Set validation rules berdasarkan data yang ada
            if ($request->has('title') || $request->filled('title')) {
                $rules['title'] = 'required|string|max:255';
            }
            if ($request->has('genre') || $request->filled('genre')) {
                $rules['genre'] = 'required|string|max:100';
            }
            if ($request->has('description') || $request->filled('description')) {
                $rules['description'] = 'nullable|string';
            }
            if ($request->has('duration') || $request->filled('duration')) {
                $rules['duration'] = 'required|integer|min:1';
            }
            if ($request->has('year') || $request->filled('year')) {
                $rules['year'] = 'required|integer|min:1900|max:2030';
            }
            if ($request->has('is_featured') || $request->filled('is_featured')) {
                $rules['is_featured'] = 'nullable|boolean';
            }
            if ($request->hasFile('thumbnail')) {
                $rules['thumbnail'] = 'file|image|mimes:jpg,jpeg,png|max:2048';
            }
            if ($request->hasFile('video')) {
                $rules['video'] = 'file|mimes:mp4,mkv,avi|max:102400';
            }

            // Only validate if there are rules
            if (!empty($rules)) {
                try {
                    $validated = $request->validate($rules, $messages);
                    Log::info('Validation passed');
                } catch (\Illuminate\Validation\ValidationException $e) {
                    Log::error('Validation failed:', ['errors' => $e->errors()]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Data tidak valid',
                        'errors' => $e->errors()
                    ], 422);
                }
            }

            // Prepare update data
            $updateData = [];

            // Handle text fields dengan pengecekan yang lebih fleksibel
            if ($request->has('title')) {
                $title = $request->input('title');
                if ($title !== null && trim($title) !== '') {
                    $updateData['title'] = trim($title);
                }
            }

            if ($request->has('genre')) {
                $genre = $request->input('genre');
                if ($genre !== null && trim($genre) !== '') {
                    $updateData['genre'] = trim($genre);
                }
            }

            if ($request->has('description')) {
                $description = $request->input('description');
                $updateData['description'] = $description ? trim($description) : null;
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
                $isFeatured = $request->input('is_featured');
                // Handle berbagai format boolean
                if ($isFeatured === true || $isFeatured === 'true' || $isFeatured === 1 || $isFeatured === '1') {
                    $updateData['is_featured'] = true;
                } elseif ($isFeatured === false || $isFeatured === 'false' || $isFeatured === 0 || $isFeatured === '0') {
                    $updateData['is_featured'] = false;
                } else {
                    $updateData['is_featured'] = (bool)$isFeatured;
                }
            }

            // Handle file uploads
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
                    Log::info('New thumbnail uploaded');
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
                    Log::info('New video uploaded');
                } catch (\Exception $e) {
                    Log::error('Video upload failed:', ['error' => $e->getMessage()]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal upload video: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Update database jika ada data untuk diupdate
            if (!empty($updateData)) {
                Log::info('Data yang akan diupdate:', $updateData);
                try {
                    $updateResult = $video->update($updateData);
                    Log::info('Hasil update database:', ['success' => $updateResult]);

                    if ($updateResult) {
                        $video->refresh();
                        Log::info('Video berhasil di-refresh');
                    }
                } catch (\Exception $e) {
                    Log::error('Gagal update database:', ['error' => $e->getMessage()]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal update database: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Jika tidak ada yang diupdate (bisa jadi cuma upload file)
            if (empty($updateData) && !$request->hasFile('thumbnail') && !$request->hasFile('video')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data yang dikirim tidak valid atau kosong'
                ], 400);
            }

            Log::info('=== UPDATE COMPLETED SUCCESSFULLY ===');

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
    }public function debugUpdate(Request $request, $id)
    {
        return response()->json([
            'method' => $request->method(),
            'video_id' => $id,
            'all_data' => $request->all(),
            'files' => $request->allFiles(),
            'headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type'),
            'is_json' => $request->isJson(),
            'raw_content' => $request->getContent(),
            'input_keys' => array_keys($request->all()),
            'request_data' => $request->request->all(),
            'query_params' => $request->query(),
            'has_title' => $request->has('title'),
            'filled_title' => $request->filled('title'),
            'title_value' => $request->input('title'),
            'user' => $request->user() ? [
                'id' => $request->user()->id,
                'role' => $request->user()->role
            ] : null,
            'field_checks' => [
                'title' => [
                    'has' => $request->has('title'),
                    'filled' => $request->filled('title'),
                    'value' => $request->input('title'),
                    'type' => gettype($request->input('title'))
                ],
                'genre' => [
                    'has' => $request->has('genre'),
                    'filled' => $request->filled('genre'),
                    'value' => $request->input('genre'),
                    'type' => gettype($request->input('genre'))
                ]
            ]
        ]);
    }
}
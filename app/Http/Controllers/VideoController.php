<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    // List semua video
    public function index()
    {
        return Video::all();
    }

    // Simpan video baru (dengan upload file)
    public function store(Request $request)
    {
        try {
            // Debug: Log SEMUA data yang diterima dengan detail
            Log::info('=== VIDEO UPLOAD DEBUG START ===');
            Log::info('Request Method:', ['method' => $request->method()]);
            Log::info('Content Type:', ['content_type' => $request->header('Content-Type')]);
            Log::info('All Input Data:', $request->all());
            Log::info('All Files:', $request->allFiles());

            // Debug setiap field individual
            Log::info('Individual Field Check:', [
                'title' => $request->input('title'),
                'genre' => $request->input('genre'),
                'description' => $request->input('description'),
                'duration' => $request->input('duration'),
                'year' => $request->input('year'),
                'is_featured' => $request->input('is_featured'),
                'has_thumbnail' => $request->hasFile('thumbnail'),
                'has_video' => $request->hasFile('video'),
            ]);

            // Debug file details jika ada
            if ($request->hasFile('thumbnail')) {
                $thumb = $request->file('thumbnail');
                Log::info('Thumbnail File Details:', [
                    'name' => $thumb->getClientOriginalName(),
                    'extension' => $thumb->getClientOriginalExtension(),
                    'mime' => $thumb->getMimeType(),
                    'size' => $thumb->getSize(),
                    'valid' => $thumb->isValid(),
                    'error' => $thumb->getError()
                ]);
            }

            if ($request->hasFile('video')) {
                $vid = $request->file('video');
                Log::info('Video File Details:', [
                    'name' => $vid->getClientOriginalName(),
                    'extension' => $vid->getClientOriginalExtension(),
                    'mime' => $vid->getMimeType(),
                    'size' => $vid->getSize(),
                    'valid' => $vid->isValid(),
                    'error' => $vid->getError()
                ]);
            }

            // Validasi dengan rules yang lebih loose untuk debug
            Log::info('Starting validation...');

            $rules = [
                'title' => 'required|string|max:255',
                'genre' => 'required|string|max:100',
                'description' => 'nullable|string',
                'duration' => 'required|integer|min:1',
                'year' => 'required|integer|min:1900|max:2030',
                'is_featured' => 'nullable|in:0,1',  // Hanya terima 0 atau 1
                'thumbnail' => 'required|file|image|mimes:jpg,jpeg,png|max:2048',
                'video' => 'required|file|mimes:mp4,mkv,avi|max:102400',
            ];

            Log::info('Validation Rules:', $rules);

            // Validasi step by step
            $messages = [
                'title.required' => 'Judul video wajib diisi',
                'title.string' => 'Judul harus berupa text',
                'title.max' => 'Judul maksimal 255 karakter',
                'genre.required' => 'Genre wajib diisi',
                'duration.required' => 'Durasi wajib diisi',
                'duration.integer' => 'Durasi harus berupa angka',
                'year.required' => 'Tahun wajib diisi',
                'year.integer' => 'Tahun harus berupa angka',
                'is_featured.in' => 'is_featured harus berupa 0 atau 1',
                'thumbnail.required' => 'Thumbnail wajib diupload',
                'thumbnail.file' => 'Thumbnail harus berupa file',
                'thumbnail.image' => 'Thumbnail harus berupa gambar',
                'thumbnail.mimes' => 'Thumbnail harus format jpg, jpeg, atau png',
                'thumbnail.max' => 'Thumbnail maksimal 2MB',
                'video.required' => 'Video wajib diupload',
                'video.file' => 'Video harus berupa file',
                'video.mimes' => 'Video harus format mp4, mkv, atau avi',
                'video.max' => 'Video maksimal 100MB',
            ];

            $validated = $request->validate($rules, $messages);
            Log::info('Validation passed successfully:', $validated);

            // Upload files
            $thumbnailUrl = null;
            $videoUrl = null;

            // Upload thumbnail ke B2
            if ($request->hasFile('thumbnail')) {
                Log::info('Starting thumbnail upload to B2...');
                try {
                    $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'b2');
                    $thumbnailUrl = Storage::disk('b2')->url($thumbnailPath);
                    Log::info('Thumbnail uploaded successfully:', [
                        'path' => $thumbnailPath,
                        'url' => $thumbnailUrl
                    ]);
                } catch (\Exception $e) {
                    Log::error('Thumbnail upload failed:', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal upload thumbnail: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Upload video ke B2
            if ($request->hasFile('video')) {
                Log::info('Starting video upload to B2...');
                try {
                    $videoPath = $request->file('video')->store('videos', 'b2');
                    $videoUrl = Storage::disk('b2')->url($videoPath);
                    Log::info('Video uploaded successfully:', [
                        'path' => $videoPath,
                        'url' => $videoUrl
                    ]);
                } catch (\Exception $e) {
                    Log::error('Video upload failed:', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal upload video: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Siapkan data untuk database
            $videoData = [
                'title'         => $request->title,
                'genre'         => $request->genre,
                'description'   => $request->description,
                'duration'      => (int)$request->duration,
                'year'          => (int)$request->year,
                'is_featured'   => $request->is_featured == '1' ? true : false,
                'thumbnail_url' => $thumbnailUrl,
                'video_url'     => $videoUrl,
            ];

            Log::info('Data prepared for database:', $videoData);

            // Simpan ke database
            $video = Video::create($videoData);
            Log::info('Video saved to database successfully:', ['id' => $video->id]);
            Log::info('=== VIDEO UPLOAD DEBUG END ===');

            return response()->json([
                'success' => true,
                'message' => 'Video berhasil diupload',
                'data' => $video
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('=== VALIDATION ERROR DETAILS ===');
            Log::error('Validation Errors:', $e->errors());
            Log::error('Failed Rules:', $e->validator->failed());
            Log::error('Error Message:', $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
                'details' => 'Check the log for detailed validation errors'
            ], 422);

        } catch (\Exception $e) {
            Log::error('Unexpected error in video store:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }

    // Method lainnya tetap sama
    public function show($id)
    {
        return Video::findOrFail($id);
    }

    public function update(Request $request, $id)
{
    try {
        $video = Video::findOrFail($id);

        // Validasi dan proses update video (seperti biasa)
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'genre' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'duration' => 'sometimes|required|integer|min:1',
            'year' => 'sometimes|required|integer|min:1900|max:2030',
            'is_featured' => 'sometimes|boolean',
            'thumbnail' => 'sometimes|file|image|mimes:jpg,jpeg,png|max:2048',
            'video' => 'sometimes|file|mimes:mp4,mkv,avi|max:102400',
        ]);

        // Debugging: Log data yang dikirim ke update
        Log::info('Updating video data', [
            'id' => $id,
            'validated_data' => $validated
        ]);

        // Update thumbnail jika ada file baru
        if ($request->hasFile('thumbnail')) {
            // Hapus thumbnail lama dari B2
            if ($video->thumbnail_url) {
                $thumbnailPath = parse_url($video->thumbnail_url, PHP_URL_PATH);
                $thumbnailPath = ltrim($thumbnailPath, '/');
                Storage::disk('b2')->delete($thumbnailPath);
                Log::info("Old thumbnail deleted from B2: {$thumbnailPath}");
            }

            // Upload thumbnail baru
            $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'b2');
            $validated['thumbnail_url'] = Storage::disk('b2')->url($thumbnailPath);
        }

        // Update video jika ada file baru
        if ($request->hasFile('video')) {
            // Hapus video lama dari B2
            if ($video->video_url) {
                $videoPath = parse_url($video->video_url, PHP_URL_PATH);
                $videoPath = ltrim($videoPath, '/');
                Storage::disk('b2')->delete($videoPath);
                Log::info("Old video deleted from B2: {$videoPath}");
            }

            // Upload video baru
            $videoPath = $request->file('video')->store('videos', 'b2');
            $validated['video_url'] = Storage::disk('b2')->url($videoPath);
        }

        // Update data video di database
        $video->update($validated);

        Log::info('Updated video data:', ['id' => $video->id]);

        return response()->json([
            'success' => true,
            'message' => 'Video berhasil diupdate',
            'data' => $video
        ]);
    } catch (\Exception $e) {
        Log::error('Video update error:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Gagal update video: ' . $e->getMessage()
        ], 500);
    }
}



    public function destroy($id)
{
    try {
        // Cari video berdasarkan ID
        $video = Video::findOrFail($id);

        // Hapus file video dari B2
        if ($video->video_url) {
            // Dapatkan path file video dari URL
            $videoPath = parse_url($video->video_url, PHP_URL_PATH);
            $videoPath = ltrim($videoPath, '/');  // Hapus '/' di depan

            // Hapus video dari B2
            Storage::disk('b2')->delete($videoPath);
            Log::info("Video deleted from B2: {$videoPath}");
        }

        // Hapus file thumbnail dari B2
        if ($video->thumbnail_url) {
            // Dapatkan path file thumbnail dari URL
            $thumbnailPath = parse_url($video->thumbnail_url, PHP_URL_PATH);
            $thumbnailPath = ltrim($thumbnailPath, '/');  // Hapus '/' di depan

            // Hapus thumbnail dari B2
            Storage::disk('b2')->delete($thumbnailPath);
            Log::info("Thumbnail deleted from B2: {$thumbnailPath}");
        }

        // Hapus video dari database
        $video->delete();

        return response()->json([
            'success' => true,
            'message' => 'Video berhasil dihapus'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal hapus video: ' . $e->getMessage()
        ], 500);
    }
}

}
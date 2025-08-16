<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\CacheService;
use Illuminate\Support\Str;

class VideoController extends Controller
{
    /** ========== Helpers ========== */

    // Ubah nilai kolom (key atau URL S3) menjadi "key" murni (videos/xxx.ext)
    private function normalizeKey(?string $value): ?string
    {
        if (!$value) return null;
        $value = trim($value);
        if ($value === '') return null;

        // Jika sudah relatif (disarankan)
        if (!Str::startsWith($value, ['http://', 'https://'])) {
            return ltrim($value, '/');
        }

        // Jika URL S3 (virtual-host / path-style), ambil path lalu buang "<bucket>/"
        $p = parse_url($value);
        if (!isset($p['path'])) return null;

        $path   = ltrim($p['path'], '/');       // "5-flix/videos/xxx.mp4" atau "videos/xxx.mp4"
        $bucket = env('B2_BUCKET');

        if ($bucket && Str::startsWith($path, $bucket . '/')) {
            return substr($path, strlen($bucket) + 1);
        }
        return $path; // virtual-host sudah "videos/xxx.mp4"
    }

    private function guessVideoMime(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp4'  => 'video/mp4',
            'm4v'  => 'video/x-m4v',
            'mkv'  => 'video/x-matroska',
            'webm' => 'video/webm',
            'avi'  => 'video/x-msvideo',
            'mov'  => 'video/quicktime',
            '3gp'  => 'video/3gpp',
            'ts'   => 'video/mp2t',
            'flv'  => 'video/x-flv',
            default => 'application/octet-stream',
        };
    }

    private function guessImageMime(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            default       => 'image/jpeg',
        };
    }

    // Pre-signed URL dari key (SigV4) via disk 'b2'
    private function presigned(string $key, int $ttl = 600, array $opts = []): string
    {
        $ttl = max(60, min($ttl, 3600)); // 1..60 menit
        return Storage::disk('b2')->temporaryUrl($key, now()->addSeconds($ttl), $opts);
    }

    // Hapus objek: terima key atau URL
    private function deleteFileFromB2(?string $keyOrUrl): bool
    {
        if (!$keyOrUrl) return false;
        $key = $this->normalizeKey($keyOrUrl);
        if (!$key) return false;
        return Storage::disk('b2')->delete($key);
    }

    /** ========== READ ========== */

    // Tambahkan Request agar bisa pakai query (?embed_signed=1 dll)
    public function index(Request $request)
{
    try {
        $videos = CacheService::getVideos();

        $videos = $videos->map(function ($video) {
            $videoKey = $this->normalizeKey($video->video_url ?? '');
            $thumbKey = $this->normalizeKey($video->thumbnail_url ?? '');

            return [
                'id' => $video->id,
                'title' => $video->title,
                'genre' => $video->genre,
                'description' => $video->description,
                'duration' => $video->duration,
                'duration_minutes' => round(($video->duration ?? 0) / 60, 1),
                'duration_formatted' => $this->formatDuration($video->duration ?? 0),
                'year' => $video->year,
                'is_featured' => (bool) $video->is_featured,

                // Direct B2 URLs dengan TTL panjang
                'stream_url' => $videoKey ? $this->presigned($videoKey, 3600, ['ResponseContentType' => $this->guessVideoMime($videoKey)]) : null,
                'thumbnail_url' => $thumbKey ? $this->presigned($thumbKey, 3600, ['ResponseContentType' => $this->guessImageMime($thumbKey)]) : null,

                // Backup endpoint (untuk refresh token jika perlu)
                'stream_endpoint' => route('api.video.stream', $video->id),
                'thumbnail_endpoint' => route('api.video.thumbnail', $video->id),
            ];
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

    public function show(Request $request, $id)
    {
        try {
            $video = CacheService::getVideo($id);

            $durationMinutes = round(($video->duration ?? 0) / 60, 1);
            $embedSigned = $request->boolean('embed_signed', false);
            $ttl         = (int) $request->query('ttl', 600);

            $videoKey = $this->normalizeKey($video->video_url ?? '');
            $thumbKey = $this->normalizeKey($video->thumbnail_url ?? '');

            $signedStream = $embedSigned && $videoKey
                ? $this->presigned($videoKey, $ttl, ['ResponseContentType' => $this->guessVideoMime($videoKey)])
                : null;

            $signedThumb  = $embedSigned && $thumbKey
                ? $this->presigned($thumbKey, $ttl, ['ResponseContentType' => $this->guessImageMime($thumbKey)])
                : null;

            $videoData = [
                'id' => $video->id,
                'title' => $video->title,
                'genre' => $video->genre,
                'description' => $video->description,
                'duration' => $video->duration,
                'duration_minutes' => $durationMinutes,
                'duration_formatted' => $this->formatDuration($video->duration ?? 0),
                'year' => $video->year,
                'is_featured' => (bool) $video->is_featured,
                'created_at' => $video->created_at,
                'updated_at' => $video->updated_at,

                // Endpoint streaming (redirect → pre-signed)
                'stream_url'    => route('api.video.stream', $video->id),
                'thumbnail_url' => route('api.video.thumbnail', $video->id),

                // Pre-signed opsional
                'signed_stream_url'    => $signedStream,
                'signed_thumbnail_url' => $signedThumb,

                // Untuk admin/debug (key yang disimpan)
                'original_video_url'     => $videoKey,
                'original_thumbnail_url' => $thumbKey,
            ];

            return response()->json([
                'success' => true,
                'data' => $videoData
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Video tidak ditemukan'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Server error'], 500);
        }
    }
    public function getUploadUrls(Request $request)
{
    if (!$request->user() || $request->user()->role !== 'admin') {
        return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
    }

    $validated = $request->validate([
        'video_filename' => 'required|string',
        'thumb_filename' => 'required|string',
        'content_type_video' => 'required|string',
        'content_type_thumb' => 'required|string',
    ]);

    $videoKey = 'videos/' . uniqid() . '_' . $validated['video_filename'];
    $thumbKey = 'thumbnails/' . uniqid() . '_' . $validated['thumb_filename'];

    // Generate pre-signed URLs untuk upload
    $videoUploadUrl = Storage::disk('b2')->temporaryUploadUrl(
        $videoKey,
        now()->addMinutes(30),
        ['ContentType' => $validated['content_type_video']]
    );

    $thumbUploadUrl = Storage::disk('b2')->temporaryUploadUrl(
        $thumbKey,
        now()->addMinutes(30),
        ['ContentType' => $validated['content_type_thumb']]
    );

    return response()->json([
        'success' => true,
        'data' => [
            'video_upload_url' => $videoUploadUrl,
            'thumb_upload_url' => $thumbUploadUrl,
            'video_key' => $videoKey,
            'thumb_key' => $thumbKey,
            'expires_in' => 1800
        ]
    ]);
}

public function confirmUpload(Request $request)
{
    if (!$request->user() || $request->user()->role !== 'admin') {
        return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
    }

    $validated = $request->validate([
        'title' => 'required|string|max:255',
        'genre' => 'required|string|max:100',
        'description' => 'nullable|string',
        'duration' => 'required|integer|min:1',
        'year' => 'required|integer|min:1900|max:2030',
        'is_featured' => 'nullable|in:0,1',
        'video_key' => 'required|string',
        'thumb_key' => 'required|string',
    ]);

    // Verifikasi file sudah terupload (optional)
    if (!Storage::disk('b2')->exists($validated['video_key'])) {
        return response()->json(['success' => false, 'message' => 'Video upload not completed'], 400);
    }

    $video = Video::create([
        'title' => $validated['title'],
        'genre' => $validated['genre'],
        'description' => $validated['description'],
        'duration' => (int) $validated['duration'],
        'year' => (int) $validated['year'],
        'is_featured' => $validated['is_featured'] == '1',
        'video_url' => $validated['video_key'],
        'thumbnail_url' => $validated['thumb_key'],
    ]);

    CacheService::clearVideoCache($video->id);

    return response()->json([
        'success' => true,
        'message' => 'Video berhasil dibuat',
        'data' => [
            'id' => $video->id,
            'title' => $video->title,
            // ... response data lainnya
        ]
    ]);
}

    /** ========== WRITE ========== */

    public function store(Request $request)
    {
        try {
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $validated = $request->validate([
                'title'       => 'required|string|max:255',
                'genre'       => 'required|string|max:100',
                'description' => 'nullable|string',
                'duration'    => 'required|integer|min:1',
                'year'        => 'required|integer|min:1900|max:2030',
                'is_featured' => 'nullable|in:0,1',
                'thumbnail'   => 'required|file|image|mimes:jpg,jpeg,png,webp|max:4096',
                // dukung banyak ekstensi video
                'video'       => 'required|file|mimes:mp4,mkv,avi,webm,mov,m4v,3gp,ts,flv|max:204800',
            ]);

            $thumbKey = null;
            $videoKey = null;

            if ($request->hasFile('thumbnail')) {
                // simpan sebagai key (private)
                $thumbKey = $request->file('thumbnail')->store('thumbnails', 'b2'); // default private
            }

            if ($request->hasFile('video')) {
                $videoKey = $request->file('video')->store('videos', 'b2'); // default private
            }

            $video = Video::create([
                'title'         => $request->title,
                'genre'         => $request->genre,
                'description'   => $request->description,
                'duration'      => (int) $request->duration,
                'year'          => (int) $request->year,
                'is_featured'   => $request->is_featured == '1',
                // simpan KEY, bukan URL
                'thumbnail_url' => $thumbKey,
                'video_url'     => $videoKey,
            ]);

            CacheService::clearVideoCache($video->id);

            // response ringkas (FE bisa panggil /stream saat butuh)
            return response()->json([
                'success' => true,
                'message' => 'Video berhasil diupload',
                'data' => [
                    'id' => $video->id,
                    'title' => $video->title,
                    'genre' => $video->genre,
                    'description' => $video->description,
                    'duration' => $video->duration,
                    'duration_minutes' => round($video->duration / 60, 1),
                    'duration_formatted' => $this->formatDuration($video->duration ?? 0),
                    'year' => $video->year,
                    'is_featured' => (bool) $video->is_featured,
                    'stream_url' => route('api.video.stream', $video->id),
                    'thumbnail_url' => route('api.video.thumbnail', $video->id),
                    'original_video_url' => $videoKey,
                    'original_thumbnail_url' => $thumbKey,
                    'created_at' => $video->created_at,
                    'updated_at' => $video->updated_at
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Server error'], 500);
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
            $allFiles  = $request->allFiles();

            if (empty($allInputs) && empty($allFiles)) {
                return response()->json(['success' => false, 'message' => 'Tidak ada data untuk diupdate'], 400);
            }

            $rules = [];
            $update = [];
            $fields = ['title','genre','description','duration','year','is_featured'];

            foreach ($fields as $f) {
                if (array_key_exists($f, $allInputs)) {
                    $val = $allInputs[$f];
                    switch ($f) {
                        case 'title':
                            if ($val && trim($val) !== '') { $rules['title']='required|string|max:255'; $update['title']=trim($val); }
                            break;
                        case 'genre':
                            if ($val && trim($val) !== '') { $rules['genre']='required|string|max:100'; $update['genre']=trim($val); }
                            break;
                        case 'description':
                            $rules['description']='nullable|string'; $update['description']=$val ? trim($val) : null;
                            break;
                        case 'duration':
                            if (is_numeric($val) && $val > 0) { $rules['duration']='required|integer|min:1'; $update['duration']=(int)$val; }
                            break;
                        case 'year':
                            if (is_numeric($val) && $val >= 1900 && $val <= 2030) { $rules['year']='required|integer|min:1900|max:2030'; $update['year']=(int)$val; }
                            break;
                        case 'is_featured':
                            $rules['is_featured']='nullable|boolean'; $update['is_featured']=in_array($val, [true,'true',1,'1'], true);
                            break;
                    }
                }
            }

            if ($request->hasFile('thumbnail')) {
                $rules['thumbnail'] = 'file|image|mimes:jpg,jpeg,png,webp|max:4096';
            }
            if ($request->hasFile('video')) {
                $rules['video'] = 'file|mimes:mp4,mkv,avi,webm,mov,m4v,3gp,ts,flv|max:204800';
            }

            if (!empty($rules)) {
                $request->validate($rules);
            }

            if ($request->hasFile('thumbnail')) {
                $this->deleteFileFromB2($video->thumbnail_url);
                $thumbKey = $request->file('thumbnail')->store('thumbnails', 'b2');
                $update['thumbnail_url'] = $thumbKey; // simpan key
            }

            if ($request->hasFile('video')) {
                $this->deleteFileFromB2($video->video_url);
                $videoKey = $request->file('video')->store('videos', 'b2');
                $update['video_url'] = $videoKey; // simpan key
            }

            if (empty($update)) {
                return response()->json(['success' => false, 'message' => 'Data tidak valid'], 400);
            }

            $video->update($update);
            CacheService::clearVideoCache($video->id);

            $fresh = $video->fresh();
            $videoKey = $this->normalizeKey($fresh->video_url ?? '');
            $thumbKey = $this->normalizeKey($fresh->thumbnail_url ?? '');

            return response()->json([
                'success' => true,
                'message' => 'Video berhasil diupdate',
                'data' => [
                    'id' => $fresh->id,
                    'title' => $fresh->title,
                    'genre' => $fresh->genre,
                    'description' => $fresh->description,
                    'duration' => $fresh->duration,
                    'duration_minutes' => round(($fresh->duration ?? 0) / 60, 1),
                    'duration_formatted' => $this->formatDuration($fresh->duration ?? 0),
                    'year' => $fresh->year,
                    'is_featured' => (bool) $fresh->is_featured,
                    'stream_url' => route('api.video.stream', $fresh->id),
                    'thumbnail_url' => route('api.video.thumbnail', $fresh->id),
                    'original_video_url' => $videoKey,
                    'original_thumbnail_url' => $thumbKey,
                    'created_at' => $fresh->created_at,
                    'updated_at' => $fresh->updated_at
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Video tidak ditemukan'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Data tidak valid', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Server error'], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $video = Video::findOrFail($id);

            // Hapus objek di B2 (key atau URL lama)
            $this->deleteFileFromB2($video->video_url);
            $this->deleteFileFromB2($video->thumbnail_url);

            $video->delete();
            CacheService::clearVideoCache($id);

            return response()->json(['success' => true, 'message' => 'Video berhasil dihapus']);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Video tidak ditemukan'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Server error'], 500);
        }
    }

    public function download(Request $request, $id)
    {
        try {
            $video = Video::findOrFail($id);

            if (!$request->user()) {
                return response()->json(['success' => false, 'message' => 'Authentication required'], 401);
            }

            $videoKey = $this->normalizeKey($video->video_url ?? '');
            if (!$videoKey) {
                return response()->json(['success' => false, 'message' => 'Invalid video key'], 400);
            }

            $mime = $this->guessVideoMime($videoKey);
            $fname = Str::slug($video->title ?? 'video') . '.' . strtolower(pathinfo($videoKey, PATHINFO_EXTENSION));

            $url = $this->presigned($videoKey, 600, [
                'ResponseContentType'        => $mime,
                'ResponseContentDisposition' => 'attachment; filename="'.$fname.'"',
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'video_id'      => $video->id,
                    'title'         => $video->title,
                    'download_url'  => $url, // pre-signed (langsung unduh)
                    'thumbnail_url' => route('api.video.thumbnail', $video->id),
                    'duration'      => $video->duration,
                    'genre'         => $video->genre,
                    'year'          => $video->year
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Video tidak ditemukan'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Server error'], 500);
        }
    }

    /** ========== Utils ========== */

    private function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }
}
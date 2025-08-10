<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class VideoStreamController extends Controller
{
    private function getS3Url($filePath, $type = 'video')
    {
        $endpoint = env('B2_ENDPOINT'); // https://s3.us-east-005.backblazeb2.com
        $bucket = env('B2_BUCKET'); // 5-flix

        // Remove leading slash if exists
        $filePath = ltrim($filePath, '/');

        return "{$endpoint}/{$bucket}/{$filePath}";
    }

    /**
     * Stream video file from B2
     */
    public function streamVideo(Request $request, $id)
    {
        try {
            // Get video from cache or database
            $video = Cache::remember("video_stream.{$id}", 1800, function () use ($id) {
                return Video::select(['id', 'title', 'video_url', 'duration', 'genre', 'year'])
                           ->findOrFail($id);
            });

            // Extract file path from stored URL
            $videoUrl = $video->video_url;
            $filePath = $this->extractFilePathFromUrl($videoUrl, 'videos');

            if (!$filePath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid video path'
                ], 400);
            }

            // Generate S3-compatible streaming URL
            $streamUrl = $this->getS3Url($filePath);

            // Get file headers to check if file exists
            $headResponse = Http::withHeaders([
                'Authorization' => $this->getB2AuthHeader()
            ])->head($streamUrl);

            if (!$headResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video file not found'
                ], 404);
            }

            $contentLength = $headResponse->header('Content-Length') ?: 0;
            $contentType = $headResponse->header('Content-Type') ?: 'video/mp4';

            // Handle range requests for video streaming
            $range = $request->header('Range');
            if ($range) {
                return $this->handleRangeRequest($streamUrl, $range, $contentLength, $contentType);
            }

            // Return full video stream
            return $this->streamFullVideo($streamUrl, $contentType, $contentLength);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Video not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    /**
     * Get video thumbnail from B2
     */
    public function getThumbnail($id)
    {
        try {
            // Get video from cache or database
            $video = Cache::remember("video_thumbnail.{$id}", 3600, function () use ($id) {
                return Video::select(['id', 'title', 'thumbnail_url'])
                           ->findOrFail($id);
            });

            // Extract file path from stored URL
            $thumbnailUrl = $video->thumbnail_url;
            $filePath = $this->extractFilePathFromUrl($thumbnailUrl, 'thumbnails');

            if (!$filePath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid thumbnail path'
                ], 400);
            }

            // Generate S3-compatible URL
            $streamUrl = $this->getS3Url($filePath);

            // Get and stream thumbnail
            $response = Http::withHeaders([
                'Authorization' => $this->getB2AuthHeader()
            ])->get($streamUrl);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thumbnail not found'
                ], 404);
            }

            $contentType = $response->header('Content-Type') ?: 'image/jpeg';

            return response($response->body())
                ->header('Content-Type', $contentType)
                ->header('Cache-Control', 'public, max-age=86400')
                ->header('Expires', now()->addDay()->toRfc7231String());

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Video not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    /**
     * Get video information with streaming URLs
     */
    public function getVideoInfo($id)
    {
        try {
            $video = Cache::remember("video_info.{$id}", 1800, function () use ($id) {
                return Video::findOrFail($id);
            });

            // Calculate duration in minutes
            $durationMinutes = round($video->duration / 60, 1);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $video->id,
                    'title' => $video->title,
                    'genre' => $video->genre,
                    'description' => $video->description,
                    'duration' => $video->duration, // seconds
                    'duration_minutes' => $durationMinutes,
                    'duration_formatted' => $this->formatDuration($video->duration),
                    'year' => $video->year,
                    'is_featured' => $video->is_featured,
                    'stream_url' => route('api.video.stream', $id),
                    'thumbnail_url' => route('api.video.thumbnail', $id),
                    'created_at' => $video->created_at,
                    'updated_at' => $video->updated_at
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Video not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    /**
     * Extract file path from stored URL (S3-compatible only)
     */
    private function extractFilePathFromUrl($url, $expectedFolder)
    {
        if (!$url) return null;

        $parsedUrl = parse_url($url);

        // Handle S3-compatible URL format only
        if (isset($parsedUrl['path'])) {
            $filePath = ltrim($parsedUrl['path'], '/');
            $bucketName = env('B2_BUCKET');

            // Remove bucket name from path if present
            if ($bucketName && strpos($filePath, $bucketName . '/') === 0) {
                $filePath = substr($filePath, strlen($bucketName) + 1);
            }

            return $filePath;
        }

        return null;
    }

    /**
     * Generate B2 authorization header
     */
    private function getB2AuthHeader()
    {
        $keyId = env('B2_KEY_ID');
        $applicationKey = env('B2_APPLICATION_KEY');

        return 'Basic ' . base64_encode($keyId . ':' . $applicationKey);
    }

    /**
     * Handle HTTP Range requests for video streaming
     */
    private function handleRangeRequest($streamUrl, $range, $contentLength, $contentType)
    {
        // Parse range header (e.g., "bytes=0-1023")
        if (!preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            return response('Invalid range', 416);
        }

        $start = intval($matches[1]);
        $end = !empty($matches[2]) ? intval($matches[2]) : $contentLength - 1;

        // Validate range
        if ($start >= $contentLength || $end >= $contentLength || $start > $end) {
            return response('Range not satisfiable', 416)
                ->header('Content-Range', "bytes */{$contentLength}");
        }

        // Get partial content from B2
        $response = Http::withHeaders([
            'Authorization' => $this->getB2AuthHeader(),
            'Range' => "bytes={$start}-{$end}"
        ])->get($streamUrl);

        if (!$response->successful()) {
            return response('Error fetching content', 500);
        }

        $partialLength = $end - $start + 1;

        return response($response->body(), 206)
            ->header('Content-Type', $contentType)
            ->header('Content-Length', $partialLength)
            ->header('Content-Range', "bytes {$start}-{$end}/{$contentLength}")
            ->header('Accept-Ranges', 'bytes')
            ->header('Cache-Control', 'no-cache');
    }

    /**
     * Stream full video
     */
    private function streamFullVideo($streamUrl, $contentType, $contentLength)
    {
        $response = Http::withHeaders([
            'Authorization' => $this->getB2AuthHeader()
        ])->get($streamUrl);

        if (!$response->successful()) {
            return response('Video not found', 404);
        }

        return response($response->body())
            ->header('Content-Type', $contentType)
            ->header('Content-Length', $contentLength)
            ->header('Accept-Ranges', 'bytes')
            ->header('Cache-Control', 'no-cache');
    }

    /**
     * Format duration to human readable format
     */
    private function formatDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        } else {
            return sprintf('%02d:%02d', $minutes, $remainingSeconds);
        }
    }
}
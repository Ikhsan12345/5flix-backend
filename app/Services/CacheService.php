<?php

// app/Services/CacheService.php
namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    // Cache keys
    const VIDEOS_ALL = 'videos.all';
    const VIDEO_SINGLE = 'video.';
    const FEATURED_VIDEOS = 'videos.featured';

    // Cache TTL (in seconds)
    const SHORT_TTL = 300;  // 5 minutes
    const MEDIUM_TTL = 1800; // 30 minutes
    const LONG_TTL = 3600;   // 1 hour

    public static function clearVideoCache($videoId = null)
    {
        Cache::forget(self::VIDEOS_ALL);
        Cache::forget(self::FEATURED_VIDEOS);

        if ($videoId) {
            Cache::forget(self::VIDEO_SINGLE . $videoId);
        }
    }

    public static function getVideos()
    {
        return Cache::remember(self::VIDEOS_ALL, self::SHORT_TTL, function () {
            return \App\Models\Video::select([
                'id', 'title', 'genre', 'thumbnail_url',
                'duration', 'year', 'is_featured'
            ])->orderBy('created_at', 'desc')->get();
        });
    }

    public static function getVideo($id)
    {
        return Cache::remember(self::VIDEO_SINGLE . $id, self::MEDIUM_TTL, function () use ($id) {
            return \App\Models\Video::findOrFail($id);
        });
    }

    public static function getFeaturedVideos()
    {
        return Cache::remember(self::FEATURED_VIDEOS, self::LONG_TTL, function () {
            return \App\Models\Video::where('is_featured', true)
                                  ->select(['id', 'title', 'genre', 'thumbnail_url', 'duration', 'year'])
                                  ->get();
        });
    }
}

// config/cache.php - Tambahkan konfigurasi ini jika belum ada
// Gunakan Redis untuk production yang lebih baik

/*
'default' => env('CACHE_STORE', 'redis'),

'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
        'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
    ],

    // Fallback ke database jika Redis tidak tersedia
    'database' => [
        'driver' => 'database',
        'table' => env('DB_CACHE_TABLE', 'cache'),
        'connection' => env('DB_CACHE_CONNECTION'),
    ],
]
*/
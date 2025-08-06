<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
protected $fillable = [
    'title', 'genre', 'thumbnail_url', 'video_url', 'description', 'duration', 'year', 'is_featured'
];

}
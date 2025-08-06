<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $validated = $request->validate([
            'title' => 'required',
            'genre' => 'required',
            'description' => 'nullable',
            'duration' => 'required|integer',
            'year' => 'required|integer',
            'is_featured' => 'boolean',
            'thumbnail' => 'required|file|image|mimes:jpg,jpeg,png',
            'video' => 'required|file|mimes:mp4,mkv,avi',
        ]);

        // Upload thumbnail ke B2
        $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'b2');
        $thumbnailUrl = Storage::disk('b2')->url($thumbnailPath);

        // Upload video ke B2
        $videoPath = $request->file('video')->store('videos', 'b2');
        $videoUrl = Storage::disk('b2')->url($videoPath);

        // Simpan data ke database
        $video = Video::create([
            'title'         => $request->title,
            'genre'         => $request->genre,
            'description'   => $request->description,
            'duration'      => $request->duration,
            'year'          => $request->year,
            'is_featured'   => $request->is_featured ?? false,
            'thumbnail_url' => $thumbnailUrl,
            'video_url'     => $videoUrl,
        ]);

        return response()->json($video, 201);
    }

    // Tampilkan 1 video berdasarkan id
    public function show($id)
    {
        return Video::findOrFail($id);
    }

    // Update data video (dengan upload file opsional)
    public function update(Request $request, $id)
    {
        $video = Video::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required',
            'genre' => 'sometimes|required',
            'description' => 'nullable',
            'duration' => 'sometimes|required|integer',
            'year' => 'sometimes|required|integer',
            'is_featured' => 'sometimes|boolean',
            'thumbnail' => 'sometimes|file|image|mimes:jpg,jpeg,png',
            'video' => 'sometimes|file|mimes:mp4,mkv,avi',
        ]);

        // Update thumbnail jika ada file baru
        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = $request->file('thumbnail')->store('thumbnails', 'b2');
            $validated['thumbnail_url'] = Storage::disk('b2')->url($thumbnailPath);
        }

        // Update video jika ada file baru
        if ($request->hasFile('video')) {
            $videoPath = $request->file('video')->store('videos', 'b2');
            $validated['video_url'] = Storage::disk('b2')->url($videoPath);
        }

        $video->update($validated);
        return response()->json($video);
    }

    // Hapus data video
    public function destroy($id)
    {
        $video = Video::findOrFail($id);
        $video->delete();
        return response()->json(['message' => 'Video berhasil dihapus']);
    }
}
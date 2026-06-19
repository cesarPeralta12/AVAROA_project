<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;

class NewsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (!Session::has('LoggedIn')) {
            return redirect('admin/login')->with('fail', 'Tu sesión expiró. Por favor, inicia sesión nuevamente.');
        }

        $user_session = User::find(Session::get('LoggedIn'));
        $news = News::latest()->paginate(10);
        return view('admin.news.index', compact('news', 'user_session'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        if (!Session::has('LoggedIn')) {
            return redirect('admin/login')->with('fail', 'Tu sesión expiró. Por favor, inicia sesión nuevamente.');
        }

        $user_session = User::find(Session::get('LoggedIn'));
        return view('admin.news.create', compact('user_session'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:text,image,audio,video',
            'content' => 'nullable|string',
            'file' => 'nullable|file|max:2048|mimes:jpeg,png,mp3,mp4', // Handles image, audio, video
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'author' => 'nullable|string|max:255',
        ]);

        $filePath = null;

        // Handle file upload for audio if type is 'audio'
        if ($request->type == 'audio' && $request->hasFile('file')) {
            $audioFile = $request->file('file');
            $audioDestination = 'news/audio'; // Folder to save audio files
            $audioFileName = time() . '-' . \Illuminate\Support\Str::random(10) . '.' . $audioFile->getClientOriginalExtension();
            $audioFile->move(public_path('uploads/' . $audioDestination), $audioFileName);
            $filePath = 'uploads/' . $audioDestination . '/' . $audioFileName;
        }

        // Handle file upload for image and video
        if (($request->type == 'image' || $request->type == 'video') && $request->hasFile('file')) {
            $file = $request->file('file');
            $fileDestination = 'news/' . $request->type; // Folder for image/video
            $fileName = time() . '-' . \Illuminate\Support\Str::random(10) . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/' . $fileDestination), $fileName);
            $filePath = 'uploads/' . $fileDestination . '/' . $fileName;
        }

        // Handle thumbnail upload (this will always be for image)
        $thumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            $thumbnail = $request->file('thumbnail');
            $thumbnailDestination = 'news/thumbnails'; // Folder for thumbnails
            $thumbnailFileName = time() . '-' . \Illuminate\Support\Str::random(10) . '.' . $thumbnail->getClientOriginalExtension();
            $thumbnail->move(public_path('uploads/' . $thumbnailDestination), $thumbnailFileName);
            $thumbnailPath = 'uploads/' . $thumbnailDestination . '/' . $thumbnailFileName;
        }

        // Store the news record
        News::create([
            'title' => $validated['title'],
            'type' => $validated['type'],
            'content' => $validated['content'],
            'file_path' => $filePath,
            'thumbnail' => $thumbnailPath,
            'author' => $validated['author'],
        ]);

        return redirect()->route('news.index')->with('success', 'Noticia creada exitosamente.');
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        if (!Session::has('LoggedIn')) {
            return redirect('admin/login')->with('fail', 'Tu sesión expiró. Por favor, inicia sesión nuevamente.');
        }

        $user_session = User::find(Session::get('LoggedIn'));
        $news = News::findOrFail($id);
        return view('admin.news.edit', compact('news', 'user_session'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $news = News::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:text,image,audio,video',
            'content' => 'nullable|string',
            'file' => 'nullable|file|max:2048|mimes:jpeg,png,mp3,mp4',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'author' => 'nullable|string|max:255',
        ]);

        // Handle file upload for audio if type is 'audio'
        if ($request->type == 'audio' && $request->hasFile('file')) {
            // Delete old audio file if it exists
            if ($news->file_path && file_exists(public_path($news->file_path))) {
                unlink(public_path($news->file_path));
            }

            $audioFile = $request->file('file');
            $audioDestination = 'news/audio'; // Folder to save audio files
            $audioFileName = time() . '-' . \Illuminate\Support\Str::random(10) . '.' . $audioFile->getClientOriginalExtension();
            $audioFile->move(public_path('uploads/' . $audioDestination), $audioFileName);
            $news->file_path = 'uploads/' . $audioDestination . '/' . $audioFileName;
        }

        // Handle file upload for image or video
        if (($request->type == 'image' || $request->type == 'video') && $request->hasFile('file')) {
            // Delete old file if it exists
            if ($news->file_path && file_exists(public_path($news->file_path))) {
                unlink(public_path($news->file_path));
            }

            $file = $request->file('file');
            $fileDestination = 'news/' . $request->type; // Folder for image/video
            $fileName = time() . '-' . \Illuminate\Support\Str::random(10) . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('uploads/' . $fileDestination), $fileName);
            $news->file_path = 'uploads/' . $fileDestination . '/' . $fileName;
        }

        // Handle thumbnail upload
        if ($request->hasFile('thumbnail')) {
            // Delete old thumbnail if it exists
            if ($news->thumbnail && file_exists(public_path($news->thumbnail))) {
                unlink(public_path($news->thumbnail));
            }

            $thumbnail = $request->file('thumbnail');
            $thumbnailDestination = 'news/thumbnails'; // Folder for thumbnails
            $thumbnailFileName = time() . '-' . \Illuminate\Support\Str::random(10) . '.' . $thumbnail->getClientOriginalExtension();
            $thumbnail->move(public_path('uploads/' . $thumbnailDestination), $thumbnailFileName);
            $news->thumbnail = 'uploads/' . $thumbnailDestination . '/' . $thumbnailFileName;
        }

        // Update the record
        $news->update([
            'title' => $validated['title'],
            'type' => $validated['type'],
            'content' => $validated['content'],
            'author' => $validated['author'],
        ]);

        return redirect()->route('news.index')->with('success', 'Noticia actualizada exitosamente.');
    }



    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $news = News::findOrFail($id);

        // Delete file
        if ($news->file_path && file_exists(public_path($news->file_path))) {
            unlink(public_path($news->file_path));
        }

        // Delete thumbnail
        if ($news->thumbnail && file_exists(public_path($news->thumbnail))) {
            unlink(public_path($news->thumbnail));
        }

        $news->delete();

        return response()->json(['success' => true, 'message' => 'Noticia eliminada exitosamente.']);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:news,id',
        ]);

        $newsRecords = News::whereIn('id', $validated['ids'])->get();

        foreach ($newsRecords as $news) {
            if ($news->file_path && file_exists(public_path($news->file_path))) {
                unlink(public_path($news->file_path));
            }

            if ($news->thumbnail && file_exists(public_path($news->thumbnail))) {
                unlink(public_path($news->thumbnail));
            }

            $news->delete();
        }

        return response()->json(['success' => true, 'message' => 'Noticias eliminadas exitosamente.']);
    }
}

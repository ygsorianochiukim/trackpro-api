<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    /**
     * POST /api/admin/media — upload one photo or short video clip.
     *
     * Returns {type, path, url}; the admin form then saves it onto a product or
     * add-on's `media` list. Files never attached are left on disk, so keep the
     * upload → save round trip in one form.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file', 'max:'.Media::MAX_KB,
                'mimes:'.implode(',', [...Media::IMAGE_MIMES, ...Media::VIDEO_MIMES]),
            ],
        ]);

        $file = $request->file('file');
        $type = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image';
        $path = Media::store($file);

        return response()->json(['data' => [
            'type' => $type,
            'path' => $path,
            'url' => Media::url($path),
        ]], 201);
    }
}

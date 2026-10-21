<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Uploaded product / add-on media, under media/ on the media disk —
 * Cloudflare R2 in production (MEDIA_DISK=r2), the local public disk otherwise.
 * Only the relative path is stored, so switching disks never touches the database.
 */
class Media
{
    public const DIR = 'media';

    public const IMAGE_MIMES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const VIDEO_MIMES = ['mp4', 'webm', 'mov'];

    /** Max upload size in KB — enough for a short clip. */
    public const MAX_KB = 51200;

    /** Uploaded names are random and never reused, so browsers / the CDN may cache them for good. */
    public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public static function diskName(): string
    {
        return (string) config('filesystems.media_disk', 'public');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(static::diskName());
    }

    /** Store an upload under media/ and return its path. */
    public static function store(UploadedFile $file): string
    {
        $options = ['disk' => static::diskName()];
        if (static::diskName() !== 'public') {
            $options['CacheControl'] = self::CACHE_CONTROL;
            $options['ContentType'] = $file->getMimeType();
        }

        return $file->store(self::DIR, $options);
    }

    public static function url(string $path): string
    {
        return static::disk()->url($path);
    }

    /** Validation rules for a `media` array on a product / add-on payload. */
    public static function rules(): array
    {
        return [
            'media' => ['nullable', 'array', 'max:12'],
            'media.*.type' => ['required', 'in:image,video'],
            'media.*.path' => ['required', 'string', 'max:255', 'regex:#^'.self::DIR.'/[A-Za-z0-9._-]+$#'],
        ];
    }

    /** A single optional uploaded file path, e.g. a hardware option's image. */
    public static function pathRule(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:#^'.self::DIR.'/[A-Za-z0-9._-]+$#'];
    }

    /** @return string[] */
    public static function paths(array $media): array
    {
        return array_values(array_filter(array_map(fn ($m) => $m['path'] ?? null, $media)));
    }

    public static function delete(array $paths): void
    {
        $paths = array_filter($paths, fn ($p) => str_starts_with($p, self::DIR.'/'));
        if ($paths) {
            static::disk()->delete(array_values($paths));
        }
    }
}

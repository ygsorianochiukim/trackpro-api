<?php

namespace App\Models\Concerns;

use App\Support\Media;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * A `media` JSON column of uploaded photos / video clips.
 *
 * Only {type, path} is stored; `url` is derived on read so the files keep
 * working if the API moves to another domain. Removing an entry (or the row)
 * deletes the file it pointed at.
 */
trait HasMedia
{
    public static function bootHasMedia(): void
    {
        static::updated(function ($model) {
            if (!$model->wasChanged('media')) {
                return;
            }
            $before = Media::paths(json_decode((string) $model->getRawOriginal('media'), true) ?? []);
            $after = Media::paths($model->media ?? []);
            Media::delete(array_diff($before, $after));
        });

        static::deleted(fn ($model) => Media::delete(Media::paths($model->media ?? [])));
    }

    protected function media(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => array_map(
                fn (array $m) => $m + ['url' => Media::url($m['path'])],
                json_decode((string) $value, true) ?? []
            ),
            set: fn (?array $value) => json_encode(array_values(array_map(
                fn (array $m) => ['type' => $m['type'], 'path' => $m['path']],
                $value ?? []
            ))),
        );
    }
}

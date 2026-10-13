<?php

namespace App\Console\Commands;

use App\Support\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\MimeTypes;

/**
 * Copy uploaded media from one disk to another — e.g. the local public disk
 * to Cloudflare R2 when switching MEDIA_DISK. Paths stay the same, so no
 * database change is needed. Files already at the destination are skipped,
 * and nothing is deleted from the source, so it's safe to re-run.
 */
class SyncMedia extends Command
{
    protected $signature = 'media:sync
        {--from=public : Disk to copy from}
        {--to= : Disk to copy to (default: MEDIA_DISK)}
        {--dry-run : List what would be copied without copying}';

    protected $description = 'Copy uploaded product / add-on media (media/*) to another disk, e.g. Cloudflare R2';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: Media::diskName());

        if ($from === $to) {
            $this->error("Source and destination are both [{$to}]. Set MEDIA_DISK=r2 or pass --to=r2.");

            return self::FAILURE;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $files = $source->files(Media::DIR);
        $this->info(count($files)." file(s) in {$from}:".Media::DIR.'/ → '.$to.($this->option('dry-run') ? ' (dry run)' : ''));

        $copied = $skipped = $failed = 0;
        foreach ($files as $path) {
            if ($target->exists($path)) {
                $skipped++;

                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("  would copy {$path}");
                $copied++;

                continue;
            }

            try {
                $stream = $source->readStream($path);
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                $target->writeStream($path, $stream, [
                    'CacheControl' => Media::CACHE_CONTROL,
                    'ContentType' => MimeTypes::getDefault()->getMimeTypes($ext)[0] ?? 'application/octet-stream',
                ]);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $this->line("  copied {$path}");
                $copied++;
            } catch (\Throwable $e) {
                $this->error("  failed {$path}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done — {$copied} copied, {$skipped} already there, {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

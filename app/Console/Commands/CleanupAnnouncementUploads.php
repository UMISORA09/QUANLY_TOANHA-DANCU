<?php

namespace App\Console\Commands;

use App\Services\AnnouncementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupAnnouncementUploads extends Command
{
    protected $signature = 'announcements:cleanup-uploads {--dry-run : List expired staging objects without deleting}';

    protected $description = 'Remove expired announcement staging uploads only';

    public function handle(): int
    {
        $disk = Storage::disk(app(AnnouncementService::class)->disk());
        $count = 0;
        foreach ($disk->allFiles('announcement-staging') as $path) {
            if (! preg_match('~^announcement-staging/([0-9]{10})/[a-f0-9-]{36}/[a-f0-9-]{36}$~D', $path, $match) || (int) $match[1] >= now()->subHour()->timestamp) {
                continue;
            }
            if (! $this->option('dry-run') && ! $disk->delete($path)) {
                $this->error('Could not remove an expired upload.');

                return self::FAILURE;
            }
            $count++;
        }
        $this->info(($this->option('dry-run') ? 'Would remove: ' : 'Removed: ').$count);

        return self::SUCCESS;
    }
}

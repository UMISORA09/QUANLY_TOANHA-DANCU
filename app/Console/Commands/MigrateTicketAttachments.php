<?php

namespace App\Console\Commands;

use App\Models\TicketAttachment;
use App\Services\TicketAttachmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('tickets:migrate-attachments {--apply : Copy and verify files, then update storage metadata} {--remove-public : Remove verified public copies after migration}')]
#[Description('Inspect or migrate legacy Ticket images to private storage')]
class MigrateTicketAttachments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;
        foreach (TicketAttachment::whereNull('storage_disk')->orWhere('storage_disk', 'public')->cursor() as $attachment) {
            $count++;
            $this->line($attachment->id.' '.($this->option('apply') ? 'migrating' : 'dry-run'));
            if (! $this->option('apply')) {
                continue;
            }
            $disk = app(TicketAttachmentService::class)->disk();
            $path = $attachment->storage_path;
            if (! $path || ! str_starts_with($path, 'tickets/') || ! Storage::disk('public')->exists($path)) {
                $this->error('Missing legacy file: '.$attachment->id);

                return self::FAILURE;
            }
            $content = Storage::disk('public')->get($path);
            if (! Storage::disk($disk)->put($path, $content, ['visibility' => 'private']) || ! hash_equals(hash('sha256', $content), hash('sha256', Storage::disk($disk)->get($path)))) {
                $this->error('Verification failed: '.$attachment->id);

                return self::FAILURE;
            }
            DB::transaction(function () use ($attachment, $disk): void {
                TicketAttachment::whereKey($attachment->id)->update(['storage_disk' => $disk, 'file_url' => '']);
            });
            if ($this->option('remove-public') && ! Storage::disk('public')->delete($path)) {
                $this->error('Private copy saved; public cleanup failed: '.$attachment->id);

                return self::FAILURE;
            }
        }
        $this->info('Attachments: '.$count);

        return self::SUCCESS;
    }
}

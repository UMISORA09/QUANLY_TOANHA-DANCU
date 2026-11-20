<?php

namespace App\Console\Commands;

use App\Models\TemporaryRegistration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('temporary-registrations:migrate-cccd {--apply : Di chuyển và xác minh file sang disk local} {--remove-public : Xóa bản sao public sau khi đã xác minh toàn vẹn}')]
#[Description('Di chuyển các tệp ảnh CCCD từ disk public sang disk local (private) và chuẩn hóa URL trong CSDL')]
class MigrateTemporaryRegistrationCccd extends Command
{
    /**
     * Thực thi lệnh di chuyển tệp CCCD
     */
    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $removePublic = (bool) $this->option('remove-public');

        $this->info($apply ? 'BẮT ĐẦU DI CHUYỂN ẢNH CCCD SANG DISK LOCAL...' : 'CHẾ ĐỘ DRY-RUN: Quét tệp và dữ liệu cần di chuyển (không thay đổi)...');

        // 1. Quét tệp vật lý trong storage public
        $publicFiles = Storage::disk('public')->files('temporary_registrations/cccd');
        $fileCount = count($publicFiles);
        $this->line("Tìm thấy {$fileCount} tệp trong 'public/temporary_registrations/cccd'.");

        $migratedFiles = 0;
        foreach ($publicFiles as $filePath) {
            $filename = basename($filePath);
            $this->line("- Tệp: {$filename} [".($apply ? 'DI CHUYỂN' : 'DRY-RUN').']');

            if ($apply) {
                $content = Storage::disk('public')->get($filePath);
                if ($content === null) {
                    $this->error("Không thể đọc tệp nguồn: {$filePath}");

                    return self::FAILURE;
                }

                $sourceHash = hash('sha256', $content);

                // Lưu vào disk local (private)
                $saved = Storage::disk('local')->put($filePath, $content);
                if (! $saved || ! Storage::disk('local')->exists($filePath)) {
                    $this->error("Lưu vào disk local thất bại: {$filePath}");

                    return self::FAILURE;
                }

                // Xác minh toàn vẹn SHA-256
                $destContent = Storage::disk('local')->get($filePath);
                $destHash = hash('sha256', $destContent ?? '');
                if (! hash_equals($sourceHash, $destHash)) {
                    $this->error("Xác minh toàn vẹn thất bại (SHA-256 mismatch): {$filePath}");

                    return self::FAILURE;
                }

                $migratedFiles++;

                // Nếu có cờ --remove-public, xóa tệp nguồn khỏi disk public để chặn triệt để URL static
                if ($removePublic) {
                    if (! Storage::disk('public')->delete($filePath)) {
                        $this->warn("Tệp đã lưu an toàn vào local nhưng xóa bản sao public thất bại: {$filePath}");
                    } else {
                        $this->line('  -> Đã xóa bản sao public để đóng URL static công khai.');
                    }
                }
            }
        }

        // 2. Cập nhật các bản ghi trong cơ sở dữ liệu nếu có đường dẫn tĩnh cũ
        $records = TemporaryRegistration::where(function ($q) {
            $q->where('identity_card_front_url', 'like', '%/storage/temporary_registrations/cccd/%')
                ->orWhere('identity_card_front_url', 'like', 'temporary_registrations/cccd/%')
                ->orWhere('identity_card_back_url', 'like', '%/storage/temporary_registrations/cccd/%')
                ->orWhere('identity_card_back_url', 'like', 'temporary_registrations/cccd/%');
        })->get();

        $recordCount = $records->count();
        $this->line("Tìm thấy {$recordCount} bản ghi CSDL cần chuẩn hóa URL sang endpoint bảo mật.");

        $updatedRecords = 0;
        foreach ($records as $record) {
            $newFront = $record->identity_card_front_url;
            if ($newFront && (str_contains($newFront, '/storage/temporary_registrations/cccd/') || str_starts_with($newFront, 'temporary_registrations/cccd/'))) {
                $newFront = '/api/v1/residents/temporary-registrations/cccd/'.basename($newFront);
            }

            $newBack = $record->identity_card_back_url;
            if ($newBack && (str_contains($newBack, '/storage/temporary_registrations/cccd/') || str_starts_with($newBack, 'temporary_registrations/cccd/'))) {
                $newBack = '/api/v1/residents/temporary-registrations/cccd/'.basename($newBack);
            }

            if ($apply) {
                DB::transaction(function () use ($record, $newFront, $newBack): void {
                    // Cập nhật trực tiếp DB bypass accessor/mutator
                    DB::table('temporary_registrations')
                        ->where('id', $record->id)
                        ->update([
                            'identity_card_front_url' => $newFront,
                            'identity_card_back_url' => $newBack,
                        ]);
                });
                $updatedRecords++;
            }
        }

        $this->info("HOÀN TẤT: {$migratedFiles}/{$fileCount} tệp được di chuyển, {$updatedRecords}/{$recordCount} bản ghi CSDL được chuẩn hóa.");

        return self::SUCCESS;
    }
}

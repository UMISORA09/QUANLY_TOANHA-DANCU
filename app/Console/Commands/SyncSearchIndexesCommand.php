<?php

namespace App\Console\Commands;

use App\Models\AccessCard;
use App\Models\Parcel;
use App\Models\Resident;
use App\Models\TemporaryRegistration;
use App\Models\Vehicle;
use App\Models\VisitorRegistration;
use Illuminate\Console\Command;
use Meilisearch\Client as MeiliClient;
use Throwable;

class SyncSearchIndexesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'search:sync-indexes
                            {--flush : Xóa sạch chỉ mục trước khi nhập lại}
                            {--check-only : Chỉ kiểm tra kết nối và cấu hình chỉ mục}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Đồng bộ cấu hình chỉ mục Meilisearch và nhập dữ liệu hiện có từ MySQL vào Search Engine';

    /**
     * Danh sách model cần index
     *
     * @var array<class-string>
     */
    protected array $models = [
        Resident::class,
        TemporaryRegistration::class,
        Vehicle::class,
        AccessCard::class,
        VisitorRegistration::class,
        Parcel::class,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== SMART CASSAVAS - MEILISEARCH INDEX SYNCHRONIZER ===');

        $host = config('scout.meilisearch.host', env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'));
        $key = config('scout.meilisearch.key', env('MEILISEARCH_KEY'));

        $this->line("• Kết nối Search Engine: <fg=cyan>{$host}</>");

        // 1. Kiểm tra kết nối Meilisearch
        try {
            $client = new MeiliClient($host, $key);
            $health = $client->health();

            if (($health['status'] ?? '') !== 'available') {
                $this->error('Meilisearch phản hồi nhưng trạng thái không sẵn sàng: '.json_encode($health));

                return self::FAILURE;
            }

            $version = $client->version();
            $this->info("✓ Meilisearch sẵn sàng (Phiên bản: {$version['pkgVersion']})");
        } catch (Throwable $e) {
            $this->error('✗ Không thể kết nối đến Meilisearch: '.$e->getMessage());
            $this->warn('Hãy đảm bảo container meilisearch đang chạy: docker compose up -d meilisearch');

            return self::FAILURE;
        }

        if ($this->option('check-only')) {
            $this->info('Chế độ --check-only: Kiểm tra hoàn tất thành công!');

            return self::SUCCESS;
        }

        $isFlush = $this->option('flush');
        $indexSettings = config('scout.meilisearch.index-settings', []);

        // 2. Đồng bộ Index Settings và Dữ liệu cho từng Model
        foreach ($this->models as $modelClass) {
            $instance = new $modelClass;
            $indexName = $instance->searchableAs();
            $this->newLine();
            $this->line("<fg=yellow>▶ Phân hệ: {$indexName} ({$modelClass})</>");

            try {
                $index = $client->index($indexName);

                // Nếu có cờ --flush, xóa chỉ mục hiện tại
                if ($isFlush) {
                    $this->warn("  • Xóa dữ liệu cũ của index '{$indexName}'...");
                    $index->deleteAllDocuments();
                }

                // Cập nhật Settings (searchable, filterable, sortable, typo tolerance)
                if (isset($indexSettings[$modelClass])) {
                    $settings = $indexSettings[$modelClass];
                    $this->line("  • Đồng bộ cấu hình chỉ mục cho '{$indexName}'...");

                    if (isset($settings['filterableAttributes'])) {
                        $index->updateFilterableAttributes($settings['filterableAttributes']);
                    }
                    if (isset($settings['sortableAttributes'])) {
                        $index->updateSortableAttributes($settings['sortableAttributes']);
                    }
                    if (isset($settings['searchableAttributes'])) {
                        $index->updateSearchableAttributes($settings['searchableAttributes']);
                    }
                    if (isset($settings['typoTolerance'])) {
                        $index->updateTypoTolerance($settings['typoTolerance']);
                    }
                    if (isset($settings['nonSeparatorTokens'])) {
                        $index->updateNonSeparatorTokens($settings['nonSeparatorTokens']);
                    }
                }

                // Import dữ liệu hiện tại từ DB vào Search Engine
                $totalRecords = $modelClass::count();
                $this->line("  • Tìm thấy <fg=green>{$totalRecords}</> bản ghi trong CSDL MySQL.");

                if ($totalRecords > 0) {
                    $bar = $this->output->createProgressBar($totalRecords);
                    $bar->start();

                    $modelClass::chunk(200, function ($records) use ($bar) {
                        $records->searchable();
                        $bar->advance($records->count());
                    });

                    $bar->finish();
                    $this->newLine();
                }

                $this->info("  ✓ Hoàn tất chỉ mục '{$indexName}'.");
            } catch (Throwable $e) {
                $this->error("  ✗ Lỗi khi xử lý '{$indexName}': ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info('=== TẤT CẢ CHỈ MỤC ĐÃ ĐƯỢC ĐỒNG BỘ THÀNH CÔNG ===');

        return self::SUCCESS;
    }
}

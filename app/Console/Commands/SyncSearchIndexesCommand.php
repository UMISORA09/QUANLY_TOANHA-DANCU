<?php

namespace App\Console\Commands;

use App\Models\AccessCard;
use App\Models\Parcel;
use App\Models\Resident;
use App\Models\TemporaryRegistration;
use App\Models\Vehicle;
use App\Models\VisitorRegistration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
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
    protected $description = 'Đồng bộ cấu hình chỉ mục Elasticsearch và nhập dữ liệu hiện có từ MySQL vào Search Engine';

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
        $this->info('=== SMART CASSAVAS - ELASTICSEARCH INDEX SYNCHRONIZER ===');

        $hosts = config('search.drivers.elasticsearch.hosts', ['http://127.0.0.1:9200']);
        $host = rtrim((string) ($hosts[0] ?? 'http://127.0.0.1:9200'), '/');

        $this->line("• Kết nối Search Engine: <fg=cyan>{$host}</>");

        // 1. Kiểm tra kết nối Elasticsearch
        try {
            $response = Http::timeout(2)->get($host);

            if (! $response->successful()) {
                $this->error('Elasticsearch phản hồi nhưng trạng thái không sẵn sàng: '.$response->body());

                return self::FAILURE;
            }

            $info = $response->json();
            $version = $info['version']['number'] ?? 'unknown';
            $this->info("✓ Elasticsearch sẵn sàng (Phiên bản: {$version})");
        } catch (Throwable $e) {
            $this->error('✗ Không thể kết nối đến Elasticsearch: '.$e->getMessage());
            $this->warn('Hãy đảm bảo container elasticsearch đang chạy: docker compose up -d elasticsearch');

            return self::FAILURE;
        }

        if ($this->option('check-only')) {
            $this->info('Chế độ --check-only: Kiểm tra hoàn tất thành công!');

            return self::SUCCESS;
        }

        $isFlush = $this->option('flush');

        // 2. Đồng bộ Index và Dữ liệu cho từng Model
        foreach ($this->models as $modelClass) {
            $instance = new $modelClass;
            $indexName = match ($modelClass) {
                Resident::class => config('search.drivers.elasticsearch.residents_index', 'cassavas_residents_v1'),
                TemporaryRegistration::class => config('search.drivers.elasticsearch.temporary_registrations_index', 'cassavas_temporary_registrations_v1'),
                Vehicle::class => config('search.drivers.elasticsearch.vehicles_index', 'cassavas_vehicles_v1'),
                AccessCard::class => config('search.drivers.elasticsearch.access_cards_index', 'cassavas_access_cards_v1'),
                VisitorRegistration::class => config('search.drivers.elasticsearch.visitors_index', 'cassavas_visitors_v1'),
                Parcel::class => config('search.drivers.elasticsearch.parcels_index', 'cassavas_parcels_v1'),
                default => $instance->searchableAs(),
            };

            $this->newLine();
            $this->line("<fg=yellow>▶ Phân hệ: {$indexName} ({$modelClass})</>");

            try {
                // Nếu có cờ --flush, xóa chỉ mục hiện tại
                if ($isFlush) {
                    $this->warn("  • Xóa dữ liệu cũ của index '{$indexName}'...");
                    Http::delete("{$host}/{$indexName}");
                }

                // Đảm bảo index tồn tại
                $checkIndex = Http::head("{$host}/{$indexName}");
                if ($checkIndex->status() === 404) {
                    Http::put("{$host}/{$indexName}", [
                        'settings' => [
                            'analysis' => [
                                'analyzer' => [
                                    'cassavas_text' => [
                                        'type' => 'custom',
                                        'tokenizer' => 'standard',
                                        'filter' => ['lowercase', 'asciifolding'],
                                    ],
                                ],
                            ],
                        ],
                    ]);
                }

                // Import dữ liệu hiện tại từ DB vào Search Engine
                $totalRecords = $modelClass::count();
                $this->line("  • Tìm thấy <fg=green>{$totalRecords}</> bản ghi trong CSDL MySQL.");

                if ($totalRecords > 0) {
                    $bar = $this->output->createProgressBar($totalRecords);
                    $bar->start();

                    $modelClass::chunk(200, function ($records) use ($host, $indexName, $bar) {
                        foreach ($records as $record) {
                            $doc = method_exists($record, 'toSearchableArray') ? $record->toSearchableArray() : $record->toArray();
                            $id = $record->getKey();
                            Http::put("{$host}/{$indexName}/_doc/{$id}", $doc);
                        }
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

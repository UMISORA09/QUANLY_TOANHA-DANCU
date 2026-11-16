<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Invoice;
use App\Models\InvoiceGenerationBatch;
use App\Models\InvoiceItem;
use App\Models\MeterReading;
use App\Models\ServicePricingConfig;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceGenerationService
{
    /**
     * Xem trước dự toán phát hành hóa đơn hàng loạt (Dry-Run / Preview)
     * Không ghi dữ liệu vào database, dùng để kiểm tra trước số liệu.
     *
     * @param  array{
     *     billing_period: string,
     *     block_id?: ?string,
     *     include_previous_debt?: bool,
     *     overwrite_existing?: bool
     * }  $params
     * @return array<string, mixed>
     */
    public function previewBatch(array $params): array
    {
        $billingPeriod = $params['billing_period'];
        $blockId = $params['block_id'] ?? null;
        $includePreviousDebt = (bool) ($params['include_previous_debt'] ?? true);
        $overwriteExisting = (bool) ($params['overwrite_existing'] ?? false);

        $apartments = $this->getTargetApartments($blockId);

        $pricingConfigs = $this->loadPricingConfigs();

        $previewItems = [];
        $totalEstimatedSubtotal = 0.0;
        $totalEstimatedTax = 0.0;
        $totalEstimatedPreviousDebt = 0.0;
        $totalEstimatedAmount = 0.0;

        $existingInvoicesCount = 0;
        $missingElecCount = 0;
        $missingWaterCount = 0;

        foreach ($apartments as $apartment) {
            $existingInvoice = Invoice::where('apartment_id', $apartment->id)
                ->where('billing_period', $billingPeriod)
                ->first();

            $hasExisting = $existingInvoice !== null;
            if ($hasExisting) {
                $existingInvoicesCount++;
            }

            // Tính toán chi tiết các mục dịch vụ
            $calculation = $this->calculateApartmentFees(
                $apartment,
                $billingPeriod,
                $pricingConfigs,
                $includePreviousDebt
            );

            if (! $calculation['has_electricity_reading']) {
                $missingElecCount++;
            }
            if (! $calculation['has_water_reading']) {
                $missingWaterCount++;
            }

            $totalEstimatedSubtotal += $calculation['subtotal_amount'];
            $totalEstimatedTax += $calculation['tax_amount'];
            $totalEstimatedPreviousDebt += $calculation['previous_debt_amount'];
            $totalEstimatedAmount += $calculation['total_amount'];

            $previewItems[] = [
                'apartment_id' => $apartment->id,
                'apartment_number' => $apartment->apartment_number,
                'block_code' => $apartment->block?->block_code ?? 'N/A',
                'block_name' => $apartment->block?->block_name ?? 'Khối N/A',
                'floor_number' => $apartment->floor?->floor_number ?? 'N/A',
                'area_sqm' => (float) ($apartment->net_usable_area_sqm ?: $apartment->gross_floor_area_sqm ?: 70),
                'resident_name' => $this->resolveResidentName($apartment),
                'has_existing_invoice' => $hasExisting,
                'existing_invoice_number' => $existingInvoice?->invoice_number,
                'existing_invoice_status' => $existingInvoice?->status,
                'has_electricity_reading' => $calculation['has_electricity_reading'],
                'has_water_reading' => $calculation['has_water_reading'],
                'items_count' => count($calculation['items']),
                'subtotal_amount' => $calculation['subtotal_amount'],
                'tax_amount' => $calculation['tax_amount'],
                'previous_debt_amount' => $calculation['previous_debt_amount'],
                'total_amount' => $calculation['total_amount'],
                'items_preview' => $calculation['items'],
            ];
        }

        return [
            'billing_period' => $billingPeriod,
            'block_id' => $blockId,
            'total_apartments' => $apartments->count(),
            'existing_invoices_count' => $existingInvoicesCount,
            'eligible_for_generation' => $overwriteExisting ? $apartments->count() : ($apartments->count() - $existingInvoicesCount),
            'missing_electricity_readings' => $missingElecCount,
            'missing_water_readings' => $missingWaterCount,
            'total_estimated_subtotal' => round($totalEstimatedSubtotal, 2),
            'total_estimated_tax' => round($totalEstimatedTax, 2),
            'total_estimated_previous_debt' => round($totalEstimatedPreviousDebt, 2),
            'total_estimated_amount' => round($totalEstimatedAmount, 2),
            'preview_items' => $previewItems,
        ];
    }

    /**
     * Sinh Hóa đơn tự động hàng loạt theo tháng bằng Database Transaction
     *
     * @param  array{
     *     billing_period: string,
     *     block_id?: ?string,
     *     issue_date?: ?string,
     *     due_date?: ?string,
     *     include_previous_debt?: bool,
     *     overwrite_existing?: bool,
     *     notes?: ?string
     * }  $params
     * @return array{
     *     batch: InvoiceGenerationBatch,
     *     total_invoices_created: int,
     *     total_amount_calculated: float,
     *     skipped_count: int,
     *     errors_count: int
     * }
     */
    public function generateBatch(array $params, string $executedByUserId): array
    {
        $billingPeriod = $params['billing_period'];
        $blockId = $params['block_id'] ?? null;
        $issueDate = ! empty($params['issue_date']) ? Carbon::parse($params['issue_date']) : Carbon::now();
        $dueDate = ! empty($params['due_date']) ? Carbon::parse($params['due_date']) : Carbon::now()->addDays(15);
        $includePreviousDebt = (bool) ($params['include_previous_debt'] ?? true);
        $overwriteExisting = (bool) ($params['overwrite_existing'] ?? false);
        $customNotes = $params['notes'] ?? null;

        $apartments = $this->getTargetApartments($blockId);
        $pricingConfigs = $this->loadPricingConfigs();

        $batchNumber = 'INV-BATCH-'.str_replace('-', '', $billingPeriod).'-'.($blockId ? Str::upper(substr($blockId, 0, 8)) : 'ALL').'-'.Str::upper(Str::random(4));

        return DB::transaction(function () use (
            $batchNumber,
            $billingPeriod,
            $blockId,
            $executedByUserId,
            $apartments,
            $pricingConfigs,
            $includePreviousDebt,
            $overwriteExisting,
            $issueDate,
            $dueDate,
            $customNotes
        ) {
            $batch = InvoiceGenerationBatch::create([
                'batch_number' => $batchNumber,
                'billing_period' => $billingPeriod,
                'block_id' => $blockId,
                'executed_by_user_id' => $executedByUserId,
                'total_apartments_processed' => 0,
                'total_invoices_created' => 0,
                'total_amount_calculated' => 0.00,
                'status' => 'RUNNING',
                'error_logs' => [],
                'started_at' => Carbon::now(),
            ]);

            $totalCreated = 0;
            $totalAmount = 0.0;
            $skippedCount = 0;
            $errorLogs = [];

            foreach ($apartments as $apartment) {
                try {
                    $existingInvoice = Invoice::where('apartment_id', $apartment->id)
                        ->where('billing_period', $billingPeriod)
                        ->first();

                    if ($existingInvoice) {
                        if (! $overwriteExisting) {
                            $skippedCount++;

                            continue;
                        }

                        // Nếu ghi đè và hóa đơn cũ chưa thanh toán (chưa có payment), xóa invoice cũ để tạo lại
                        if ($existingInvoice->paid_amount > 0) {
                            $errorLogs[] = [
                                'apartment_id' => $apartment->id,
                                'apartment_number' => $apartment->apartment_number,
                                'message' => "Không thể ghi đè hóa đơn {$existingInvoice->invoice_number} vì đã có giao dịch thanh toán.",
                            ];
                            $skippedCount++;

                            continue;
                        }

                        // Xóa các items cũ và invoice cũ
                        InvoiceItem::where('invoice_id', $existingInvoice->id)->delete();
                        $existingInvoice->forceDelete();
                    }

                    // Xác định resident_user_id
                    $residentUserId = $this->resolveResidentUserId($apartment, $executedByUserId);

                    // Tính toán chi phí
                    $calc = $this->calculateApartmentFees(
                        $apartment,
                        $billingPeriod,
                        $pricingConfigs,
                        $includePreviousDebt
                    );

                    // Sinh mã hóa đơn chuẩn nghiệp vụ
                    $cleanPeriod = str_replace('-', '', $billingPeriod);
                    $blockCode = $apartment->block?->block_code ?? 'BLK';
                    $aptNum = str_replace(['/', ' '], '-', $apartment->apartment_number);
                    $invoiceNumber = "HD-{$cleanPeriod}-{$blockCode}-{$aptNum}";

                    // Nếu mã hóa đơn đã tồn tại trong DB, thêm hậu tố
                    if (Invoice::where('invoice_number', $invoiceNumber)->exists()) {
                        $invoiceNumber .= '-'.Str::upper(Str::random(3));
                    }

                    $invoice = Invoice::create([
                        'invoice_number' => $invoiceNumber,
                        'batch_id' => $batch->id,
                        'apartment_id' => $apartment->id,
                        'resident_user_id' => $residentUserId,
                        'billing_period' => $billingPeriod,
                        'issue_date' => $issueDate->format('Y-m-d'),
                        'due_date' => $dueDate->format('Y-m-d'),
                        'subtotal_amount' => $calc['subtotal_amount'],
                        'tax_amount' => $calc['tax_amount'],
                        'discount_amount' => 0.00,
                        'previous_debt_amount' => $calc['previous_debt_amount'],
                        'total_amount' => $calc['total_amount'],
                        'paid_amount' => 0.00,
                        'remaining_balance' => $calc['total_amount'],
                        'status' => 'ISSUED',
                        'notes' => $customNotes ?: "Hóa đơn dịch vụ căn hộ {$apartment->apartment_number} kỳ {$billingPeriod}",
                    ]);

                    // Tạo các invoice_items
                    foreach ($calc['items'] as $itemData) {
                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'service_code' => $itemData['service_code'],
                            'item_description' => $itemData['item_description'],
                            'meter_reading_id' => $itemData['meter_reading_id'] ?? null,
                            'previous_reading' => $itemData['previous_reading'] ?? null,
                            'current_reading' => $itemData['current_reading'] ?? null,
                            'quantity' => $itemData['quantity'],
                            'unit_name' => $itemData['unit_name'],
                            'unit_price' => $itemData['unit_price'],
                            'amount_before_tax' => $itemData['amount_before_tax'],
                            'vat_percentage' => $itemData['vat_percentage'],
                            'vat_amount' => $itemData['vat_amount'],
                            'environmental_fee_amount' => $itemData['environmental_fee_amount'] ?? 0.00,
                            'total_line_amount' => $itemData['total_line_amount'],
                            'tier_calculation_details' => $itemData['tier_calculation_details'] ?? [],
                        ]);

                        // Khóa bản ghi meter_reading nếu có liên kết
                        if (! empty($itemData['meter_reading_id'])) {
                            MeterReading::where('id', $itemData['meter_reading_id'])
                                ->update(['is_locked_for_billing' => true]);
                        }
                    }

                    $totalCreated++;
                    $totalAmount += $calc['total_amount'];
                } catch (\Throwable $e) {
                    $errorLogs[] = [
                        'apartment_id' => $apartment->id,
                        'apartment_number' => $apartment->apartment_number,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            // Hoàn tất cập nhật Batch
            $batch->update([
                'total_apartments_processed' => $apartments->count(),
                'total_invoices_created' => $totalCreated,
                'total_amount_calculated' => round($totalAmount, 2),
                'status' => count($errorLogs) > 0 && $totalCreated === 0 ? 'FAILED' : 'COMPLETED',
                'error_logs' => $errorLogs,
                'completed_at' => Carbon::now(),
            ]);

            return [
                'batch' => $batch->fresh(['block', 'executedBy']),
                'total_invoices_created' => $totalCreated,
                'total_amount_calculated' => round($totalAmount, 2),
                'skipped_count' => $skippedCount,
                'errors_count' => count($errorLogs),
            ];
        });
    }

    /**
     * Danh sách phân trang các đợt sinh hóa đơn
     *
     * @param  array<string, mixed>  $filters
     */
    public function listBatches(array $filters = []): LengthAwarePaginator
    {
        $query = InvoiceGenerationBatch::with(['block', 'executedBy'])
            ->orderBy('started_at', 'desc');

        if (! empty($filters['billing_period'])) {
            $query->where('billing_period', $filters['billing_period']);
        }

        if (! empty($filters['block_id'])) {
            $query->where('block_id', $filters['block_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = (int) ($filters['per_page'] ?? 15);

        return $query->paginate($perPage);
    }

    /**
     * Chi tiết một đợt sinh hóa đơn và danh sách hóa đơn kèm theo
     *
     * @return array<string, mixed>
     */
    public function getBatchDetail(string $batchId): array
    {
        $batch = InvoiceGenerationBatch::with(['block', 'executedBy'])
            ->findOrFail($batchId);

        $invoices = Invoice::with(['apartment.block', 'residentUser'])
            ->where('batch_id', $batchId)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return [
            'batch' => $batch,
            'invoices' => $invoices,
        ];
    }

    // =========================================================================
    // HELPER METHODS TÍNH TOÁN DỊCH VỤ VÀ CHI PHÍ
    // =========================================================================

    /**
     * Lấy danh sách căn hộ theo tòa/toàn bộ
     *
     * @return Collection<int, Apartment>
     */
    private function getTargetApartments(?string $blockId = null)
    {
        $query = Apartment::with([
            'block',
            'floor',
            'headOfHousehold.user',
            'currentResident',
            'firstResident.user',
            'vehicles' => fn ($q) => $q->where('is_active', true),
        ])->whereNull('deleted_at');

        if ($blockId) {
            $query->where('block_id', $blockId);
        }

        return $query->orderBy('apartment_number', 'asc')->get();
    }

    /**
     * Tải các cấu hình đơn giá dịch vụ đang áp dụng
     *
     * @return array<string, ?ServicePricingConfig>
     */
    private function loadPricingConfigs(): array
    {
        return [
            'ELECTRICITY' => ServicePricingConfig::with('tiers')
                ->where('meter_type', 'ELECTRICITY')
                ->where('is_active', true)
                ->latest('effective_from_date')
                ->first(),

            'WATER' => ServicePricingConfig::with('tiers')
                ->where('meter_type', 'WATER')
                ->where('is_active', true)
                ->latest('effective_from_date')
                ->first(),

            'MANAGEMENT_FEE' => ServicePricingConfig::where('service_code', 'MANAGEMENT_FEE')
                ->where('is_active', true)
                ->latest('effective_from_date')
                ->first(),
        ];
    }

    /**
     * Tính toán toàn bộ các khoản mục phí của 1 căn hộ trong kỳ
     *
     * @param  array<string, ?ServicePricingConfig>  $pricingConfigs
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     subtotal_amount: float,
     *     tax_amount: float,
     *     previous_debt_amount: float,
     *     total_amount: float,
     *     has_electricity_reading: bool,
     *     has_water_reading: bool
     * }
     */
    private function calculateApartmentFees(
        Apartment $apartment,
        string $billingPeriod,
        array $pricingConfigs,
        bool $includePreviousDebt
    ): array {
        $items = [];
        $subtotalAmount = 0.0;
        $taxAmount = 0.0;

        // 1. Tiền Điện
        $elecReading = MeterReading::where('apartment_id', $apartment->id)
            ->where('billing_cycle', $billingPeriod)
            ->whereHas('meter', fn ($q) => $q->where('meter_type', 'ELECTRICITY'))
            ->latest('created_at')
            ->first();

        $hasElectricityReading = $elecReading !== null;

        if ($elecReading && $pricingConfigs['ELECTRICITY']) {
            $elecCalc = $pricingConfigs['ELECTRICITY']->calculateCost((float) $elecReading->consumed_units);

            $items[] = [
                'service_code' => 'ELECTRICITY',
                'item_description' => "Tiền điện sinh hoạt kỳ {$billingPeriod} (Chỉ số: {$elecReading->previous_reading} -> {$elecReading->current_reading})",
                'meter_reading_id' => $elecReading->id,
                'previous_reading' => (float) $elecReading->previous_reading,
                'current_reading' => (float) $elecReading->current_reading,
                'quantity' => (float) $elecReading->consumed_units,
                'unit_name' => 'kWh',
                'unit_price' => $elecCalc['tier_breakdowns'][0]['unit_price'] ?? 1806.00,
                'amount_before_tax' => $elecCalc['subtotal'],
                'vat_percentage' => $elecCalc['vat_percentage'],
                'vat_amount' => $elecCalc['vat_amount'],
                'environmental_fee_amount' => 0.00,
                'total_line_amount' => $elecCalc['total_amount'],
                'tier_calculation_details' => $elecCalc['tier_breakdowns'],
            ];

            $subtotalAmount += $elecCalc['subtotal'];
            $taxAmount += $elecCalc['vat_amount'];
        }

        // 2. Tiền Nước
        $waterReading = MeterReading::where('apartment_id', $apartment->id)
            ->where('billing_cycle', $billingPeriod)
            ->whereHas('meter', fn ($q) => $q->where('meter_type', 'WATER'))
            ->latest('created_at')
            ->first();

        $hasWaterReading = $waterReading !== null;

        if ($waterReading && $pricingConfigs['WATER']) {
            $waterCalc = $pricingConfigs['WATER']->calculateCost((float) $waterReading->consumed_units);

            $items[] = [
                'service_code' => 'WATER',
                'item_description' => "Tiền nước sinh hoạt kỳ {$billingPeriod} (Chỉ số: {$waterReading->previous_reading} -> {$waterReading->current_reading})",
                'meter_reading_id' => $waterReading->id,
                'previous_reading' => (float) $waterReading->previous_reading,
                'current_reading' => (float) $waterReading->current_reading,
                'quantity' => (float) $waterReading->consumed_units,
                'unit_name' => 'm³',
                'unit_price' => $waterCalc['tier_breakdowns'][0]['unit_price'] ?? 7500.00,
                'amount_before_tax' => $waterCalc['subtotal'],
                'vat_percentage' => $waterCalc['vat_percentage'],
                'vat_amount' => $waterCalc['vat_amount'],
                'environmental_fee_amount' => $waterCalc['environmental_fee'],
                'total_line_amount' => $waterCalc['total_amount'],
                'tier_calculation_details' => $waterCalc['tier_breakdowns'],
            ];

            $subtotalAmount += $waterCalc['subtotal'];
            $taxAmount += ($waterCalc['vat_amount'] + $waterCalc['environmental_fee']);
        }

        // 3. Phí Quản Lý Vận Hành Tòa Nhà
        $mgmtFeeFixed = (float) $apartment->monthly_management_fee_fixed;
        $area = (float) ($apartment->net_usable_area_sqm ?: $apartment->gross_floor_area_sqm ?: 70);

        if ($mgmtFeeFixed > 0) {
            $mgmtSubtotal = $mgmtFeeFixed;
            $mgmtVat = round($mgmtSubtotal * 0.10, 2);
            $mgmtTotal = $mgmtSubtotal + $mgmtVat;

            $items[] = [
                'service_code' => 'MANAGEMENT_FEE',
                'item_description' => "Phí quản lý vận hành tòa nhà cố định kỳ {$billingPeriod}",
                'quantity' => 1.0,
                'unit_name' => 'tháng',
                'unit_price' => $mgmtSubtotal,
                'amount_before_tax' => $mgmtSubtotal,
                'vat_percentage' => 10.0,
                'vat_amount' => $mgmtVat,
                'environmental_fee_amount' => 0.00,
                'total_line_amount' => $mgmtTotal,
                'tier_calculation_details' => [],
            ];

            $subtotalAmount += $mgmtSubtotal;
            $taxAmount += $mgmtVat;
        } else {
            $unitPrice = $pricingConfigs['MANAGEMENT_FEE']
                ? (float) $pricingConfigs['MANAGEMENT_FEE']->fixed_unit_price
                : 12000.00;

            $mgmtSubtotal = round($area * $unitPrice, 2);
            $vatPct = $pricingConfigs['MANAGEMENT_FEE']
                ? (float) $pricingConfigs['MANAGEMENT_FEE']->vat_percentage
                : 10.0;
            $mgmtVat = round($mgmtSubtotal * ($vatPct / 100), 2);
            $mgmtTotal = $mgmtSubtotal + $mgmtVat;

            $items[] = [
                'service_code' => 'MANAGEMENT_FEE',
                'item_description' => "Phí quản lý vận hành tòa nhà ({$area} m²) kỳ {$billingPeriod}",
                'quantity' => $area,
                'unit_name' => 'm²',
                'unit_price' => $unitPrice,
                'amount_before_tax' => $mgmtSubtotal,
                'vat_percentage' => $vatPct,
                'vat_amount' => $mgmtVat,
                'environmental_fee_amount' => 0.00,
                'total_line_amount' => $mgmtTotal,
                'tier_calculation_details' => [],
            ];

            $subtotalAmount += $mgmtSubtotal;
            $taxAmount += $mgmtVat;
        }

        // 4. Phí Gửi Xe
        if ($apartment->relationLoaded('vehicles') && $apartment->vehicles->isNotEmpty()) {
            foreach ($apartment->vehicles as $vehicle) {
                $category = Str::upper($vehicle->vehicle_category ?? 'MOTORBIKE');
                $plate = $vehicle->license_plate ?: 'Không biển';

                $parkingFee = (float) $vehicle->monthly_parking_fee;
                if ($parkingFee <= 0) {
                    $parkingFee = match ($category) {
                        'CAR', 'O_TO' => 1200000.00,
                        'MOTORBIKE', 'XE_MAY' => 120000.00,
                        'ELECTRIC_BIKE', 'XE_DAP_DIEN' => 80000.00,
                        default => 50000.00,
                    };
                }

                $parkingVat = round($parkingFee * 0.10, 2);
                $parkingTotal = $parkingFee + $parkingVat;

                $items[] = [
                    'service_code' => 'PARKING_FEE',
                    'item_description' => "Phí giữ xe ({$category} - {$plate}) kỳ {$billingPeriod}",
                    'quantity' => 1.0,
                    'unit_name' => 'tháng/xe',
                    'unit_price' => $parkingFee,
                    'amount_before_tax' => $parkingFee,
                    'vat_percentage' => 10.0,
                    'vat_amount' => $parkingVat,
                    'environmental_fee_amount' => 0.00,
                    'total_line_amount' => $parkingTotal,
                    'tier_calculation_details' => [],
                ];

                $subtotalAmount += $parkingFee;
                $taxAmount += $parkingVat;
            }
        }

        // 5. Nợ cũ kỳ trước
        $previousDebtAmount = 0.0;
        if ($includePreviousDebt) {
            $previousDebtAmount = (float) Invoice::where('apartment_id', $apartment->id)
                ->where('billing_period', '<', $billingPeriod)
                ->where('remaining_balance', '>', 0)
                ->where('status', '!=', 'CANCELLED')
                ->sum('remaining_balance');
        }

        $totalAmount = round($subtotalAmount + $taxAmount + $previousDebtAmount, 2);

        return [
            'items' => $items,
            'subtotal_amount' => round($subtotalAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'previous_debt_amount' => round($previousDebtAmount, 2),
            'total_amount' => $totalAmount,
            'has_electricity_reading' => $hasElectricityReading,
            'has_water_reading' => $hasWaterReading,
        ];
    }

    /**
     * Xác định resident_user_id cho hóa đơn căn hộ
     */
    private function resolveResidentUserId(Apartment $apartment, string $fallbackUserId): string
    {
        // 1. Cư dân chủ hộ
        if ($apartment->headOfHousehold?->user_id) {
            return (string) $apartment->headOfHousehold->user_id;
        }

        // 2. Đại diện hiện tại của căn hộ
        if ($apartment->current_resident_user_id) {
            return (string) $apartment->current_resident_user_id;
        }

        // 3. Cư dân đầu tiên có user
        if ($apartment->firstResident?->user_id) {
            return (string) $apartment->firstResident->user_id;
        }

        // 4. Fallback về User đầu tiên trong hệ thống hoặc người thực thi
        $firstUser = User::first();

        return $firstUser ? (string) $firstUser->id : $fallbackUserId;
    }

    /**
     * Lấy tên đại diện cư dân hiển thị
     */
    private function resolveResidentName(Apartment $apartment): string
    {
        if ($apartment->headOfHousehold?->user?->full_name) {
            return $apartment->headOfHousehold->user->full_name;
        }

        if ($apartment->currentResident?->full_name) {
            return $apartment->currentResident->full_name;
        }

        if ($apartment->firstResident?->user?->full_name) {
            return $apartment->firstResident->user->full_name;
        }

        return 'Đại diện Căn Hộ';
    }
}

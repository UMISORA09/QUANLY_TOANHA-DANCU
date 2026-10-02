<?php

namespace App\Services;

use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VehicleService
{
    /**
     * Lấy cấu hình đơn giá gửi xe theo danh mục phương tiện từ bảng service_pricing_configs
     */
    public function getPricingForCategory(string $category): array
    {
        $category = strtoupper(trim($category));
        $serviceCode = match ($category) {
            'CAR' => 'PARKING_CAR',
            default => 'PARKING_MOTORBIKE',
        };

        $config = DB::table('service_pricing_configs')
            ->where('service_code', $serviceCode)
            ->where('is_active', 1)
            ->first();

        if ($config) {
            return [
                'service_code' => $config->service_code,
                'service_name' => $config->service_name,
                'monthly_parking_fee' => (float) $config->fixed_unit_price,
                'vat_percentage' => (float) $config->vat_percentage,
                'unit_name' => $config->unit_name ?? 'xe/tháng',
            ];
        }

        // Giá mặc định nếu chưa khởi tạo cấu hình
        $fallbackPrice = ($category === 'CAR') ? 1500000.00 : 120000.00;

        return [
            'service_code' => $serviceCode,
            'service_name' => ($category === 'CAR') ? 'Phí Trông Giữ Ô Tô Tháng' : 'Phí Trông Giữ Xe Máy Tháng',
            'monthly_parking_fee' => $fallbackPrice,
            'vat_percentage' => 10.00,
            'unit_name' => 'xe/tháng',
        ];
    }

    /**
     * Lấy toàn bộ danh mục cấu hình biểu phí giữ xe hiện hành
     */
    public function getAllParkingPricingConfigs(): array
    {
        $configs = DB::table('service_pricing_configs')
            ->whereIn('service_code', ['PARKING_MOTORBIKE', 'PARKING_CAR'])
            ->where('is_active', 1)
            ->get();

        if ($configs->isEmpty()) {
            return [
                'MOTORBIKE' => $this->getPricingForCategory('MOTORBIKE'),
                'CAR' => $this->getPricingForCategory('CAR'),
            ];
        }

        $result = [];
        foreach ($configs as $config) {
            $catKey = ($config->service_code === 'PARKING_CAR') ? 'CAR' : 'MOTORBIKE';
            $result[$catKey] = [
                'service_code' => $config->service_code,
                'service_name' => $config->service_name,
                'monthly_parking_fee' => (float) $config->fixed_unit_price,
                'vat_percentage' => (float) $config->vat_percentage,
                'unit_name' => $config->unit_name,
            ];
        }

        return $result;
    }

    /**
     * Danh sách phương tiện có tìm kiếm, lọc và phân trang
     */
    public function listVehicles(array $filters = []): LengthAwarePaginator
    {
        $query = Vehicle::query()
            ->with([
                'apartment',
                'owner:id,full_name,email,phone_number',
                'approver:id,full_name',
            ]);

        // 1. Tìm kiếm theo biển số, hãng xe, model, tên cư dân, số căn hộ
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('license_plate', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('registration_certificate_number', 'like', "%{$search}%")
                    ->orWhereHas('owner', function ($userQuery) use ($search) {
                        $userQuery->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('apartment', function ($aptQuery) use ($search) {
                        $aptQuery->where('apartment_number', 'like', "%{$search}%");
                    });
            });
        }

        // 2. Lọc theo loại phương tiện
        if (! empty($filters['vehicle_category'])) {
            $query->where('vehicle_category', strtoupper(trim($filters['vehicle_category'])));
        }

        // 3. Lọc theo căn hộ
        if (! empty($filters['apartment_id'])) {
            $query->where('apartment_id', $filters['apartment_id']);
        }

        // 4. Lọc theo chủ xe
        if (! empty($filters['owner_user_id'])) {
            $query->where('owner_user_id', $filters['owner_user_id']);
        }

        // 5. Lọc theo trạng thái hoạt động
        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $isActive = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        // 6. Lọc theo trạng thái duyệt
        if (! empty($filters['approval_status'])) {
            if ($filters['approval_status'] === 'APPROVED') {
                $query->whereNotNull('approved_by');
            } elseif ($filters['approval_status'] === 'PENDING') {
                $query->whereNull('approved_by');
            }
        }

        // 7. Sắp xếp
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['created_at', 'license_plate', 'monthly_parking_fee', 'vehicle_category', 'brand'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    /**
     * Lấy chi tiết phương tiện
     */
    public function getVehicleById(string $id): Vehicle
    {
        $vehicle = Vehicle::with([
            'apartment',
            'owner:id,full_name,email,phone_number',
            'approver:id,full_name',
        ])->find($id);

        if (! $vehicle) {
            throw new VehicleNotFoundException("Không tìm thấy phương tiện với mã ID: {$id}");
        }

        return $vehicle;
    }

    /**
     * Đăng ký phương tiện mới và tự động tích hợp đẩy phí sang Module Hóa Đơn trong DB Transaction
     */
    public function createVehicle(array $data, ?string $adminUserId = null): array
    {
        // 1. Chuẩn hóa biển số
        $licensePlate = strtoupper(trim($data['license_plate']));
        $data['license_plate'] = $licensePlate;

        // 2. Tự động lấy phí từ cấu hình pricing nếu chưa nhập hoặc bằng 0
        $category = strtoupper(trim($data['vehicle_category'] ?? 'MOTORBIKE'));
        $data['vehicle_category'] = $category;

        if (! isset($data['monthly_parking_fee']) || (float) $data['monthly_parking_fee'] <= 0) {
            $pricing = $this->getPricingForCategory($category);
            $data['monthly_parking_fee'] = $pricing['monthly_parking_fee'];
        }

        // 3. Xử lý trạng thái duyệt ban đầu
        if ($adminUserId && ! isset($data['approved_by'])) {
            $data['approved_by'] = $adminUserId;
            $data['approved_at'] = now();
        }

        if (! isset($data['is_active'])) {
            $data['is_active'] = true;
        }

        // 4. Thực thi trong DB Transaction bảo đảm toàn vẹn
        return DB::transaction(function () use ($data) {
            try {
                $vehicle = Vehicle::create($data);
            } catch (QueryException $e) {
                // Kiểm tra lỗi trùng khóa unique biển số (MySQL error code 1062)
                if ($e->errorInfo[1] === 1062 || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'license_plate')) {
                    throw new VehicleConflictException("Biển số xe '{$data['license_plate']}' đã tồn tại trong hệ thống.", 409);
                }
                throw $e;
            }

            // Đẩy phí gửi xe vào hóa đơn kỳ hiện tại
            $invoiceSync = $this->syncVehicleParkingFeeToInvoice($vehicle);

            return [
                'vehicle' => $vehicle->fresh(['apartment', 'owner', 'approver']),
                'invoice_sync' => $invoiceSync,
            ];
        });
    }

    /**
     * Cập nhật thông tin phương tiện
     */
    public function updateVehicle(string $id, array $data): Vehicle
    {
        $vehicle = Vehicle::find($id);
        if (! $vehicle) {
            throw new VehicleNotFoundException('Phương tiện không tồn tại hoặc đã bị xóa.');
        }

        $oldLicensePlate = $vehicle->license_plate;

        if (! empty($data['license_plate'])) {
            $data['license_plate'] = strtoupper(trim($data['license_plate']));
        }

        // Nếu thay đổi loại phương tiện mà không chỉ định phí mới -> tự cập nhật theo bảng giá
        if (! empty($data['vehicle_category']) && $data['vehicle_category'] !== $vehicle->vehicle_category && empty($data['monthly_parking_fee'])) {
            $pricing = $this->getPricingForCategory($data['vehicle_category']);
            $data['monthly_parking_fee'] = $pricing['monthly_parking_fee'];
        }

        try {
            $vehicle->update($data);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] === 1062 || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'license_plate')) {
                throw new VehicleConflictException("Biển số xe '{$data['license_plate']}' đã được đăng ký bởi phương tiện khác.", 409);
            }
            throw $e;
        }

        // Cập nhật mục phí trong hóa đơn kỳ hiện hành nếu chưa thanh toán
        $currentPeriod = Carbon::now()->format('Y-m');
        $invoice = DB::table('invoices')
            ->where('apartment_id', $vehicle->apartment_id)
            ->where('billing_period', $currentPeriod)
            ->where('status', '!=', 'PAID')
            ->whereNull('deleted_at')
            ->first();

        if ($invoice) {
            $category = strtoupper($vehicle->vehicle_category);
            $serviceCode = ($category === 'CAR') ? 'PARKING_CAR' : 'PARKING_MOTORBIKE';
            $categoryLabel = ($category === 'CAR') ? 'ô tô' : 'xe máy';
            $newItemDescription = "Phí gửi xe {$categoryLabel} - {$vehicle->license_plate}";

            $pricing = $this->getPricingForCategory($category);
            $vatPct = $pricing['vat_percentage'];
            $unitPrice = (float) $vehicle->monthly_parking_fee;
            $amountBeforeTax = $unitPrice;
            $vatAmount = round($amountBeforeTax * ($vatPct / 100), 2);
            $totalLineAmount = $amountBeforeTax + $vatAmount;

            $updatedCount = DB::table('invoice_items')
                ->where('invoice_id', $invoice->id)
                ->where(function ($q) use ($oldLicensePlate, $vehicle) {
                    $q->where('item_description', 'like', "%{$oldLicensePlate}%")
                        ->orWhere('item_description', 'like', "%{$vehicle->license_plate}%");
                })
                ->update([
                    'service_code' => $serviceCode,
                    'item_description' => $newItemDescription,
                    'unit_price' => $unitPrice,
                    'amount_before_tax' => $amountBeforeTax,
                    'vat_percentage' => $vatPct,
                    'vat_amount' => $vatAmount,
                    'total_line_amount' => $totalLineAmount,
                ]);

            if ($updatedCount > 0) {
                $totals = DB::table('invoice_items')
                    ->where('invoice_id', $invoice->id)
                    ->selectRaw('SUM(amount_before_tax) as subtotal, SUM(vat_amount) as tax, SUM(total_line_amount) as total')
                    ->first();

                $subtotal = (float) ($totals->subtotal ?? 0.00);
                $tax = (float) ($totals->tax ?? 0.00);
                $total = (float) ($totals->total ?? 0.00);

                DB::table('invoices')
                    ->where('id', $invoice->id)
                    ->update([
                        'subtotal_amount' => $subtotal,
                        'tax_amount' => $tax,
                        'total_amount' => $total,
                        'remaining_balance' => DB::raw('total_amount - paid_amount'),
                        'updated_at' => Carbon::now(),
                    ]);
            }
        }

        return $vehicle->fresh(['apartment', 'owner', 'approver']);
    }

    /**
     * Xóa mềm phương tiện (bảo toàn lịch sử hóa đơn tài chính)
     */
    public function deleteVehicle(string $id): bool
    {
        $vehicle = Vehicle::find($id);
        if (! $vehicle) {
            throw new VehicleNotFoundException('Phương tiện không tồn tại hoặc đã bị xóa.');
        }

        return (bool) $vehicle->delete();
    }

    /**
     * Bật / tắt trạng thái kích hoạt của phương tiện
     */
    public function toggleActive(string $id): Vehicle
    {
        $vehicle = Vehicle::find($id);
        if (! $vehicle) {
            throw new VehicleNotFoundException('Phương tiện không tồn tại hoặc đã bị xóa.');
        }

        $vehicle->is_active = ! $vehicle->is_active;
        $vehicle->save();

        return $vehicle->fresh(['apartment', 'owner', 'approver']);
    }

    /**
     * Phê duyệt đăng ký phương tiện
     */
    public function approveVehicle(string $id, string $approvedByUserId): Vehicle
    {
        $vehicle = Vehicle::find($id);
        if (! $vehicle) {
            throw new VehicleNotFoundException('Phương tiện không tồn tại hoặc đã bị xóa.');
        }

        $vehicle->approved_by = $approvedByUserId;
        $vehicle->approved_at = now();
        $vehicle->is_active = true;
        $vehicle->save();

        // Tự động đẩy phí vào kỳ hóa đơn hiện tại khi duyệt xe
        $this->syncVehicleParkingFeeToInvoice($vehicle);

        return $vehicle->fresh(['apartment', 'owner', 'approver']);
    }

    /**
     * Đẩy phí gửi xe vào quy trình Hóa Đơn hiện hành (IDEMPOTENT - Không bao giờ ghi trùng phí)
     */
    public function syncVehicleParkingFeeToInvoice(Vehicle $vehicle, ?string $billingPeriod = null): array
    {
        // 1. Chỉ phát sinh phí nếu phương tiện đang hoạt động và có phí > 0
        if (! $vehicle->is_active || (float) $vehicle->monthly_parking_fee <= 0) {
            return [
                'synced' => false,
                'reason' => 'INACTIVE_OR_ZERO_FEE',
                'message' => 'Phương tiện đang ngưng hoạt động hoặc mức phí bằng 0.',
            ];
        }

        $billingPeriod = $billingPeriod ?: Carbon::now()->format('Y-m');

        // Xác định loại phí và mã dịch vụ theo cấu hình hệ thống
        $category = strtoupper($vehicle->vehicle_category);
        $serviceCode = ($category === 'CAR') ? 'PARKING_CAR' : 'PARKING_MOTORBIKE';
        $categoryLabel = ($category === 'CAR') ? 'ô tô' : 'xe máy';
        $itemDescription = "Phí gửi xe {$categoryLabel} - {$vehicle->license_plate}";

        // Tra cứu đơn giá và thuế VAT từ cấu hình
        $pricing = $this->getPricingForCategory($category);
        $vatPct = $pricing['vat_percentage'];
        $unitPrice = (float) $vehicle->monthly_parking_fee;
        $amountBeforeTax = $unitPrice;
        $vatAmount = round($amountBeforeTax * ($vatPct / 100), 2);
        $totalLineAmount = $amountBeforeTax + $vatAmount;

        // 2. Tìm hoặc tạo Hóa đơn (Invoices) cho Căn hộ trong kỳ thanh toán
        $invoice = DB::table('invoices')
            ->where('apartment_id', $vehicle->apartment_id)
            ->where('billing_period', $billingPeriod)
            ->whereNull('deleted_at')
            ->first();

        $invoiceId = null;

        if (! $invoice) {
            $invoiceId = (string) Str::uuid();
            $periodClean = str_replace('-', '', $billingPeriod);
            $invoiceNumber = "INV-{$periodClean}-".strtoupper(Str::random(6));

            $now = Carbon::now();
            $issueDate = $now->copy()->startOfMonth()->toDateString();
            $dueDate = $now->copy()->startOfMonth()->addDays(15)->toDateString();

            DB::table('invoices')->insert([
                'id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'apartment_id' => $vehicle->apartment_id,
                'resident_user_id' => $vehicle->owner_user_id,
                'billing_period' => $billingPeriod,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'subtotal_amount' => 0.00,
                'tax_amount' => 0.00,
                'discount_amount' => 0.00,
                'previous_debt_amount' => 0.00,
                'total_amount' => 0.00,
                'paid_amount' => 0.00,
                'remaining_balance' => 0.00,
                'status' => 'ISSUED',
                'notes' => "Hóa đơn dịch vụ và gửi xe chu kỳ {$billingPeriod}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $invoiceId = $invoice->id;
        }

        // 3. IDEMPOTENCY CHECK: Kiểm tra xem biển số này đã có phí trong hóa đơn kỳ này chưa
        $existingItem = DB::table('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->where(function ($q) use ($vehicle) {
                $q->where('item_description', 'like', "%{$vehicle->license_plate}%");
            })
            ->first();

        if ($existingItem) {
            return [
                'synced' => false,
                'reason' => 'ALREADY_EXISTS',
                'message' => "Phí gửi xe cho biển số {$vehicle->license_plate} đã được ghi nhận trong hóa đơn kỳ {$billingPeriod}.",
                'invoice_id' => $invoiceId,
                'item_id' => $existingItem->id,
                'total_amount' => (float) $existingItem->total_line_amount,
            ];
        }

        // 4. Thêm mục phí gửi xe vào hóa đơn
        $itemId = (string) Str::uuid();
        DB::table('invoice_items')->insert([
            'id' => $itemId,
            'invoice_id' => $invoiceId,
            'service_code' => $serviceCode,
            'item_description' => $itemDescription,
            'quantity' => 1.00,
            'unit_name' => $pricing['unit_name'],
            'unit_price' => $unitPrice,
            'amount_before_tax' => $amountBeforeTax,
            'vat_percentage' => $vatPct,
            'vat_amount' => $vatAmount,
            'environmental_fee_amount' => 0.00,
            'total_line_amount' => $totalLineAmount,
            'tier_calculation_details' => '[]',
            'created_at' => Carbon::now(),
        ]);

        // 5. Cập nhật tổng số tiền trong bảng hóa đơn
        $totals = DB::table('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->selectRaw('SUM(amount_before_tax) as subtotal, SUM(vat_amount) as tax, SUM(total_line_amount) as total')
            ->first();

        $subtotal = (float) ($totals->subtotal ?? 0.00);
        $tax = (float) ($totals->tax ?? 0.00);
        $total = (float) ($totals->total ?? 0.00);

        DB::table('invoices')
            ->where('id', $invoiceId)
            ->update([
                'subtotal_amount' => $subtotal,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'remaining_balance' => DB::raw('total_amount - paid_amount'),
                'updated_at' => Carbon::now(),
            ]);

        return [
            'synced' => true,
            'invoice_id' => $invoiceId,
            'item_id' => $itemId,
            'service_code' => $serviceCode,
            'item_description' => $itemDescription,
            'amount_before_tax' => $amountBeforeTax,
            'vat_amount' => $vatAmount,
            'total_line_amount' => $totalLineAmount,
            'billing_period' => $billingPeriod,
            'message' => "Đã ghi nhận phí gửi xe vào hóa đơn kỳ {$billingPeriod} thành công.",
        ];
    }

    /**
     * Lấy danh sách căn hộ cho bộ lọc
     */
    public function getApartmentsForFilter(): array
    {
        return DB::table('apartments')
            ->select('id', 'apartment_number')
            ->whereNull('deleted_at')
            ->orderBy('apartment_number')
            ->get()
            ->toArray();
    }
}

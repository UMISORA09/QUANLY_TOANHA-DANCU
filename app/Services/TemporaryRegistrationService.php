<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\TemporaryRegistration;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TemporaryRegistrationService
{
    /**
     * Danh sách hồ sơ tạm trú / tạm vắng kèm bộ lọc, tìm kiếm và phân trang
     *
     * @param  array<string, mixed>  $params
     */
    public function list(array $params = []): LengthAwarePaginator
    {
        $search = trim((string) ($params['search'] ?? ''));
        $apartmentId = trim((string) ($params['apartment_id'] ?? ''));
        $residentId = trim((string) ($params['resident_id'] ?? ''));
        $type = trim((string) ($params['registration_type'] ?? ''));
        $status = trim((string) ($params['police_status'] ?? ''));
        $startDate = trim((string) ($params['start_date'] ?? ''));
        $endDate = trim((string) ($params['end_date'] ?? ''));
        $sortBy = (string) ($params['sort_by'] ?? 'created_at');
        $sortOrder = strtolower((string) ($params['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) ($params['limit'] ?? 15), 5), 100);

        $query = TemporaryRegistration::query()->with([
            'resident.user:id,username,full_name,phone_number,email,national_id_number,avatar_url,gender,date_of_birth,status',
            'apartment:id,apartment_number,block_id,floor_id,room_type,status',
            'reviewer:id,username,full_name,phone_number,email',
        ]);

        // 1. Tìm kiếm theo Tên cư dân, Số điện thoại, CCCD/CMND, Số căn hộ, Mã hồ sơ Công An, Lý do
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('resident.user', function ($uq) use ($search) {
                    $uq->where('full_name', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%")
                        ->orWhere('national_id_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('apartment', function ($aq) use ($search) {
                    $aq->where('apartment_number', 'like', "%{$search}%");
                })->orWhere('police_reference_code', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        // 2. Lọc theo căn hộ
        if ($apartmentId !== '') {
            $query->where('apartment_id', $apartmentId);
        }

        // 3. Lọc theo cư dân
        if ($residentId !== '') {
            $query->where('resident_id', $residentId);
        }

        // 4. Lọc theo loại đăng ký (TEMPORARY_STAY / TEMPORARY_ABSENCE)
        if ($type !== '') {
            $query->where('registration_type', strtoupper($type));
        }

        // 5. Lọc theo trạng thái công an
        if ($status !== '') {
            $query->where('police_status', strtoupper($status));
        }

        // 6. Lọc theo khoảng thời gian
        if ($startDate !== '') {
            $query->whereDate('start_date', '>=', $startDate);
        }
        if ($endDate !== '') {
            $query->whereDate('end_date', '<=', $endDate);
        }

        // 7. Sắp xếp
        if (in_array($sortBy, ['created_at', 'start_date', 'end_date', 'police_status'], true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query->paginate($perPage);
    }

    /**
     * Chi tiết một hồ sơ tạm trú / tạm vắng
     */
    public function getById(string $id): TemporaryRegistration
    {
        $registration = TemporaryRegistration::with([
            'resident.user:id,username,full_name,phone_number,email,national_id_number,avatar_url,gender,date_of_birth,status',
            'apartment:id,apartment_number,block_id,floor_id,room_type,status',
            'reviewer:id,username,full_name,phone_number,email',
        ])->find($id);

        if (! $registration) {
            throw new TemporaryRegistrationNotFoundException('Hồ sơ tạm trú / tạm vắng không tồn tại trong hệ thống.');
        }

        return $registration;
    }

    /**
     * Tạo hồ sơ đăng ký tạm trú / tạm vắng mới
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): TemporaryRegistration
    {
        $residentId = $data['resident_id'];
        $apartmentId = $data['apartment_id'];

        // 1. Kiểm tra cư dân tồn tại
        $resident = Resident::find($residentId);
        if (! $resident) {
            throw ValidationException::withMessages([
                'resident_id' => 'Cư dân được chọn không tồn tại trong hệ thống.',
            ]);
        }

        // 2. Kiểm tra căn hộ tồn tại
        $apartment = Apartment::find($apartmentId);
        if (! $apartment) {
            throw ValidationException::withMessages([
                'apartment_id' => 'Căn hộ được chọn không tồn tại trong hệ thống.',
            ]);
        }

        // 3. Kiểm tra tính phù hợp giữa cư dân và căn hộ
        if ($resident->apartment_id !== $apartmentId) {
            throw ValidationException::withMessages([
                'resident_id' => 'Cư dân được chọn không thuộc căn hộ này.',
            ]);
        }

        // 4. Các trường do hệ thống kiểm soát khi tạo mới
        $data['police_status'] = 'PENDING_POLICE_SUBMISSION';
        $data['reviewed_by'] = null;
        $data['reviewed_at'] = null;

        return DB::transaction(function () use ($data) {
            $registration = TemporaryRegistration::create($data);

            return $registration->load([
                'resident.user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
                'apartment:id,apartment_number,block_id',
                'reviewer:id,username,full_name',
            ]);
        });
    }

    /**
     * Cập nhật thông tin hồ sơ khi trạng thái cho phép (kèm Optimistic Locking)
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TemporaryRegistrationNotFoundException|TemporaryRegistrationConflictException
     */
    public function update(string $id, array $data): TemporaryRegistration
    {
        return DB::transaction(function () use ($id, $data) {
            $locked = TemporaryRegistration::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new TemporaryRegistrationNotFoundException('Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.');
            }

            // CHỈ cho phép sửa hồ sơ khi ở trạng thái PENDING hoặc PENDING_POLICE_SUBMISSION
            if (! in_array($locked->police_status, ['PENDING', 'PENDING_POLICE_SUBMISSION'], true)) {
                throw new TemporaryRegistrationConflictException('Hồ sơ đã được xử lý hoặc không còn ở trạng thái cho phép chỉnh sửa.', 409);
            }

            // Optimistic concurrency check qua updated_at
            if (! empty($data['updated_at'])) {
                try {
                    $clientTime = Carbon::parse($data['updated_at'])->timestamp;
                } catch (\Throwable $e) {
                    throw new TemporaryRegistrationConflictException('Dữ liệu phiên bản hồ sơ (updated_at) không hợp lệ.', 409);
                }

                $dbTime = $locked->updated_at ? $locked->updated_at->timestamp : 0;
                if ($clientTime !== $dbTime) {
                    throw new TemporaryRegistrationConflictException('Hồ sơ đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.', 409);
                }
            }

            // Loại bỏ các trường hệ thống không được ghi đè tự ý
            unset($data['reviewed_by'], $data['reviewed_at'], $data['police_status'], $data['resident_id'], $data['apartment_id'], $data['updated_at']);

            // Đảm bảo updated_at luôn được tăng lên ít nhất 1 giây để bảo đảm phân biệt giữa các lần update liên tiếp trong cùng 1 giây
            $newUpdatedAt = now();
            if ($locked->updated_at && $newUpdatedAt->timestamp <= $locked->updated_at->timestamp) {
                $newUpdatedAt = $locked->updated_at->copy()->addSecond();
            }
            $locked->updated_at = $newUpdatedAt;
            $locked->fill($data);
            $locked->save();

            return $locked->load([
                'resident.user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
                'apartment:id,apartment_number,block_id',
                'reviewer:id,username,full_name',
            ]);
        });
    }

    /**
     * Xóa hồ sơ tạm trú / tạm vắng khi ở trạng thái cho phép (PENDING / PENDING_POLICE_SUBMISSION)
     *
     * @throws TemporaryRegistrationNotFoundException|TemporaryRegistrationConflictException
     */
    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $locked = TemporaryRegistration::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new TemporaryRegistrationNotFoundException('Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.');
            }

            // Tuyệt đối không cho xóa hồ sơ đã được phê duyệt, từ chối hoặc đã nộp Công An
            if (! in_array($locked->police_status, ['PENDING', 'PENDING_POLICE_SUBMISSION'], true)) {
                throw new TemporaryRegistrationConflictException('Không thể xóa hồ sơ đã được xử lý hoặc đã gửi Công An.', 409);
            }

            $affected = TemporaryRegistration::where('id', $id)->delete();
            if ($affected === 0) {
                throw new TemporaryRegistrationNotFoundException('Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.');
            }

            return true;
        });
    }

    /**
     * Phê duyệt hồ sơ tạm trú / tạm vắng (Concurrency Safe with lockForUpdate)
     *
     * @throws TemporaryRegistrationNotFoundException|TemporaryRegistrationConflictException
     */
    public function approve(string $id, User $admin, ?string $notes = null, ?string $policeReferenceCode = null): TemporaryRegistration
    {
        return DB::transaction(function () use ($id, $admin, $notes, $policeReferenceCode) {
            $locked = TemporaryRegistration::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new TemporaryRegistrationNotFoundException('Hồ sơ tạm trú / tạm vắng không tồn tại trong hệ thống.');
            }

            if ($locked->police_status === 'APPROVED') {
                throw new TemporaryRegistrationConflictException('Hồ sơ này đã được phê duyệt trước đó.', 409);
            }

            if ($locked->police_status === 'REJECTED') {
                throw new TemporaryRegistrationConflictException('Hồ sơ này đã bị từ chối trước đó, không thể phê duyệt.', 409);
            }

            $locked->police_status = 'APPROVED';
            $locked->reviewed_by = $admin->id;
            $locked->reviewed_at = now();

            if ($notes !== null && trim($notes) !== '') {
                $locked->notes = trim($notes);
            }

            if ($policeReferenceCode !== null && trim($policeReferenceCode) !== '') {
                $locked->police_reference_code = trim($policeReferenceCode);
            }

            $locked->save();

            return $locked->load([
                'resident.user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
                'apartment:id,apartment_number,block_id',
                'reviewer:id,username,full_name',
            ]);
        });
    }

    /**
     * Từ chối hồ sơ tạm trú / tạm vắng (Concurrency Safe with lockForUpdate)
     *
     * @throws TemporaryRegistrationNotFoundException|TemporaryRegistrationConflictException
     */
    public function reject(string $id, User $admin, string $reason): TemporaryRegistration
    {
        return DB::transaction(function () use ($id, $admin, $reason) {
            $locked = TemporaryRegistration::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new TemporaryRegistrationNotFoundException('Hồ sơ tạm trú / tạm vắng không tồn tại trong hệ thống.');
            }

            if ($locked->police_status === 'REJECTED') {
                throw new TemporaryRegistrationConflictException('Hồ sơ này đã bị từ chối trước đó.', 409);
            }

            if ($locked->police_status === 'APPROVED') {
                throw new TemporaryRegistrationConflictException('Hồ sơ này đã được phê duyệt trước đó, không thể từ chối.', 409);
            }

            $locked->police_status = 'REJECTED';
            $locked->reviewed_by = $admin->id;
            $locked->reviewed_at = now();
            $locked->notes = trim($reason);

            $locked->save();

            return $locked->load([
                'resident.user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
                'apartment:id,apartment_number,block_id',
                'reviewer:id,username,full_name',
            ]);
        });
    }

    /**
     * Nộp hồ sơ sang Công An / Cập nhật mã tham chiếu Công An tiếp nhận
     *
     * @throws TemporaryRegistrationNotFoundException|TemporaryRegistrationConflictException
     */
    public function submitToPolice(string $id, User $admin, ?string $referenceCode = null, ?string $notes = null): TemporaryRegistration
    {
        return DB::transaction(function () use ($id, $referenceCode, $notes) {
            $locked = TemporaryRegistration::where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new TemporaryRegistrationNotFoundException('Hồ sơ tạm trú / tạm vắng không tồn tại trong hệ thống.');
            }

            if (in_array($locked->police_status, ['APPROVED', 'REJECTED'], true)) {
                throw new TemporaryRegistrationConflictException("Không thể chuyển nộp hồ sơ đã ở trạng thái {$locked->police_status}.", 409);
            }

            $locked->police_status = 'SUBMITTED_TO_POLICE';

            if ($referenceCode !== null && trim($referenceCode) !== '') {
                $locked->police_reference_code = trim($referenceCode);
            }

            if ($notes !== null && trim($notes) !== '') {
                $locked->notes = trim($notes);
            }

            $locked->save();

            return $locked->load([
                'resident.user:id,username,full_name,phone_number,email,national_id_number,avatar_url',
                'apartment:id,apartment_number,block_id',
                'reviewer:id,username,full_name',
            ]);
        });
    }

    /**
     * Tải lên ảnh CCCD mặt trước hoặc mặt sau
     */
    public function uploadCccd(UploadedFile $file, string $side = 'front'): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        if (! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'Định dạng file không hợp lệ. Chỉ chấp nhận JPG, PNG, WEBP, PDF.',
            ]);
        }

        // Tối đa 5MB
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => 'Kích thước file không được vượt quá 5MB.',
            ]);
        }

        $filename = 'cccd_'.$side.'_'.Str::random(16).'.'.$extension;
        $path = $file->storeAs('temporary_registrations/cccd', $filename, 'public');

        return '/storage/'.$path;
    }

    /**
     * Xuất dữ liệu Biểu mẫu gửi Công An (Mẫu CT01 - Theo Thông tư 66/2023/TT-BCA)
     * Thao tác CHỈ ĐỌC (READ-ONLY) - Tuyệt đối không thay đổi CSDL.
     *
     * @return array<string, mixed>
     */
    public function exportPoliceForm(string $id): array
    {
        $registration = $this->getById($id);

        $resident = $registration->resident;
        $user = $resident?->user;
        $apartment = $registration->apartment;
        $reviewer = $registration->reviewer;

        $regId = (string) $registration->id;
        $policeRef = (string) ($registration->police_reference_code ?? 'Chưa cấp');
        $policeStatus = (string) $registration->police_status;
        $reason = (string) $registration->reason;
        $notes = (string) ($registration->notes ?? 'Không');

        $userFullName = (string) ($user?->full_name ?? '');
        $userDob = (string) ($user?->date_of_birth ?? '');
        $userGender = (string) ($user?->gender ?? '');
        $userNationalId = (string) ($user?->national_id_number ?? '');
        $userPhone = (string) ($user?->phone_number ?? '');
        $userEmail = (string) ($user?->email ?? '');
        $residentOccupation = (string) ($resident?->occupation ?? '');
        $aptNumber = (string) ($apartment?->apartment_number ?? '');
        $residentType = (string) ($resident?->resident_type ?? '');
        $isHeadText = ($resident && $resident->is_head_of_household) ? 'CÓ' : 'KHÔNG';

        $reviewerName = (string) ($reviewer?->full_name ?? 'Ban Quản Lý Tòa Nhà');
        $reviewerEmail = (string) ($reviewer?->email ?? '');
        $reviewedAt = $registration->reviewed_at ? $registration->reviewed_at->format('d/m/Y H:i:s') : 'Đang chờ xử lý';

        $typeName = $registration->registration_type === 'TEMPORARY_STAY' ? 'ĐĂNG KÝ TẠM TRÚ' : 'THÔNG BÁO TẠM VẮNG';
        $createdDate = $registration->created_at ? $registration->created_at->format('d/m/Y') : date('d/m/Y');
        $startDate = $registration->start_date ? $registration->start_date->format('d/m/Y') : '';
        $endDate = $registration->end_date ? $registration->end_date->format('d/m/Y') : '';

        $printableHtml = <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Biểu Mẫu CT01 - {$typeName} - {$userFullName}</title>
    <style>
        @page { size: A4; margin: 20mm; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 13pt; line-height: 1.5; color: #111; margin: 0; padding: 20px; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .header-table td { vertical-align: top; text-align: center; }
        .motto { font-size: 11pt; font-weight: bold; }
        .motto-sub { font-size: 12pt; font-weight: bold; border-bottom: 1px solid #111; display: inline-block; padding-bottom: 2px; }
        .title { text-align: center; font-size: 16pt; font-weight: bold; text-transform: uppercase; margin: 25px 0 5px 0; }
        .subtitle { text-align: center; font-size: 12pt; font-style: italic; margin-bottom: 20px; }
        .section-title { font-weight: bold; font-size: 13pt; margin-top: 15px; margin-bottom: 8px; text-decoration: underline; }
        .info-row { margin-bottom: 8px; }
        .info-label { font-weight: bold; }
        .signature-table { width: 100%; border-collapse: collapse; margin-top: 40px; }
        .signature-table td { vertical-align: top; text-align: center; width: 50%; }
        .stamp-box { border: 2px dashed #0284c7; background-color: #f0f9ff; padding: 15px; border-radius: 8px; margin-top: 20px; font-size: 11pt; color: #0369a1; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; padding: 12px; background: #e2e8f0; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
        <span style="font-weight: bold; font-family: sans-serif;">Biểu Mẫu CT01 Xuất Gửi Công An (Hồ sơ: {$regId})</span>
        <button onclick="window.print()" style="padding: 8px 16px; background: #0284c7; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">In Biểu Mẫu (Print / Save as PDF)</button>
    </div>

    <table class="header-table">
        <tr>
            <td style="width: 45%;">
                <strong>BAN QUẢN LÝ TÒA NHÀ</strong><br>
                <strong>SMART CASSAVAS APARTMENT</strong><br>
                <span style="font-size: 11pt;">Số hồ sơ: {$regId}</span><br>
                <span style="font-size: 11pt;">Mã tiếp nhận CA: <strong>{$policeRef}</strong></span>
            </td>
            <td style="width: 55%;">
                <span class="motto">CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</span><br>
                <span class="motto-sub">Độc lập - Tự do - Hạnh phúc</span><br>
                <span style="font-size: 11pt; font-style: italic; display: inline-block; margin-top: 5px;">..., ngày {$createdDate}</span>
            </td>
        </tr>
    </table>

    <div class="title">TỜ KHAI THAY ĐỔI THÔNG TIN CƯ TRÚ</div>
    <div class="subtitle">(Dùng cho thông báo {$typeName} gửi Cơ quan Công an xã/phường)</div>

    <div style="text-align: center; margin-bottom: 20px; font-weight: bold;">
        Kính gửi: CÔNG AN PHƯỜNG / XÃ ĐỊA BÀN QUẢN LÝ
    </div>

    <div class="section-title">I. THÔNG TIN NGƯỜI KÊ KHAI (CƯ DÂN)</div>
    <div class="info-row"><span class="info-label">1. Họ và tên:</span> <span style="text-transform: uppercase; font-weight: bold;">{$userFullName}</span></div>
    <div class="info-row">
        <span class="info-label">2. Ngày, tháng, năm sinh:</span> {$userDob} &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        <span class="info-label">3. Giới tính:</span> {$userGender}
    </div>
    <div class="info-row"><span class="info-label">4. Số ĐDCN / CCCD / CMND:</span> <strong>{$userNationalId}</strong></div>
    <div class="info-row"><span class="info-label">5. Số điện thoại liên hệ:</span> {$userPhone} &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <span class="info-label">Email:</span> {$userEmail}</div>
    <div class="info-row"><span class="info-label">6. Nghề nghiệp:</span> {$residentOccupation}</div>

    <div class="section-title">II. THÔNG TIN NƠI CƯ TRÚ VÀ CĂN HỘ TÒA NHÀ</div>
    <div class="info-row"><span class="info-label">1. Địa chỉ căn hộ:</span> Căn hộ số <strong>{$aptNumber}</strong>, Chung cư Smart Cassavas</div>
    <div class="info-row"><span class="info-label">2. Loại cư dân:</span> {$residentType} &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <span class="info-label">Chủ hộ:</span> {$isHeadText}</div>

    <div class="section-title">III. NỘI DUNG ĐỀ NGHỊ THAY ĐỔI CƯ TRÚ</div>
    <div class="info-row"><span class="info-label">1. Hình thức đăng ký:</span> <strong>{$typeName}</strong></div>
    <div class="info-row"><span class="info-label">2. Thời hạn:</span> Từ ngày <strong>{$startDate}</strong> đến ngày <strong>{$endDate}</strong></div>
    <div class="info-row"><span class="info-label">3. Lý do:</span> {$reason}</div>
    <div class="info-row"><span class="info-label">4. Ghi chú tiếp nhận:</span> {$notes}</div>

    <div class="stamp-box">
        <strong>XÁC NHẬN CỦA BAN QUẢN LÝ TÒA NHÀ:</strong><br>
        Hồ sơ đã được thẩm tra thông tin hợp lệ bởi Ban Quản Lý Chung cư.<br>
        - Trạng thái xử lý: <strong>{$policeStatus}</strong><br>
        - Cán bộ duyệt: <strong>{$reviewerName} ({$reviewerEmail})</strong><br>
        - Thời gian phê duyệt: <strong>{$reviewedAt}</strong>
    </div>

    <table class="signature-table">
        <tr>
            <td>
                <strong>Ý KIẾN CỦA CHỦ HỘ / CƯ DÂN</strong><br>
                <span style="font-size: 11pt; font-style: italic;">(Ký, ghi rõ họ tên)</span>
                <div style="height: 70px;"></div>
                <strong>{$userFullName}</strong>
            </td>
            <td>
                <strong>ĐẠI DIỆN BAN QUẢN LÝ TÒA NHÀ</strong><br>
                <span style="font-size: 11pt; font-style: italic;">(Ký, đóng dấu xác nhận)</span>
                <div style="height: 70px;"></div>
                <strong>{$reviewerName}</strong>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        return [
            'registration' => $registration,
            'resident' => $resident,
            'user' => $user,
            'apartment' => $apartment,
            'reviewer' => $reviewer,
            'form_code' => 'CT01',
            'form_title' => "Biểu Mẫu CT01 - {$typeName}",
            'police_status' => $registration->police_status,
            'police_reference_code' => $registration->police_reference_code,
            'html_content' => $printableHtml,
        ];
    }
}

class TemporaryRegistrationConflictException extends \RuntimeException
{
    public function __construct(string $message, protected int $statusCode = 409)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

class TemporaryRegistrationNotFoundException extends \RuntimeException
{
    public function __construct(string $message = 'Hồ sơ tạm trú / tạm vắng không tồn tại trong hệ thống.', protected int $statusCode = 404)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

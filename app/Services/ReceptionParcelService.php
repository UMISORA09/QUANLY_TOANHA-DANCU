<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Parcel;
use App\Models\Resident;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReceptionParcelService
{
    /**
     * Lấy danh sách kiện hàng bưu phẩm có phân trang, bộ lọc và thống kê KPIs
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: LengthAwarePaginator, kpis: array<string, int>}
     */
    public function getParcels(array $filters = [], int $perPage = 15): array
    {
        $query = Parcel::query()
            ->with([
                'apartment' => function ($q) {
                    $q->select('id', 'apartment_number', 'block_id');
                },
                'apartment.block' => function ($q) {
                    $q->select('id', 'block_name', 'block_code');
                },
                'recipientUser' => function ($q) {
                    $q->select('id', 'full_name', 'phone_number');
                },
                'receivedByStaff' => function ($q) {
                    $q->select('id', 'full_name');
                },
            ]);

        // 1. Lọc theo trạng thái (status)
        if (! empty($filters['status']) && $filters['status'] !== 'ALL') {
            if ($filters['status'] === 'PENDING') {
                $query->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED']);
            } elseif ($filters['status'] === 'OVERDUE') {
                $query->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
                    ->where('received_at', '<', Carbon::now()->subDays(5));
            } else {
                $query->where('status', $filters['status']);
            }
        }

        // 2. Lọc theo căn hộ (apartment_id)
        if (! empty($filters['apartment_id'])) {
            $query->where('apartment_id', $filters['apartment_id']);
        }

        // 3. Lọc theo đơn vị vận chuyển (courier_company)
        if (! empty($filters['courier_company']) && $filters['courier_company'] !== 'ALL') {
            $query->where('courier_company', $filters['courier_company']);
        }

        // 4. Lọc theo khoảng ngày tiếp nhận
        if (! empty($filters['from_date'])) {
            $query->whereDate('received_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('received_at', '<=', $filters['to_date']);
        }

        // 5. Tìm kiếm (Mã vận đơn, Tên người nhận, SĐT, Căn hộ)
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('recipient_phone', 'like', "%{$search}%")
                    ->orWhere('courier_company', 'like', "%{$search}%")
                    ->orWhere('pickup_pin_code', 'like', "%{$search}%")
                    ->orWhere('pickup_qr_code', 'like', "%{$search}%")
                    ->orWhereHas('apartment', function ($aq) use ($search) {
                        $aq->where('apartment_number', 'like', "%{$search}%");
                    });
            });
        }

        // Sắp xếp mặc định: mới tiếp nhận lên trước
        $query->orderBy('received_at', 'desc');

        $paginator = $query->paginate($perPage);

        // Tính toán KPIs tối ưu (chỉ query số lượng)
        $kpis = $this->calculateKpis();

        return [
            'data' => $paginator,
            'kpis' => $kpis,
        ];
    }

    /**
     * Tính toán các chỉ số KPI bưu phẩm tại quầy Lễ tân
     *
     * @return array{pending_parcels: int, overdue_parcels: int, received_today: int, total_parcels: int}
     */
    public function calculateKpis(): array
    {
        $fiveDaysAgo = Carbon::now()->subDays(5);
        $todayStart = Carbon::today();

        $counts = DB::table('parcels')
            ->selectRaw("
                COUNT(*) as total_parcels,
                SUM(CASE WHEN status IN ('RECEIVED_AT_RECEPTION', 'RECEIVED') THEN 1 ELSE 0 END) as pending_parcels,
                SUM(CASE WHEN status IN ('RECEIVED_AT_RECEPTION', 'RECEIVED') AND received_at < ? THEN 1 ELSE 0 END) as overdue_parcels,
                SUM(CASE WHEN received_at >= ? THEN 1 ELSE 0 END) as received_today
            ", [$fiveDaysAgo, $todayStart])
            ->first();

        return [
            'pending_parcels' => (int) ($counts?->pending_parcels ?? 0),
            'overdue_parcels' => (int) ($counts?->overdue_parcels ?? 0),
            'received_today' => (int) ($counts?->received_today ?? 0),
            'total_parcels' => (int) ($counts?->total_parcels ?? 0),
        ];
    }

    /**
     * Tiếp nhận kiện hàng mới và lưu trữ ảnh bưu kiện
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException|Throwable
     */
    public function receiveParcel(array $data, ?User $staffUser = null, ?UploadedFile $photoFile = null): Parcel
    {
        // 1. Xác thực căn hộ tồn tại
        $apartment = Apartment::find($data['apartment_id']);
        if (! $apartment) {
            throw ValidationException::withMessages([
                'apartment_id' => ['Căn hộ được chọn không tồn tại trong hệ thống.'],
            ]);
        }

        // 2. Chống double-submit / kiện hàng trùng:
        // Nếu cùng mã vận đơn + cùng căn hộ vừa được tiếp nhận trong 24h và còn đang chờ nhận
        $recentDuplicate = Parcel::where('tracking_number', trim($data['tracking_number']))
            ->where('apartment_id', $data['apartment_id'])
            ->whereIn('status', ['RECEIVED_AT_RECEPTION', 'RECEIVED'])
            ->where('received_at', '>=', Carbon::now()->subHours(24))
            ->first();

        if ($recentDuplicate) {
            throw ValidationException::withMessages([
                'tracking_number' => [
                    "Kiện hàng với mã vận đơn '{$data['tracking_number']}' đã được tiếp nhận cho căn hộ này lúc ".
                    $recentDuplicate->received_at->format('H:i d/m/Y').'. Vui lòng kiểm tra lại!',
                ],
            ]);
        }

        // 3. Xác định nhân viên tiếp nhận (từ phiên đăng nhập của server, KHÔNG tin ID frontend gửi)
        $staffId = $staffUser?->id;
        if (! $staffId) {
            // Fallback lấy nhân viên lễ tân/admin đầu tiên nếu chạy CLI/Unit Test không có session
            $staffId = User::whereHas('roles', function ($q) {
                $q->whereIn('roles.role_code', ['RECEPTIONIST', 'STAFF', 'ADMIN', 'SUPER_ADMIN', 'SECURITY_GUARD']);
            })->value('id') ?? User::value('id');

            if (! $staffId) {
                throw new \RuntimeException('Không tìm thấy tài khoản nhân viên hợp lệ để ghi nhận phiếu tiếp nhận.');
            }
        }

        // 4. Liên kết người nhận (recipient_user_id) nếu có hoặc tìm theo SĐT cư dân trong căn hộ
        $recipientUserId = $data['recipient_user_id'] ?? null;
        if (! $recipientUserId && ! empty($data['recipient_phone'])) {
            $matchingResident = Resident::where('apartment_id', $data['apartment_id'])
                ->where('is_active', true)
                ->whereHas('user', function ($uq) use ($data) {
                    $uq->where('phone_number', trim($data['recipient_phone']));
                })
                ->value('user_id');

            if ($matchingResident) {
                $recipientUserId = $matchingResident;
            }
        }

        // 5. Xử lý lưu trữ file ảnh bưu kiện
        $storedPath = null;
        $photoUrl = $data['parcel_photo_url'] ?? null;

        if ($photoFile) {
            $this->validateImageFile($photoFile);
            $storedPath = $this->optimizeAndStoreImage($photoFile);
            $photoUrl = '/storage/'.$storedPath;
        }

        if (empty($photoUrl)) {
            throw ValidationException::withMessages([
                'photo' => ['Vui lòng chụp hoặc tải lên ảnh chụp thực tế của kiện hàng bưu phẩm.'],
            ]);
        }

        // 6. Tạo mã lấy hàng an toàn (Pickup PIN Code 6 số & Pickup QR Code)
        $pickupPinCode = sprintf('%06d', mt_rand(100000, 999999));
        $pickupQrCode = 'PKG-'.strtoupper(Str::random(8)).'-'.mt_rand(100, 999);

        // Đảm bảo mã QR không bị trùng
        while (Parcel::where('pickup_qr_code', $pickupQrCode)->exists()) {
            $pickupQrCode = 'PKG-'.strtoupper(Str::random(8)).'-'.mt_rand(100, 999);
        }

        // 7. Thực thi Transaction Database
        $parcel = null;
        try {
            $parcel = DB::transaction(function () use (
                $data,
                $apartment,
                $recipientUserId,
                $staffId,
                $photoUrl,
                $pickupPinCode,
                $pickupQrCode
            ) {
                return Parcel::create([
                    'tracking_number' => trim($data['tracking_number']),
                    'courier_company' => trim($data['courier_company']),
                    'apartment_id' => $apartment->id,
                    'recipient_user_id' => $recipientUserId,
                    'recipient_name' => trim($data['recipient_name']),
                    'recipient_phone' => trim($data['recipient_phone']),
                    'parcel_photo_url' => $photoUrl,
                    'stored_location_type' => $data['stored_location_type'] ?? 'RECEPTION_DESK',
                    'locker_compartment_id' => $data['locker_compartment_id'] ?? null,
                    'pickup_pin_code' => $pickupPinCode,
                    'pickup_qr_code' => $pickupQrCode,
                    'status' => 'RECEIVED_AT_RECEPTION',
                    'received_at' => now(),
                    'received_by_staff_id' => $staffId,
                    'notes' => $data['notes'] ?? null,
                    'ai_ocr_extracted_text' => $data['ai_ocr_extracted_text'] ?? null,
                    'ai_ocr_raw_result' => $data['ai_ocr_raw_result'] ?? null,
                    'ai_ocr_confidence' => $data['ai_ocr_confidence'] ?? null,
                ]);
            });
        } catch (Throwable $e) {
            // Nếu insert database thất bại, xóa ngay file mồ côi vừa lưu để tránh rác storage
            if ($storedPath && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }
            Log::error('Lỗi khi lưu kiện hàng bưu phẩm vào database: '.$e->getMessage(), [
                'tracking_number' => $data['tracking_number'] ?? null,
                'apartment_id' => $data['apartment_id'] ?? null,
            ]);
            throw $e;
        }

        // 8. Eager load relations để trả về response đầy đủ
        $parcel->load([
            'apartment:id,apartment_number,block_id',
            'apartment.block:id,block_name,block_code',
            'recipientUser:id,full_name,phone_number',
            'receivedByStaff:id,full_name',
        ]);

        // 9. Phát sự kiện Realtime NGAY SAU KHI DATABASE COMMIT THÀNH CÔNG (Không cooldown lock)
        try {
            QuocTinRealtimeService::emit(
                module: 'parcels',
                entity: 'parcel',
                action: 'CREATED',
                entityId: $parcel->id,
                extra: [
                    'tracking_number' => $parcel->tracking_number,
                    'recipient_name' => $parcel->recipient_name,
                    'recipient_phone' => $parcel->recipient_phone,
                    'apartment_number' => $parcel->apartment?->apartment_number,
                    'courier_company' => $parcel->courier_company,
                    'status' => $parcel->status,
                    'parcel_photo_url' => $parcel->parcel_photo_url,
                    'received_at' => $parcel->received_at?->toIso8601String(),
                ],
                actorId: $staffId,
                triggerCooldown: false // KHÔNG đặt cooldown để Lễ tân tiếp nhận liên tiếp nhiều kiện hàng!
            );
        } catch (Throwable $e) {
            Log::warning('Không thể phát realtime event cho tiếp nhận bưu phẩm: '.$e->getMessage());
        }

        return $parcel;
    }

    /**
     * Cập nhật thông tin phiếu tiếp nhận bưu phẩm (không cho sửa trạng thái bàn giao)
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException|Throwable
     */
    public function updateParcel(string $id, array $data, ?User $staffUser = null, ?UploadedFile $newPhotoFile = null): Parcel
    {
        $parcel = Parcel::findOrFail($id);

        // Không cho phép sửa nếu bưu phẩm đã hoàn tất bàn giao (tránh can thiệp module check-out của thành viên khác)
        if (in_array($parcel->status, ['DELIVERED', 'RETURNED'])) {
            throw ValidationException::withMessages([
                'status' => ['Không thể chỉnh sửa kiện hàng đã hoàn tất bàn giao hoặc đã hoàn trả.'],
            ]);
        }

        $oldPhotoPath = null;
        $newStoredPath = null;
        $newPhotoUrl = null;

        // Nếu có upload ảnh thay thế
        if ($newPhotoFile) {
            $this->validateImageFile($newPhotoFile);
            $newStoredPath = $this->optimizeAndStoreImage($newPhotoFile);
            $newPhotoUrl = '/storage/'.$newStoredPath;

            // Ghi nhớ ảnh cũ để xóa sau khi DB commit thành công
            $oldUrl = $parcel->parcel_photo_url;
            if ($oldUrl && str_contains($oldUrl, '/storage/parcels/')) {
                $oldPhotoPath = 'parcels/'.basename($oldUrl);
            }
        }

        $fieldsToUpdate = [];
        if (isset($data['tracking_number'])) {
            $fieldsToUpdate['tracking_number'] = trim($data['tracking_number']);
        }
        if (isset($data['courier_company'])) {
            $fieldsToUpdate['courier_company'] = trim($data['courier_company']);
        }
        if (isset($data['recipient_name'])) {
            $fieldsToUpdate['recipient_name'] = trim($data['recipient_name']);
        }
        if (isset($data['recipient_phone'])) {
            $fieldsToUpdate['recipient_phone'] = trim($data['recipient_phone']);
        }
        if (isset($data['stored_location_type'])) {
            $fieldsToUpdate['stored_location_type'] = $data['stored_location_type'];
        }
        if (array_key_exists('notes', $data)) {
            $fieldsToUpdate['notes'] = $data['notes'];
        }
        if ($newPhotoUrl) {
            $fieldsToUpdate['parcel_photo_url'] = $newPhotoUrl;
        }

        try {
            DB::transaction(function () use ($parcel, $fieldsToUpdate) {
                $parcel->update($fieldsToUpdate);
            });
        } catch (Throwable $e) {
            // Nếu update DB thất bại, xóa ảnh mới vừa tải lên
            if ($newStoredPath && Storage::disk('public')->exists($newStoredPath)) {
                Storage::disk('public')->delete($newStoredPath);
            }
            throw $e;
        }

        // Sau khi DB commit thành công, xóa ảnh cũ nếu có thay thế ảnh
        if ($oldPhotoPath && Storage::disk('public')->exists($oldPhotoPath)) {
            Storage::disk('public')->delete($oldPhotoPath);
        }

        $parcel->load([
            'apartment:id,apartment_number,block_id',
            'apartment.block:id,block_name,block_code',
            'recipientUser:id,full_name,phone_number',
            'receivedByStaff:id,full_name',
        ]);

        // Phát realtime event update
        try {
            QuocTinRealtimeService::emit(
                module: 'parcels',
                entity: 'parcel',
                action: 'UPDATED',
                entityId: $parcel->id,
                extra: [
                    'tracking_number' => $parcel->tracking_number,
                    'recipient_name' => $parcel->recipient_name,
                    'recipient_phone' => $parcel->recipient_phone,
                    'apartment_number' => $parcel->apartment?->apartment_number,
                    'courier_company' => $parcel->courier_company,
                    'status' => $parcel->status,
                    'parcel_photo_url' => $parcel->parcel_photo_url,
                ],
                actorId: $staffUser?->id,
                triggerCooldown: false
            );
        } catch (Throwable $e) {
            Log::warning('Lỗi broadcast realtime update parcel: '.$e->getMessage());
        }

        return $parcel;
    }

    /**
     * Xóa phiếu tiếp nhận bưu phẩm (chỉ cho phép xóa khi bưu phẩm chưa bàn giao)
     *
     * @throws ValidationException|Throwable
     */
    public function deleteParcel(string $id, ?User $staffUser = null): bool
    {
        $parcel = Parcel::findOrFail($id);

        if (in_array($parcel->status, ['DELIVERED', 'RETURNED'])) {
            throw ValidationException::withMessages([
                'status' => ['Không thể xóa kiện hàng đã hoàn tất bàn giao hoặc đã hoàn trả.'],
            ]);
        }

        $photoUrl = $parcel->parcel_photo_url;
        $trackingNumber = $parcel->tracking_number;
        $parcelId = $parcel->id;

        // Xóa file ảnh vật lý nếu có
        if ($photoUrl && str_contains($photoUrl, '/storage/parcels/')) {
            $photoPath = 'parcels/'.basename($photoUrl);
            if (Storage::disk('public')->exists($photoPath)) {
                Storage::disk('public')->delete($photoPath);
            }
        }

        $parcel->delete();

        // Phát sự kiện realtime DELETED
        try {
            QuocTinRealtimeService::emit(
                module: 'parcels',
                entity: 'parcel',
                action: 'DELETED',
                entityId: $parcelId,
                extra: [
                    'tracking_number' => $trackingNumber,
                ],
                actorId: $staffUser?->id,
                triggerCooldown: false
            );
        } catch (Throwable $e) {
            Log::warning('Lỗi broadcast realtime delete parcel: '.$e->getMessage());
        }

        return true;
    }

    /**
     * Tìm kiếm nhanh người nhận/cư dân (chỉ select trường cần thiết)
     *
     * @return array<int, array{id: string, full_name: string, phone_number: ?string, apartment_number: ?string}>
     */
    public function searchRecipients(string $keyword, ?string $apartmentId = null, int $limit = 10): array
    {
        $keyword = trim($keyword);
        $query = Resident::query()
            ->with([
                'user:id,full_name,phone_number',
                'apartment:id,apartment_number',
            ])
            ->where('is_active', true);

        if (! empty($apartmentId)) {
            $query->where('apartment_id', $apartmentId);
        }

        if (! empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->whereHas('user', function ($uq) use ($keyword) {
                    $uq->where('full_name', 'like', "%{$keyword}%")
                        ->orWhere('phone_number', 'like', "%{$keyword}%");
                })->orWhereHas('apartment', function ($aq) use ($keyword) {
                    $aq->where('apartment_number', 'like', "%{$keyword}%");
                });
            });
        }

        $residents = $query->limit($limit)->get();

        return $residents->map(function ($r) {
            return [
                'id' => (string) ($r->user?->id ?? $r->id),
                'resident_id' => (string) $r->id,
                'full_name' => (string) ($r->user?->full_name ?? 'Cư dân'),
                'phone_number' => $r->user?->phone_number,
                'apartment_id' => (string) $r->apartment_id,
                'apartment_number' => $r->apartment?->apartment_number,
            ];
        })->toArray();
    }

    /**
     * Tìm kiếm nhanh danh sách căn hộ (chỉ select trường cần thiết)
     *
     * @return array<int, array{id: string, apartment_number: string, block_name: ?string}>
     */
    public function searchApartments(string $keyword = '', int $limit = 15): array
    {
        $keyword = trim($keyword);
        $query = Apartment::query()
            ->with(['block:id,block_name,block_code'])
            ->select('id', 'apartment_number', 'block_id', 'status');

        if (! empty($keyword)) {
            $query->where('apartment_number', 'like', "%{$keyword}%");
        }

        $apartments = $query->limit($limit)->get();

        return $apartments->map(function ($apt) {
            return [
                'id' => (string) $apt->id,
                'apartment_number' => (string) $apt->apartment_number,
                'block_name' => $apt->block?->block_name ?? $apt->block?->block_code,
                'status' => (string) $apt->status,
            ];
        })->toArray();
    }

    /**
     * Validate an toàn file ảnh tải lên
     *
     * @throws ValidationException
     */
    protected function validateImageFile(UploadedFile $file): void
    {
        // 1. Kiểm tra lỗi upload cơ bản
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'photo' => ['Quá trình tải file ảnh bưu kiện lên gặp lỗi. Vui lòng thử lại!'],
            ]);
        }

        // 2. Kiểm tra dung lượng (tối đa 10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'photo' => ['Dung lượng ảnh bưu kiện không được vượt quá 10MB.'],
            ]);
        }

        // 3. Kiểm tra MIME type thực tế (chỉ cho phép JPEG, PNG, WEBP)
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $mime = $file->getMimeType();
        if (! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'photo' => ['Định dạng ảnh không hợp lệ. Chỉ chấp nhận định dạng JPG, JPEG, PNG hoặc WebP.'],
            ]);
        }

        // 4. Kiểm tra phần mở rộng file (Extension)
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $allowedExts, true)) {
            throw ValidationException::withMessages([
                'photo' => ['Phần mở rộng file không được chấp nhận. Vui lòng chọn ảnh định dạng hợp lệ.'],
            ]);
        }
    }

    /**
     * Tự động nén và tối ưu hóa ảnh bưu kiện bằng GD (nếu khả dụng)
     */
    protected function optimizeAndStoreImage(UploadedFile $photoFile): string
    {
        $extension = strtolower($photoFile->getClientOriginalExtension() ?: 'jpg');
        $safeFileName = 'pkg_'.date('Ymd_His').'_'.Str::random(12).'.'.$extension;
        $destinationDir = storage_path('app/public/parcels');

        if (! file_exists($destinationDir)) {
            @mkdir($destinationDir, 0755, true);
        }

        $targetPath = $destinationDir.'/'.$safeFileName;

        // Nếu GD extension khả dụng và file là JPEG/PNG/WebP, tiến hành resize & compress
        if (extension_loaded('gd') && in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            try {
                $image = match ($extension) {
                    'jpg', 'jpeg' => @imagecreatefromjpeg($photoFile->getRealPath()),
                    'png' => @imagecreatefrompng($photoFile->getRealPath()),
                    'webp' => @imagecreatefromwebp($photoFile->getRealPath()),
                    default => null,
                };

                if ($image) {
                    $origWidth = imagesx($image);
                    $origHeight = imagesy($image);
                    $maxDim = 1280;

                    if ($origWidth > $maxDim || $origHeight > $maxDim) {
                        if ($origWidth > $origHeight) {
                            $newWidth = $maxDim;
                            $newHeight = (int) round(($origHeight * $maxDim) / $origWidth);
                        } else {
                            $newHeight = $maxDim;
                            $newWidth = (int) round(($origWidth * $maxDim) / $origHeight);
                        }

                        $resized = imagecreatetruecolor($newWidth, $newHeight);

                        if (in_array($extension, ['png', 'webp'], true)) {
                            imagealphablending($resized, false);
                            imagesavealpha($resized, true);
                        }

                        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
                        imagedestroy($image);
                        $image = $resized;
                    }

                    if ($extension === 'webp') {
                        imagewebp($image, $targetPath, 82);
                    } elseif ($extension === 'png') {
                        imagepng($image, $targetPath, 6);
                    } else {
                        imagejpeg($image, $targetPath, 82);
                    }

                    imagedestroy($image);

                    return 'parcels/'.$safeFileName;
                }
            } catch (Throwable $e) {
                // Fallback nếu có lỗi xử lý GD
            }
        }

        return $photoFile->storeAs('parcels', $safeFileName, 'public');
    }
}

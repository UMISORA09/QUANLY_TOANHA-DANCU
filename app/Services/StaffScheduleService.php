<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Role;
use App\Models\ShiftType;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StaffScheduleService
{
    private static bool $initialDataChecked = false;

    public static function resetInitialDataCache(): void
    {
        self::$initialDataChecked = false;
        Cache::forget('staff_schedule_initial_data_done');
    }

    /**
     * Khởi tạo dữ liệu ca trực mẫu và liên kết tài khoản Lễ tân & Bảo vệ vào hồ sơ nhân viên
     */
    public function ensureInitialData(bool $force = false): void
    {
        if (! $force && ! app()->environment('testing')) {
            if (self::$initialDataChecked) {
                return;
            }

            if (Cache::has('staff_schedule_initial_data_done')) {
                self::$initialDataChecked = true;

                return;
            }
        }
        // 1. Khởi tạo Departments nếu chưa có
        $deptReception = Department::firstOrCreate(
            ['department_code' => 'FRONT_DESK'],
            ['department_name' => 'Bộ phận Lễ Tân']
        );

        $deptSecurity = Department::firstOrCreate(
            ['department_code' => 'SECURITY'],
            ['department_name' => 'Bộ phận An Ninh / Bảo Vệ']
        );

        // 2. Khởi tạo Shift Types nếu chưa có
        $defaultShifts = [
            [
                'shift_code' => 'CA_SANG',
                'shift_name' => 'Ca Sáng (06:00 - 14:00)',
                'start_time' => '06:00:00',
                'end_time' => '14:00:00',
                'break_minutes' => 60,
                'is_overnight' => 0,
            ],
            [
                'shift_code' => 'CA_CHIEU',
                'shift_name' => 'Ca Chiều (14:00 - 22:00)',
                'start_time' => '14:00:00',
                'end_time' => '22:00:00',
                'break_minutes' => 60,
                'is_overnight' => 0,
            ],
            [
                'shift_code' => 'CA_DEM',
                'shift_name' => 'Ca Đêm (22:00 - 06:00)',
                'start_time' => '22:00:00',
                'end_time' => '06:00:00',
                'break_minutes' => 60,
                'is_overnight' => 1,
            ],
            [
                'shift_code' => 'HANH_CHINH',
                'shift_name' => 'Hành Chính (08:00 - 17:00)',
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'break_minutes' => 60,
                'is_overnight' => 0,
            ],
        ];

        foreach ($defaultShifts as $shift) {
            ShiftType::firstOrCreate(
                ['shift_code' => $shift['shift_code']],
                $shift
            );
        }

        // 3. Tự động đồng bộ các user Lễ tân và Bảo vệ vào StaffProfiles nếu chưa có
        $staffUsers = User::query()
            ->join('user_roles', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.role_code', ['RECEPTIONIST', 'SECURITY_GUARD'])
            ->select('users.id', 'users.full_name', 'roles.role_code')
            ->get();

        foreach ($staffUsers as $su) {
            $existingProfile = StaffProfile::where('user_id', $su->id)->first();
            if (! $existingProfile) {
                $isReception = $su->role_code === 'RECEPTIONIST';
                $codePrefix = $isReception ? 'LT-' : 'BV-';
                $empCode = $codePrefix.strtoupper(substr(str_replace('-', '', $su->id), -6));

                while (StaffProfile::where('employee_code', $empCode)->where('user_id', '!=', $su->id)->exists()) {
                    $empCode = $codePrefix.strtoupper(Str::random(6));
                }

                StaffProfile::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $su->id,
                    'employee_code' => $empCode,
                    'department_id' => $isReception ? $deptReception->id : $deptSecurity->id,
                    'job_title' => $isReception ? 'Nhân viên Lễ tân' : 'Nhân viên Bảo vệ',
                    'hire_date' => now()->toDateString(),
                    'contract_type' => 'FULL_TIME',
                    'base_salary' => $isReception ? 9000000 : 8500000,
                ]);
            }
        }

        if (! app()->environment('testing')) {
            Cache::put('staff_schedule_initial_data_done', true, 86400);
            self::$initialDataChecked = true;
        }
    }

    /**
     * Lấy danh sách ca trực theo khoảng thời gian và bộ lọc
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getSchedules(array $filters): array
    {
        $this->ensureInitialData();

        $startDate = $filters['start_date'] ?? now()->startOfWeek()->toDateString();
        $endDate = $filters['end_date'] ?? now()->endOfWeek()->toDateString();

        $query = StaffSchedule::query()
            ->with([
                'staffProfile' => function ($q) {
                    $q->select('id', 'user_id', 'employee_code', 'department_id', 'job_title');
                },
                'staffProfile.user' => function ($q) {
                    $q->select('id', 'full_name', 'username', 'email', 'phone_number');
                },
                'staffProfile.user.roles' => function ($q) {
                    $q->select('roles.id', 'roles.role_code', 'roles.role_name');
                },
                'staffProfile.department' => function ($q) {
                    $q->select('id', 'department_code', 'department_name');
                },
                'shiftType:id,shift_code,shift_name,start_time,end_time,break_minutes,is_overnight',
                'creator:id,full_name',
            ])
            ->whereBetween('work_date', [$startDate, $endDate]);

        // Lọc theo Role Lễ tân hoặc Bảo vệ
        if (! empty($filters['role']) && strtoupper($filters['role']) !== 'ALL') {
            $roleCode = strtoupper($filters['role']);
            $query->whereHas('staffProfile.user.roles', function ($q) use ($roleCode) {
                $q->where('roles.role_code', $roleCode);
            });
        }

        // Lọc theo nhân viên cụ thể
        if (! empty($filters['staff_profile_id'])) {
            $query->where('staff_profile_id', $filters['staff_profile_id']);
        }

        // Lọc theo shift_type_id
        if (! empty($filters['shift_type_id'])) {
            $query->where('shift_type_id', $filters['shift_type_id']);
        }

        // Lọc theo trạng thái
        if (! empty($filters['status']) && strtoupper($filters['status']) !== 'ALL') {
            $query->where('status', strtoupper($filters['status']));
        }

        // Lọc theo từ khóa tìm kiếm (tên nhân viên, mã nhân viên, địa điểm trực)
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $q) use ($search) {
                $q->where('assigned_location', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('staffProfile', function ($spQ) use ($search) {
                        $spQ->where('employee_code', 'like', "%{$search}%")
                            ->orWhereHas('user', function ($uQ) use ($search) {
                                $uQ->where('full_name', 'like', "%{$search}%")
                                    ->orWhere('phone_number', 'like', "%{$search}%");
                            });
                    });
            });
        }

        // Tối ưu sorting: theo ngày tăng dần, sau đó theo ca
        $schedules = $query
            ->orderBy('work_date', 'asc')
            ->get();

        // Tính toán thống kê tóm tắt (KPIs) trong phạm vi xem
        $totalShifts = $schedules->count();
        $receptionShifts = 0;
        $securityShifts = 0;
        $scheduledCount = 0;
        $completedCount = 0;
        $cancelledCount = 0;

        foreach ($schedules as $item) {
            $roleCodes = $item->staffProfile?->user?->roles?->pluck('role_code')->toArray() ?? [];
            if (in_array('RECEPTIONIST', $roleCodes, true)) {
                $receptionShifts++;
            } elseif (in_array('SECURITY_GUARD', $roleCodes, true)) {
                $securityShifts++;
            }

            $st = strtoupper($item->status);
            if ($st === 'SCHEDULED') {
                $scheduledCount++;
            } elseif ($st === 'COMPLETED') {
                $completedCount++;
            } elseif ($st === 'CANCELLED') {
                $cancelledCount++;
            }
        }

        return [
            'schedules' => $schedules,
            'summary' => [
                'total_shifts' => $totalShifts,
                'reception_shifts' => $receptionShifts,
                'security_shifts' => $securityShifts,
                'scheduled_count' => $scheduledCount,
                'completed_count' => $completedCount,
                'cancelled_count' => $cancelledCount,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ];
    }

    /**
     * Kiểm tra xung đột và trùng lịch trực đối với nhân viên cụ thể
     *
     * @throws HttpException
     */
    public function validateNoConflict(
        string $staffProfileId,
        string $shiftTypeId,
        string $workDate,
        ?string $excludeScheduleId = null
    ): void {
        $staffProfile = StaffProfile::with('user')->find($staffProfileId);
        if (! $staffProfile) {
            throw new HttpException(422, 'Không tìm thấy hồ sơ nhân viên được chọn.');
        }

        $shiftType = ShiftType::find($shiftTypeId);
        if (! $shiftType) {
            throw new HttpException(422, 'Không tìm thấy loại ca trực được chọn.');
        }

        $staffName = $staffProfile->user?->full_name ?? $staffProfile->employee_code;

        // Tính khoảng thời gian thực tế của ca dự kiến
        $proposedStart = Carbon::parse("{$workDate} {$shiftType->start_time}");
        $proposedEnd = $shiftType->is_overnight || ($shiftType->end_time <= $shiftType->start_time)
            ? Carbon::parse("{$workDate} {$shiftType->end_time}")->addDay()
            : Carbon::parse("{$workDate} {$shiftType->end_time}");

        // Lấy tất cả ca trực đang hoạt động của nhân viên này trong khoảng [-2 ngày, +2 ngày]
        $rangeStart = $proposedStart->copy()->subDays(2)->toDateString();
        $rangeEnd = $proposedEnd->copy()->addDays(2)->toDateString();

        $existingSchedules = StaffSchedule::query()
            ->with('shiftType')
            ->where('staff_profile_id', $staffProfileId)
            ->where('status', '!=', 'CANCELLED')
            ->whereBetween('work_date', [$rangeStart, $rangeEnd])
            ->when($excludeScheduleId, fn ($q) => $q->where('id', '!=', $excludeScheduleId))
            ->get();

        foreach ($existingSchedules as $existing) {
            $existShift = $existing->shiftType;
            if (! $existShift) {
                continue;
            }

            $existWorkDate = Carbon::parse($existing->work_date)->toDateString();
            $existStart = Carbon::parse("{$existWorkDate} {$existShift->start_time}");
            $existEnd = $existShift->is_overnight || ($existShift->end_time <= $existShift->start_time)
                ? Carbon::parse("{$existWorkDate} {$existShift->end_time}")->addDay()
                : Carbon::parse("{$existWorkDate} {$existShift->end_time}");

            // 1. Kiểm tra nếu cùng ngày làm việc (work_date)
            if ($existWorkDate === $workDate) {
                $err = sprintf(
                    "Trùng lịch trực: Nhân viên '%s' đã được xếp ca '%s' trong ngày %s. Theo quy định, mỗi nhân viên chỉ trực 1 ca/ngày.",
                    $staffName,
                    $existShift->shift_name,
                    Carbon::parse($workDate)->format('d/m/Y')
                );
                throw new HttpException(409, $err);
            }

            // 2. Kiểm tra nếu khoảng thời gian thực tế (datetime) bị chồng lấn (qua đêm, giao ca)
            $overlapStart = max($proposedStart->timestamp, $existStart->timestamp);
            $overlapEnd = min($proposedEnd->timestamp, $existEnd->timestamp);

            if ($overlapStart < $overlapEnd) {
                $err = sprintf(
                    "Xung đột ca trực: Nhân viên '%s' đã có ca '%s' từ %s đến %s. Ca mới dự kiến (%s - %s) bị chồng lấn thời gian thực tế.",
                    $staffName,
                    $existShift->shift_name,
                    $existStart->format('H:i d/m/Y'),
                    $existEnd->format('H:i d/m/Y'),
                    $proposedStart->format('H:i d/m/Y'),
                    $proposedEnd->format('H:i d/m/Y')
                );
                throw new HttpException(409, $err);
            }
        }
    }

    /**
     * Tạo một ca trực mới
     *
     * @param  array<string, mixed>  $data
     *
     * @throws HttpException
     */
    public function createSchedule(array $data, ?User $creator): StaffSchedule
    {
        $staffProfileId = (string) $data['staff_profile_id'];
        $shiftTypeId = (string) $data['shift_type_id'];
        $workDate = Carbon::parse($data['work_date'])->toDateString();
        $location = $data['assigned_location'] ?? null;
        $notes = $data['notes'] ?? null;
        $status = strtoupper($data['status'] ?? 'SCHEDULED');

        $schedule = DB::transaction(function () use (
            $staffProfileId,
            $shiftTypeId,
            $workDate,
            $location,
            $notes,
            $status,
            $creator
        ) {
            // Khóa phạm vi bản ghi của nhân viên để chống race-condition
            $existingActive = StaffSchedule::where('staff_profile_id', $staffProfileId)
                ->where('work_date', $workDate)
                ->lockForUpdate()
                ->first();

            // Nếu đã có ca đang hoạt động (không phải CANCELLED) -> 409 Conflict
            if ($existingActive && strtoupper($existingActive->status) !== 'CANCELLED') {
                $staff = StaffProfile::with('user')->find($staffProfileId);
                $name = $staff?->user?->full_name ?? $staffProfileId;
                $existingActive->loadMissing('shiftType');
                $shiftName = $existingActive->shiftType?->shift_name;
                $detail = $shiftName ? " (Ca '{$shiftName}')" : '';
                throw new HttpException(409, "Trùng lịch trực: Nhân viên '{$name}' đã có lịch trực{$detail} trong ngày ".Carbon::parse($workDate)->format('d/m/Y'));
            }

            // Kiểm tra xung đột thời gian thực tế (kể cả ca đêm)
            $this->validateNoConflict($staffProfileId, $shiftTypeId, $workDate, $existingActive?->id);

            // Nếu bản ghi cũ trước đó đã CANCELLED, ta tái sử dụng hoặc cập nhật để bảo toàn unique key uq_staff_work_date
            if ($existingActive) {
                $existingActive->shift_type_id = $shiftTypeId;
                $existingActive->assigned_location = $location;
                $existingActive->status = $status;
                $existingActive->notes = $notes;
                $existingActive->created_by = $creator?->id ?? $existingActive->created_by;
                $existingActive->created_at = now();
                $existingActive->save();

                return $existingActive;
            }

            return StaffSchedule::create([
                'id' => (string) Str::uuid(),
                'staff_profile_id' => $staffProfileId,
                'shift_type_id' => $shiftTypeId,
                'work_date' => $workDate,
                'assigned_location' => $location,
                'status' => $status,
                'notes' => $notes,
                'created_by' => $creator?->id,
            ]);
        });

        // Tải eager relations để trả về payload đầy đủ
        $schedule->load([
            'staffProfile.user:id,full_name,username,email,phone_number',
            'staffProfile.user.roles:id,role_code,role_name',
            'staffProfile.department:id,department_code,department_name',
            'shiftType:id,shift_code,shift_name,start_time,end_time,break_minutes,is_overnight',
            'creator:id,full_name',
        ]);

        // Phát realtime event ngay lập tức sau khi DB commit
        QuocTinRealtimeService::emit(
            module: 'schedules',
            entity: 'schedule',
            action: 'CREATED',
            entityId: $schedule->id,
            extra: [
                'schedule' => $schedule->toArray(),
                'work_date' => Carbon::parse($schedule->work_date)->format('Y-m-d'),
                'staff_profile_id' => $schedule->staff_profile_id,
            ],
            actorId: $creator?->id,
            triggerCooldown: false
        );

        return $schedule;
    }

    /**
     * Cập nhật thông tin ca trực
     *
     * @param  array<string, mixed>  $data
     *
     * @throws HttpException
     */
    public function updateSchedule(string $id, array $data, ?User $actor): StaffSchedule
    {
        $schedule = DB::transaction(function () use ($id, $data) {
            $item = StaffSchedule::where('id', $id)->lockForUpdate()->first();
            if (! $item) {
                throw new HttpException(404, 'Ca trực không tồn tại hoặc đã được xóa trước đó.');
            }

            $targetStaffId = $data['staff_profile_id'] ?? $item->staff_profile_id;
            $targetShiftId = $data['shift_type_id'] ?? $item->shift_type_id;
            $targetWorkDate = isset($data['work_date']) ? Carbon::parse($data['work_date'])->toDateString() : Carbon::parse($item->work_date)->format('Y-m-d');
            $targetStatus = isset($data['status']) ? strtoupper($data['status']) : $item->status;

            // Nếu thay đổi ngày, nhân viên hoặc loại ca, kiểm tra xung đột
            if (
                $targetStaffId !== $item->staff_profile_id ||
                $targetShiftId !== $item->shift_type_id ||
                $targetWorkDate !== Carbon::parse($item->work_date)->format('Y-m-d')
            ) {
                if ($targetStatus !== 'CANCELLED') {
                    $this->validateNoConflict($targetStaffId, $targetShiftId, $targetWorkDate, $item->id);
                }
            }

            if (isset($data['staff_profile_id'])) {
                $item->staff_profile_id = $data['staff_profile_id'];
            }
            if (isset($data['shift_type_id'])) {
                $item->shift_type_id = $data['shift_type_id'];
            }
            if (isset($data['work_date'])) {
                $item->work_date = $targetWorkDate;
            }
            if (array_key_exists('assigned_location', $data)) {
                $item->assigned_location = $data['assigned_location'];
            }
            if (isset($data['status'])) {
                $item->status = $targetStatus;
            }
            if (array_key_exists('notes', $data)) {
                $item->notes = $data['notes'];
            }

            $item->save();

            return $item;
        });

        $schedule->load([
            'staffProfile.user:id,full_name,username,email,phone_number',
            'staffProfile.user.roles:id,role_code,role_name',
            'staffProfile.department:id,department_code,department_name',
            'shiftType:id,shift_code,shift_name,start_time,end_time,break_minutes,is_overnight',
            'creator:id,full_name',
        ]);

        // Phát realtime event
        QuocTinRealtimeService::emit(
            module: 'schedules',
            entity: 'schedule',
            action: 'UPDATED',
            entityId: $schedule->id,
            extra: [
                'schedule' => $schedule->toArray(),
                'work_date' => Carbon::parse($schedule->work_date)->format('Y-m-d'),
                'staff_profile_id' => $schedule->staff_profile_id,
            ],
            actorId: $actor?->id,
            triggerCooldown: false
        );

        return $schedule;
    }

    /**
     * Xóa hoặc hủy ca trực
     *
     * @return array<string, mixed>
     */
    public function deleteOrCancelSchedule(string $id, string $mode = 'delete', ?User $actor = null): array
    {
        $result = DB::transaction(function () use ($id, $mode) {
            $item = StaffSchedule::where('id', $id)->lockForUpdate()->first();

            // Nếu không tìm thấy bản ghi
            if (! $item) {
                // Nếu người dùng chọn xóa hẳn: Idempotent success (coi như đã xóa thành công)
                if ($mode === 'delete') {
                    return [
                        'action' => 'DELETED',
                        'id' => $id,
                        'work_date' => null,
                        'staff_profile_id' => null,
                        'status' => 'DELETED',
                        'already_deleted' => true,
                    ];
                }

                // Nếu chọn hủy ca nhưng ca trực không tồn tại
                throw new HttpException(404, 'Ca trực không tồn tại hoặc đã được xóa trước đó.');
            }

            // Kiểm tra có dữ liệu chấm công tham chiếu đến schedule này không
            $hasAttendance = DB::table('staff_attendance')
                ->where('schedule_id', $id)
                ->exists();

            $workDate = Carbon::parse($item->work_date)->format('Y-m-d');
            $staffProfileId = $item->staff_profile_id;

            // Nếu đã có chấm công hoặc người dùng chọn hủy ca: chuyển trạng thái CANCELLED
            if ($hasAttendance || $mode === 'cancel') {
                $item->status = 'CANCELLED';
                $item->save();

                return [
                    'action' => 'CANCELLED',
                    'id' => $id,
                    'work_date' => $workDate,
                    'staff_profile_id' => $staffProfileId,
                    'status' => 'CANCELLED',
                ];
            }

            // Nếu chưa có chấm công và muốn xóa hẳn: hard delete
            $item->delete();

            return [
                'action' => 'DELETED',
                'id' => $id,
                'work_date' => $workDate,
                'staff_profile_id' => $staffProfileId,
                'status' => 'DELETED',
            ];
        });

        // Phát realtime event thông báo xóa/hủy ca
        QuocTinRealtimeService::emit(
            module: 'schedules',
            entity: 'schedule',
            action: $result['action'],
            entityId: $id,
            extra: $result,
            actorId: $actor?->id,
            triggerCooldown: false
        );

        return $result;
    }

    /**
     * Lấy danh sách nhân viên đủ điều kiện trực (Lễ tân & Bảo vệ)
     */
    public function getStaffCandidates(?string $roleFilter = null): Collection
    {
        $this->ensureInitialData();

        $query = StaffProfile::query()
            ->with([
                'user:id,full_name,username,email,phone_number',
                'user.roles:id,role_code,role_name',
                'department:id,department_code,department_name',
            ]);

        if (! empty($roleFilter) && strtoupper($roleFilter) !== 'ALL') {
            $roleCode = strtoupper($roleFilter);
            $query->whereHas('user.roles', function ($q) use ($roleCode) {
                $q->where('roles.role_code', $roleCode);
            });
        }

        return $query->get()->sortBy('user.full_name')->values();
    }

    /**
     * Lấy danh sách các loại ca trực
     */
    public function getShiftTypes(): Collection
    {
        $this->ensureInitialData();

        return ShiftType::orderBy('start_time', 'asc')->get();
    }
}

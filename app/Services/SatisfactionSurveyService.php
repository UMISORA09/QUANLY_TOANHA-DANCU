<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\SatisfactionSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SatisfactionSurveyService
{
    public function __construct(
        protected SurveyAccessService $accessService
    ) {}

    /**
     * List surveys with filters, search, and pagination.
     */
    public function listSurveys(array $filters, User $user, int $perPage = 15): LengthAwarePaginator
    {
        $query = SatisfactionSurvey::query()->with(['targetBlock', 'questions']);
        $this->accessService->scopeForManager($user, $query);

        // Search
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('survey_code', 'like', "%{$search}%");
            });
        }

        // Filter by block
        if (! empty($filters['target_block_id'])) {
            $query->where('target_block_id', $filters['target_block_id']);
        }

        // Filter by schedule / status
        $status = strtoupper($filters['status'] ?? 'ALL');
        $today = Carbon::now('Asia/Ho_Chi_Minh')->toDateString();

        if ($status === 'ACTIVE') {
            $query->where('is_active', 1)
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today);
        } elseif ($status === 'UPCOMING') {
            $query->where('is_active', 1)
                ->where('start_date', '>', $today);
        } elseif ($status === 'CLOSED') {
            $query->where('is_active', 1)
                ->where('end_date', '<', $today);
        } elseif ($status === 'DISABLED') {
            $query->where('is_active', 0);
        }

        $paginator = $query->withCount(['responses', 'questions'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Append computed fields
        $paginator->getCollection()->transform(function (SatisfactionSurvey $survey) {
            $eligibleCountQuery = Apartment::query()->whereNull('deleted_at');
            if ($survey->target_block_id) {
                $eligibleCountQuery->where('block_id', $survey->target_block_id);
            }
            $eligibleCount = max(1, $eligibleCountQuery->count());
            $responsesCount = $survey->responses_count ?? 0;

            $survey->setAttribute('computed_status', $survey->computed_status);
            $survey->setAttribute('eligible_count', $eligibleCount);
            $survey->setAttribute('participation_rate_percent', round(($responsesCount / $eligibleCount) * 100, 2));

            return $survey;
        });

        return $paginator;
    }

    /**
     * Create a new satisfaction survey with questions.
     */
    public function createSurvey(array $data, User $creator): SatisfactionSurvey
    {
        abort_unless($this->accessService->isManager($creator) && $creator->hasPermission('SURVEY:CREATE'), 403, 'Bạn không có quyền tạo khảo sát.');

        $assignedBlockId = $this->accessService->assignedBlock($creator);
        if ($assignedBlockId && ! empty($data['target_block_id']) && $data['target_block_id'] !== $assignedBlockId) {
            abort(403, 'Bạn chỉ được tạo khảo sát thuộc tòa nhà bạn đang quản lý.');
        }

        // Date validation in Asia/Ho_Chi_Minh
        $startDate = Carbon::parse($data['start_date'], 'Asia/Ho_Chi_Minh')->startOfDay();
        $endDate = Carbon::parse($data['end_date'], 'Asia/Ho_Chi_Minh')->endOfDay();

        if ($endDate->lt($startDate)) {
            abort(422, 'Ngày kết thúc không được trước ngày bắt đầu.');
        }

        $questionsData = $data['questions'] ?? [];
        if (($data['is_active'] ?? true) && empty($questionsData)) {
            abort(422, 'Khảo sát phải có ít nhất 1 câu hỏi trước khi được kích hoạt.');
        }

        return DB::transaction(function () use ($data, $creator, $questionsData) {
            $surveyCode = ! empty($data['survey_code'])
                ? trim($data['survey_code'])
                : 'SURVEY-'.now()->year.'-'.strtoupper(Str::random(6));

            if (SatisfactionSurvey::where('survey_code', $surveyCode)->exists()) {
                abort(422, "Mã khảo sát '{$surveyCode}' đã tồn tại trong hệ thống.");
            }

            $survey = SatisfactionSurvey::create([
                'id' => (string) Str::uuid(),
                'survey_code' => $surveyCode,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'target_block_id' => ! empty($data['target_block_id']) ? $data['target_block_id'] : null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by' => $creator->id,
                'version' => 1,
            ]);

            $order = 1;
            foreach ($questionsData as $q) {
                SurveyQuestion::create([
                    'id' => (string) Str::uuid(),
                    'survey_id' => $survey->id,
                    'question_order' => $q['question_order'] ?? $order++,
                    'question_text' => trim($q['question_text']),
                    'question_type' => $q['question_type'],
                    'options_json' => $q['options_json'] ?? [],
                    'is_required' => (bool) ($q['is_required'] ?? true),
                ]);
            }

            $this->audit($creator, 'SURVEY_CREATED', $survey->id, "Khảo sát '{$survey->title}' được tạo thành công.");

            return $survey->load(['targetBlock', 'questions']);
        });
    }

    /**
     * Update an existing survey with Optimistic Concurrency Control (OCC).
     */
    public function updateSurvey(SatisfactionSurvey $survey, array $data, User $updater): SatisfactionSurvey
    {
        return DB::transaction(function () use ($survey, $data, $updater) {
            $survey = SatisfactionSurvey::query()->whereKey($survey->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($this->accessService->isManager($updater) && $updater->hasPermission('SURVEY:UPDATE'), 403, 'Bạn không có quyền cập nhật khảo sát.');

            $assignedBlockId = $this->accessService->assignedBlock($updater);
            if ($assignedBlockId && $survey->target_block_id && $survey->target_block_id !== $assignedBlockId) {
                abort(403, 'Bạn không thể chỉnh sửa khảo sát thuộc tòa nhà khác.');
            }

            // 1. OCC Check
            if (isset($data['version']) && (int) $data['version'] !== (int) $survey->version) {
                abort(409, 'Xung đột phiên bản: Khảo sát này đã được cập nhật bởi một quản lý khác. Vui lòng tải lại dữ liệu mới nhất.');
            }

            // 2. Lock question editing if responses already exist
            $hasResponses = $survey->responses()->exists();
            if ($hasResponses && isset($data['questions'])) {
                abort(422, 'Không thể chỉnh sửa danh sách câu hỏi vì khảo sát đã có cư dân nộp phiếu đánh giá.');
            }

            // 3. Date validation
            $startDate = isset($data['start_date']) ? Carbon::parse($data['start_date'], 'Asia/Ho_Chi_Minh')->startOfDay() : Carbon::parse($survey->start_date)->startOfDay();
            $endDate = isset($data['end_date']) ? Carbon::parse($data['end_date'], 'Asia/Ho_Chi_Minh')->endOfDay() : Carbon::parse($survey->end_date)->endOfDay();

            if ($endDate->lt($startDate)) {
                abort(422, 'Ngày kết thúc không được trước ngày bắt đầu.');
            }

            $survey->update([
                'title' => isset($data['title']) ? trim($data['title']) : $survey->title,
                'description' => array_key_exists('description', $data) ? $data['description'] : $survey->description,
                'target_block_id' => array_key_exists('target_block_id', $data) ? ($data['target_block_id'] ?: null) : $survey->target_block_id,
                'start_date' => $data['start_date'] ?? $survey->start_date,
                'end_date' => $data['end_date'] ?? $survey->end_date,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : $survey->is_active,
                'version' => $survey->version + 1,
            ]);

            // If no responses yet and questions provided, sync questions
            if (! $hasResponses && isset($data['questions'])) {
                SurveyQuestion::where('survey_id', $survey->id)->delete();
                $order = 1;
                foreach ($data['questions'] as $q) {
                    SurveyQuestion::create([
                        'id' => (string) Str::uuid(),
                        'survey_id' => $survey->id,
                        'question_order' => $q['question_order'] ?? $order++,
                        'question_text' => trim($q['question_text']),
                        'question_type' => $q['question_type'],
                        'options_json' => $q['options_json'] ?? [],
                        'is_required' => (bool) ($q['is_required'] ?? true),
                    ]);
                }
            }

            $this->audit($updater, 'SURVEY_UPDATED', $survey->id, "Khảo sát '{$survey->title}' được cập nhật lên phiên bản {$survey->version}.");

            return $survey->fresh(['targetBlock', 'questions']);
        }, 3);
    }

    /**
     * Toggle active status.
     */
    public function toggleActive(SatisfactionSurvey $survey, bool $isActive, User $updater): SatisfactionSurvey
    {
        return DB::transaction(function () use ($survey, $isActive, $updater) {
            $survey = SatisfactionSurvey::query()->whereKey($survey->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($this->accessService->isManager($updater) && $updater->hasPermission('SURVEY:MANAGE'), 403, 'Bạn không có quyền thay đổi trạng thái kích hoạt khảo sát.');

            $survey->update([
                'is_active' => $isActive,
                'version' => $survey->version + 1,
            ]);

            $statusStr = $isActive ? 'KÍCH HOẠT' : 'TẠM DỪNG';
            $this->audit($updater, 'SURVEY_STATUS_TOGGLED', $survey->id, "Khảo sát đã chuyển sang trạng thái {$statusStr}.");

            return $survey;
        }, 3);
    }

    protected function audit(User $user, string $action, string $targetId, string $description): void
    {
        if (DB::getSchemaBuilder()->hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'table_name' => 'satisfaction_surveys',
                'record_id' => $targetId,
                'action' => substr($action, 0, 20),
                'performed_by_user_id' => $user->id,
                'new_data' => json_encode(['description' => $description]),
                'created_at' => now(),
            ]);
        }
    }
}

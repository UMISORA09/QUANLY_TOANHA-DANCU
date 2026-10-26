<?php

namespace App\Services;

use App\Models\Suggestion;
use App\Models\SuggestionBox;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ManagementSuggestionService
{
    public function __construct(
        public SuggestionAccessService $access,
        public SuggestionNotificationService $notifications
    ) {}

    /**
     * Get suggestions list with search, filters, pagination and KPIs for managers.
     *
     * @param  array<string, mixed>  $filters
     * @return array{suggestions: LengthAwarePaginator, kpis: array<string, int>}
     */
    public function list(User $user, array $filters = []): array
    {
        $baseQuery = Suggestion::query();
        $this->access->scopeForManager($user, $baseQuery);

        // Calculate KPIs on base scoped query before specific status/search filtering
        $kpiQuery = clone $baseQuery;
        $statusCounts = (clone $kpiQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $negativeSentimentCount = (clone $kpiQuery)
            ->whereIn('ai_sentiment', [Suggestion::SENTIMENT_NEGATIVE, Suggestion::SENTIMENT_VERY_ANGRY])
            ->count();

        $totalCount = array_sum($statusCounts);

        $kpis = [
            'total' => $totalCount,
            'received' => (int) ($statusCounts[Suggestion::STATUS_RECEIVED] ?? 0),
            'reviewing' => (int) ($statusCounts[Suggestion::STATUS_REVIEWING] ?? 0),
            'accepted' => (int) ($statusCounts[Suggestion::STATUS_ACCEPTED] ?? 0),
            'resolved' => (int) ($statusCounts[Suggestion::STATUS_RESOLVED] ?? 0),
            'rejected' => (int) ($statusCounts[Suggestion::STATUS_REJECTED] ?? 0),
            'negative_sentiment' => $negativeSentimentCount,
        ];

        // Apply filters
        $query = $baseQuery->with([
            'box:id,box_name,block_id',
            'box.block:id,block_name,block_code',
            'respondedByStaff:id,full_name',
        ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['topic_category'])) {
            $query->where('topic_category', $filters['topic_category']);
        }

        if (! empty($filters['box_id'])) {
            $query->where('box_id', $filters['box_id']);
        }

        if (! empty($filters['ai_sentiment'])) {
            $query->where('ai_sentiment', $filters['ai_sentiment']);
        }

        if (isset($filters['is_anonymous']) && $filters['is_anonymous'] !== '') {
            $query->where('is_anonymous', (bool) $filters['is_anonymous']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $keyword = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($keyword): void {
                $q->where('title', 'like', $keyword)
                    ->orWhere('content_text', 'like', $keyword);
            });
        }

        $perPage = max(5, min(50, (int) ($filters['per_page'] ?? 15)));
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Sanitize anonymous records: ensure no resident info is ever exposed
        $paginator->getCollection()->transform(function (Suggestion $item) {
            if ($item->is_anonymous) {
                $item->setRelation('residentUser', null);
                $item->setRelation('apartment', null);
                $item->resident_user_id = null;
                $item->apartment_id = null;
            } else {
                $item->loadMissing(['residentUser:id,full_name,phone_number', 'apartment:id,apartment_number,block_id']);
            }

            return $item;
        });

        return [
            'suggestions' => $paginator,
            'kpis' => $kpis,
        ];
    }

    /**
     * Get detail of a suggestion for management.
     */
    public function detail(User $user, string $id): Suggestion
    {
        $query = Suggestion::query();
        $this->access->scopeForManager($user, $query);

        $suggestion = $query->with([
            'box:id,box_name,block_id,description',
            'box.block:id,block_name,block_code',
            'respondedByStaff:id,full_name',
        ])->whereKey($id)->firstOrFail();

        if ($suggestion->is_anonymous) {
            $suggestion->setRelation('residentUser', null);
            $suggestion->setRelation('apartment', null);
            $suggestion->resident_user_id = null;
            $suggestion->apartment_id = null;
        } else {
            $suggestion->loadMissing(['residentUser:id,full_name,phone_number', 'apartment:id,apartment_number,block_id']);
        }

        return $suggestion;
    }

    /**
     * Transition status of a suggestion with State Machine and OCC validation.
     */
    public function updateStatus(
        User $user,
        string $id,
        string $newStatus,
        int $clientVersion,
        ?string $reason = null
    ): Suggestion {
        abort_unless($user->hasPermission('SUGGESTION:REPLY'), 403, 'Bạn không có quyền cập nhật trạng thái góp ý.');

        return DB::transaction(function () use ($user, $id, $newStatus, $clientVersion, $reason): Suggestion {
            $query = Suggestion::query();
            $this->access->scopeForManager($user, $query);

            /** @var Suggestion $suggestion */
            $suggestion = $query->lockForUpdate()->whereKey($id)->firstOrFail();

            // 1. Optimistic Concurrency Control (OCC)
            if ($suggestion->version !== $clientVersion) {
                throw new HttpException(
                    409,
                    'Góp ý đã được cập nhật bởi một người quản lý khác. Vui lòng tải lại dữ liệu để xem phiên bản mới nhất.'
                );
            }

            // 2. State Machine Validation
            $validTransitions = [
                Suggestion::STATUS_RECEIVED => [
                    Suggestion::STATUS_REVIEWING,
                    Suggestion::STATUS_REJECTED,
                ],
                Suggestion::STATUS_REVIEWING => [
                    Suggestion::STATUS_ACCEPTED,
                    Suggestion::STATUS_RESOLVED,
                    Suggestion::STATUS_REJECTED,
                ],
                Suggestion::STATUS_ACCEPTED => [
                    Suggestion::STATUS_RESOLVED,
                    Suggestion::STATUS_REJECTED,
                ],
                Suggestion::STATUS_RESOLVED => [],
                Suggestion::STATUS_REJECTED => [],
            ];

            $currentStatus = $suggestion->status;
            $allowedTargets = $validTransitions[$currentStatus] ?? [];

            if (! in_array($newStatus, $allowedTargets, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Không thể chuyển trạng thái góp ý từ '{$currentStatus}' sang '{$newStatus}'."],
                ]);
            }

            if ($newStatus === Suggestion::STATUS_REJECTED && empty(trim((string) $reason))) {
                throw ValidationException::withMessages([
                    'rejection_reason' => ['Bắt buộc phải nhập lý do khi từ chối góp ý.'],
                ]);
            }

            $oldData = $suggestion->toArray();

            $suggestion->status = $newStatus;
            $suggestion->version = $suggestion->version + 1;
            if ($newStatus === Suggestion::STATUS_REJECTED && $reason !== null) {
                $suggestion->rejection_reason = trim($reason);
            }
            $suggestion->save();

            // Record audit log
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'table_name' => 'suggestions',
                'record_id' => $suggestion->id,
                'action' => 'UPDATE',
                'performed_by_user_id' => $user->id,
                'old_data' => json_encode(['status' => $currentStatus, 'version' => $clientVersion], JSON_THROW_ON_ERROR),
                'new_data' => json_encode(['status' => $newStatus, 'version' => $suggestion->version, 'rejection_reason' => $reason], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $suggestion;
        });
    }

    /**
     * Submit official reply with OCC validation.
     */
    public function reply(
        User $user,
        string $id,
        string $responseText,
        int $clientVersion,
        ?string $newStatus = null
    ): Suggestion {
        abort_unless($user->hasPermission('SUGGESTION:REPLY'), 403, 'Bạn không có quyền phản hồi góp ý.');

        $trimmedResponse = trim($responseText);
        if (empty($trimmedResponse)) {
            throw ValidationException::withMessages([
                'official_response_text' => ['Nội dung phản hồi không được để trống.'],
            ]);
        }

        $suggestion = DB::transaction(function () use (
            $user,
            $id,
            $trimmedResponse,
            $clientVersion,
            $newStatus
        ): Suggestion {
            $query = Suggestion::query();
            $this->access->scopeForManager($user, $query);

            /** @var Suggestion $suggestion */
            $suggestion = $query->lockForUpdate()->whereKey($id)->firstOrFail();

            // OCC check
            if ($suggestion->version !== $clientVersion) {
                throw new HttpException(
                    409,
                    'Góp ý đã được phản hồi hoặc cập nhật bởi một người quản lý khác. Vui lòng tải lại dữ liệu.'
                );
            }

            $oldData = [
                'official_response_text' => $suggestion->official_response_text,
                'status' => $suggestion->status,
                'version' => $suggestion->version,
            ];

            $suggestion->official_response_text = $trimmedResponse;
            $suggestion->responded_by_staff_id = $user->id;
            $suggestion->responded_at = now();
            $suggestion->version = $suggestion->version + 1;

            if ($newStatus && in_array($newStatus, [
                Suggestion::STATUS_REVIEWING,
                Suggestion::STATUS_ACCEPTED,
                Suggestion::STATUS_RESOLVED,
                Suggestion::STATUS_REJECTED,
            ], true)) {
                $suggestion->status = $newStatus;
            }

            $suggestion->save();

            // Audit log
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'table_name' => 'suggestions',
                'record_id' => $suggestion->id,
                'action' => 'UPDATE',
                'performed_by_user_id' => $user->id,
                'old_data' => json_encode($oldData, JSON_THROW_ON_ERROR),
                'new_data' => json_encode([
                    'official_response_text' => $trimmedResponse,
                    'status' => $suggestion->status,
                    'version' => $suggestion->version,
                    'responded_by_staff_id' => $user->id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $suggestion;
        });

        // Notify resident if non-anonymous
        $this->notifications->notifyResidentOnOfficialReply($suggestion);

        return $suggestion->fresh([
            'box:id,box_name,block_id',
            'respondedByStaff:id,full_name',
        ]);
    }

    /**
     * Create a new suggestion box.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBox(User $user, array $data): SuggestionBox
    {
        abort_unless($user->hasPermission('SUGGESTION:MANAGE'), 403, 'Bạn không có quyền quản lý hòm thư góp ý.');

        $box = SuggestionBox::create([
            'id' => (string) Str::uuid(),
            'box_name' => trim((string) $data['box_name']),
            'block_id' => ! empty($data['block_id']) ? $data['block_id'] : null,
            'description' => ! empty($data['description']) ? trim((string) $data['description']) : null,
            'is_open' => isset($data['is_open']) ? (bool) $data['is_open'] : true,
        ]);

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'table_name' => 'suggestion_boxes',
            'record_id' => $box->id,
            'action' => 'INSERT',
            'performed_by_user_id' => $user->id,
            'new_data' => json_encode($box->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return $box;
    }
}

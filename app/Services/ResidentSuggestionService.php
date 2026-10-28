<?php

namespace App\Services;

use App\Models\Suggestion;
use App\Models\SuggestionBox;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResidentSuggestionService
{
    public function __construct(
        public SuggestionAccessService $access,
        public SuggestionAttachmentService $attachments,
        public AiSentimentService $aiSentiment,
        public SuggestionNotificationService $notifications
    ) {}

    /**
     * Get accessible and open suggestion boxes for resident.
     *
     * @return Collection<int, SuggestionBox>
     */
    public function boxes(User $user): Collection
    {
        $apartments = $this->access->residentApartments($user);
        if ($apartments->isEmpty()) {
            return collect();
        }

        $blockIds = $apartments->pluck('block_id')->filter()->unique()->values()->all();

        return SuggestionBox::with('block:id,block_name,block_code')
            ->where('is_open', true)
            ->where(function (Builder $query) use ($blockIds): void {
                $query->whereNull('block_id');
                if (! empty($blockIds)) {
                    $query->orWhereIn('block_id', $blockIds);
                }
            })
            ->orderBy('box_name')
            ->get();
    }

    /**
     * Submit a new suggestion.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     * @return array{suggestion: Suggestion, anonymous_token: ?string}
     */
    public function submit(User $user, array $data, array $files = []): array
    {
        // 1. Idempotency control
        $idempotencyKey = ! empty($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;
        if ($idempotencyKey) {
            $existing = Suggestion::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return [
                    'suggestion' => $existing,
                    'anonymous_token' => null,
                ];
            }
        }

        // 2. Validate box
        $box = SuggestionBox::findOrFail($data['box_id']);
        if (! $box->is_open) {
            throw ValidationException::withMessages([
                'box_id' => ['Hòm thư góp ý này hiện đang tạm đóng tiếp nhận.'],
            ]);
        }

        // 3. Validate resident permission for box
        if (! $this->access->canResidentSubmitToBox($user, $box)) {
            throw ValidationException::withMessages([
                'box_id' => ['Bạn không thuộc khối/tòa nhà được phân quyền gửi góp ý vào hòm thư này.'],
            ]);
        }

        $isAnonymous = ! empty($data['is_anonymous']);
        $anonymousRawToken = null;
        $anonymousTokenHash = null;
        $residentUserId = null;
        $apartmentId = null;

        if ($isAnonymous) {
            // High entropy random token for anonymous lookup
            $anonymousRawToken = 'SC-'.Str::upper(Str::random(8)).'-'.Str::upper(Str::random(8));
            $anonymousTokenHash = hash('sha256', $anonymousRawToken);
        } else {
            $residentUserId = $user->id;
            $apartments = $this->access->residentApartments($user);
            if (! empty($data['apartment_id'])) {
                $chosen = $apartments->firstWhere('id', $data['apartment_id']);
                if (! $chosen) {
                    throw ValidationException::withMessages([
                        'apartment_id' => ['Căn hộ được chọn không hợp lệ với tài khoản cư dân của bạn.'],
                    ]);
                }
                $apartmentId = $chosen->id;
            } else {
                $apartmentId = $apartments->first()?->id;
            }
        }

        $suggestion = DB::transaction(function () use (
            $box,
            $idempotencyKey,
            $residentUserId,
            $apartmentId,
            $isAnonymous,
            $anonymousTokenHash,
            $data,
            $files,
            $user
        ): Suggestion {
            $suggestionId = (string) Str::uuid();

            // Store files if present
            $attachedFiles = ! empty($files)
                ? $this->attachments->storeAttachments($suggestionId, $files)
                : [];

            $suggestion = Suggestion::create([
                'id' => $suggestionId,
                'box_id' => $box->id,
                'idempotency_key' => $idempotencyKey,
                'resident_user_id' => $residentUserId,
                'apartment_id' => $apartmentId,
                'is_anonymous' => $isAnonymous,
                'anonymous_access_token_hash' => $anonymousTokenHash,
                'topic_category' => $data['topic_category'],
                'title' => trim((string) $data['title']),
                'content_text' => trim((string) $data['content_text']),
                'attached_files' => $attachedFiles,
                'status' => Suggestion::STATUS_RECEIVED,
                'version' => 1,
                'ai_sentiment' => Suggestion::SENTIMENT_NOT_ANALYZED,
                'ai_sentiment_score' => null,
            ]);

            // Audit log: strictly omit user identity if anonymous
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'table_name' => 'suggestions',
                'record_id' => $suggestion->id,
                'action' => 'INSERT',
                'performed_by_user_id' => $isAnonymous ? null : $user->id,
                'new_data' => json_encode([
                    'id' => $suggestion->id,
                    'box_id' => $box->id,
                    'is_anonymous' => $isAnonymous,
                    'topic_category' => $suggestion->topic_category,
                    'title' => $suggestion->title,
                    'status' => $suggestion->status,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $suggestion;
        });

        // Trigger AI sentiment (gracefully fails/blocks if no AI provider configured)
        $this->aiSentiment->analyzeSuggestion($suggestion);

        // Notify managers
        $this->notifications->notifyManagersOnNewSuggestion($suggestion);

        return [
            'suggestion' => $suggestion->fresh(['box:id,box_name,block_id', 'apartment:id,apartment_number']),
            'anonymous_token' => $anonymousRawToken,
        ];
    }

    /**
     * Get list of suggestions submitted by the logged in resident (non-anonymous).
     *
     * @param  array<string, mixed>  $filters
     */
    public function mySuggestions(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = Suggestion::with([
            'box:id,box_name',
            'apartment:id,apartment_number',
            'respondedByStaff:id,full_name',
        ])
            ->where('resident_user_id', $user->id)
            ->where('is_anonymous', false);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['topic_category'])) {
            $query->where('topic_category', $filters['topic_category']);
        }

        if (! empty($filters['search'])) {
            $keyword = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($keyword): void {
                $q->where('title', 'like', $keyword)
                    ->orWhere('content_text', 'like', $keyword);
            });
        }

        $perPage = max(5, min(50, (int) ($filters['per_page'] ?? 10)));

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Lookup an anonymous suggestion by its secure tracking token.
     *
     * @return array<string, mixed>
     */
    public function anonymousLookup(string $rawToken): array
    {
        $token = trim($rawToken);
        if (empty($token) || strlen($token) < 10) {
            abort(404, 'Mã tra cứu không hợp lệ hoặc không tìm thấy góp ý.');
        }

        $tokenHash = hash('sha256', $token);

        $suggestion = Suggestion::with(['box:id,box_name'])
            ->where('anonymous_access_token_hash', $tokenHash)
            ->where('is_anonymous', true)
            ->first();

        abort_unless($suggestion, 404, 'Mã tra cứu không hợp lệ hoặc không tìm thấy góp ý tương ứng.');

        return [
            'id' => $suggestion->id,
            'box_name' => $suggestion->box?->box_name,
            'topic_category' => $suggestion->topic_category,
            'title' => $suggestion->title,
            'content_text' => $suggestion->content_text,
            'status' => $suggestion->status,
            'attached_files' => $suggestion->attached_files ?? [],
            'official_response_text' => $suggestion->official_response_text,
            'rejection_reason' => $suggestion->rejection_reason,
            'responded_at' => $suggestion->responded_at?->toIso8601String(),
            'created_at' => $suggestion->created_at->toIso8601String(),
        ];
    }
}

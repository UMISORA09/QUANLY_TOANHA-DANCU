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
        $submissionKey = trim((string) ($data['idempotency_key'] ?? ''));
        $submissionHash = $submissionKey === '' ? null : hash_hmac('sha256', $user->id.':'.$submissionKey, config('app.key'));
        $anonymous = (bool) ($data['is_anonymous'] ?? false);
        $fileFingerprints = array_map(fn (UploadedFile $file): array => [
            'name' => $file->getClientOriginalName(), 'sha256' => hash_file('sha256', $file->getRealPath()),
        ], $files);
        $fingerprint = hash('sha256', json_encode([
            'box' => $data['box_id'], 'anonymous' => $anonymous,
            'apartment' => $anonymous ? null : ($data['apartment_id'] ?? null),
            'topic' => $data['topic_category'], 'title' => trim($data['title']),
            'content' => trim($data['content_text']), 'files' => $fileFingerprints,
        ], JSON_THROW_ON_ERROR));
        $storedFiles = [];
        $rawToken = null;
        $replayed = false;
        try {
            $suggestion = DB::transaction(function () use ($user, $data, $files, $submissionHash, $fingerprint, $anonymous, &$storedFiles, &$rawToken, &$replayed): Suggestion {
                // Serialize submissions for this authenticated account without recording its identity on anonymous rows.
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                $box = SuggestionBox::whereKey($data['box_id'])->lockForUpdate()->firstOrFail();
                if (! $this->access->canResidentSubmitToBox($user, $box)) {
                    throw ValidationException::withMessages(['box_id' => ['Bạn không thuộc phạm vi hòm thư này.']]);
                }
                if ($submissionHash !== null) {
                    $existing = Suggestion::where('submission_key_hash', $submissionHash)->first();
                    if ($existing) {
                        abort_unless(hash_equals($existing->payload_fingerprint, $fingerprint), 409, 'Mã gửi đã được dùng với nội dung khác.');
                        $replayed = true;

                        return $existing;
                    }
                }
                if (! $box->is_open) {
                    throw ValidationException::withMessages(['box_id' => ['Hòm thư đã đóng tiếp nhận.']]);
                }
                $apartmentId = null;
                if (! $anonymous) {
                    $apartments = $this->access->residentApartments($user);
                    $apartment = empty($data['apartment_id']) ? $apartments->first() : $apartments->firstWhere('id', $data['apartment_id']);
                    if (! $apartment || ($box->block_id !== null && $apartment->block_id !== $box->block_id)) {
                        throw ValidationException::withMessages(['apartment_id' => ['Căn hộ không thuộc phạm vi hòm thư.']]);
                    }
                    $apartmentId = $apartment->id;
                }
                $id = (string) Str::uuid();
                $rawToken = $anonymous ? 'SC-'.Str::random(40) : null;
                $storedFiles = $this->attachments->storeAttachments($id, $files);
                $suggestion = Suggestion::create([
                    'id' => $id, 'box_id' => $box->id, 'submission_key_hash' => $submissionHash,
                    'payload_fingerprint' => $fingerprint, 'idempotency_key' => null,
                    'resident_user_id' => $anonymous ? null : $user->id, 'apartment_id' => $apartmentId,
                    'is_anonymous' => $anonymous, 'anonymous_access_token_hash' => $rawToken ? hash('sha256', $rawToken) : null,
                    'topic_category' => $data['topic_category'], 'title' => trim($data['title']),
                    'content_text' => trim($data['content_text']), 'attached_files' => $storedFiles,
                    'status' => Suggestion::STATUS_RECEIVED, 'version' => 1,
                    'ai_sentiment' => Suggestion::SENTIMENT_NOT_ANALYZED,
                ]);
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid(), 'table_name' => 'suggestions', 'record_id' => $id,
                    'action' => 'INSERT', 'performed_by_user_id' => $anonymous ? null : $user->id,
                    'new_data' => json_encode(['id' => $id, 'box_id' => $box->id, 'is_anonymous' => $anonymous, 'status' => $suggestion->status], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                $this->notifications->notifyManagersOnNewSuggestion($suggestion);

                return $suggestion;
            });
        } catch (\Throwable $exception) {
            $this->attachments->deleteAttachments($storedFiles);
            throw $exception;
        }
        if (! $replayed) {
            DB::afterCommit(fn () => $this->aiSentiment->analyzeSuggestion($suggestion));
        }

        return [
            'suggestion' => $suggestion->fresh(['box:id,box_name,block_id', 'apartment:id,apartment_number']),
            'anonymous_token' => $rawToken, 'replayed' => $replayed,
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

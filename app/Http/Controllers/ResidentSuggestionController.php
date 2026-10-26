<?php

namespace App\Http\Controllers;

use App\Models\Suggestion;
use App\Models\User;
use App\Services\ResidentSuggestionService;
use App\Services\SuggestionAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResidentSuggestionController extends Controller
{
    public function __construct(
        public ResidentSuggestionService $service,
        public SuggestionAttachmentService $attachmentService
    ) {}

    /**
     * Get accessible suggestion boxes for the current resident.
     */
    public function boxes(Request $request): JsonResponse
    {
        $boxes = $this->service->boxes($request->user());

        return response()->json([
            'success' => true,
            'data' => $boxes,
        ]);
    }

    /**
     * Submit a new suggestion.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'box_id' => 'required|uuid|exists:suggestion_boxes,id',
            'topic_category' => 'required|string|in:AN_NINH,VE_SINH,BAI_XE,TIEN_ICH,THAI_DO_NHAN_VIEN,KHAC',
            'title' => 'required|string|min:5|max:255',
            'content_text' => 'required|string|min:10|max:5000',
            'is_anonymous' => 'nullable|boolean',
            'apartment_id' => 'nullable|uuid|exists:apartments,id',
            'idempotency_key' => 'nullable|string|max:64',
            'attached_files' => 'nullable|array|max:5',
            'attached_files.*' => 'file|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            'box_id.required' => 'Vui lòng chọn hòm thư nhận góp ý.',
            'topic_category.required' => 'Vui lòng chọn chủ đề góp ý.',
            'topic_category.in' => 'Chủ đề góp ý không hợp lệ.',
            'title.required' => 'Tiêu đề góp ý không được để trống.',
            'title.min' => 'Tiêu đề cần tối thiểu 5 ký tự.',
            'content_text.required' => 'Nội dung góp ý không được để trống.',
            'content_text.min' => 'Nội dung cần tối thiểu 10 ký tự để mô tả chi tiết.',
            'attached_files.max' => 'Chỉ được tải lên tối đa 5 hình ảnh đính kèm.',
            'attached_files.*.mimes' => 'Hình ảnh phải có định dạng JPG, PNG hoặc WebP.',
            'attached_files.*.max' => 'Mỗi hình ảnh không được vượt quá 5MB.',
        ]);

        $files = $request->file('attached_files', []);
        $result = $this->service->submit($request->user(), $data, is_array($files) ? $files : [$files]);

        return response()->json([
            'success' => true,
            'message' => 'Gửi ý kiến đóng góp thành công.',
            'data' => $result['suggestion'],
            'anonymous_token' => $result['anonymous_token'],
        ], 201);
    }

    /**
     * Get suggestions submitted by the logged in resident.
     */
    public function mySuggestions(Request $request): JsonResponse
    {
        $paginator = $this->service->mySuggestions($request->user(), $request->all());

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Public rate-limited anonymous suggestion lookup.
     */
    public function anonymousLookup(Request $request): JsonResponse
    {
        $token = $request->input('token', $request->header('X-Anonymous-Token'));
        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng cung cấp mã tra cứu góp ý ẩn danh.',
            ], 422);
        }

        $data = $this->service->anonymousLookup((string) $token);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * View or download an attachment.
     */
    public function showAttachment(Request $request, string $suggestionId, string $fileId): StreamedResponse
    {
        $suggestion = Suggestion::findOrFail($suggestionId);
        $user = $request->user();

        if (! $user && $request->hasHeader('Authorization')) {
            $authHeader = (string) $request->header('Authorization');
            if (str_starts_with($authHeader, 'Bearer ')) {
                $token = trim(substr($authHeader, 7));
                $tokenHash = hash('sha256', $token);
                $session = DB::table('user_sessions')
                    ->where(function ($q) use ($token, $tokenHash): void {
                        $q->where('refresh_token_hash', $tokenHash)->orWhere('refresh_token_hash', $token);
                    })
                    ->where('is_revoked', 0)
                    ->where('expires_at', '>', now())
                    ->first();
                if ($session) {
                    $user = User::with('roles.permissions')->find($session->user_id);
                }
            }
        }

        $anonToken = $request->query('anon_token', $request->header('X-Anonymous-Token'));

        return $this->attachmentService->downloadAttachment(
            $suggestion,
            $fileId,
            $user,
            $anonToken
        );
    }
}

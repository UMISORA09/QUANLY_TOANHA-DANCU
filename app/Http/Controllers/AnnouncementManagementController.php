<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveAnnouncementRequest;
use App\Services\AnnouncementService;
use App\Services\AnnouncementUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AnnouncementManagementController extends Controller
{
    public function __construct(public AnnouncementService $announcements) {}

    private function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100', 'category' => 'nullable|in:'.implode(',', array_keys(AnnouncementService::CATEGORIES)), 'target_scope' => 'nullable|in:ALL_BLOCKS,BLOCK,FLOOR', 'block_id' => 'nullable|uuid', 'status' => 'nullable|in:DRAFT,PUBLISHED,UNPUBLISHED,SCHEDULED,EXPIRED']);
        $query = $this->announcements->query($request->user());
        foreach (['category', 'target_scope'] as $key) {
            if (! empty($data[$key])) {
                $query->where($key, $data[$key]);
            }
        }
        if (! empty($data['block_id'])) {
            $query->where('target_block_id', $data['block_id']);
        }
        if (! empty($data['search'])) {
            $query->where('title', 'like', '%'.$data['search'].'%');
        }
        $status = $data['status'] ?? null;
        if (in_array($status, ['DRAFT', 'UNPUBLISHED'], true)) {
            $query->where('announcements.status', $status);
        }
        if (in_array($status, ['PUBLISHED', 'SCHEDULED', 'EXPIRED'], true)) {
            $query->where('announcements.status', 'PUBLISHED');
            if ($status === 'EXPIRED') {
                $query->where('expires_at', '<=', now());
            } else {
                $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->where('publish_at', $status === 'SCHEDULED' ? '>' : '<=', now());
            }
        }
        $page = $query->leftJoin('users as author', 'author.id', '=', 'announcements.author_user_id')->select('announcements.*', 'author.full_name as author_name')->orderByDesc('announcements.created_at')->orderByDesc('announcements.id')->paginate(15);
        $page->through(fn (object $row) => $this->announcements->serialize($request->user(), $row, false));

        return $this->json($page);
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $block = $this->announcements->block($user);
        $blocks = DB::table('blocks')->whereNull('deleted_at')->when($block, fn ($q) => $q->where('id', $block))->orderBy('block_name')->get(['id', 'block_name']);
        $floors = DB::table('floors')->whereNull('deleted_at')->whereIn('block_id', $blocks->pluck('id'))->orderBy('floor_number')->get(['id', 'block_id', 'floor_name']);

        return $this->json(['timezone' => config('app.timezone'), 'categories' => AnnouncementService::CATEGORIES, 'blocks' => $blocks, 'floors' => $floors, 'can_target_all' => ! $block, 'can_create' => $user->hasPermission('ANNOUNCEMENT:CREATE'), 'can_publish' => $user->hasPermission('ANNOUNCEMENT:PUBLISH')]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->announcements->serialize($request->user(), $this->announcements->find($request->user(), $id)));
    }

    public function store(SaveAnnouncementRequest $request): JsonResponse
    {
        return $this->json($this->announcements->save($request->user(), null, $request->validated()), 201);
    }

    public function update(SaveAnnouncementRequest $request, string $id): JsonResponse
    {
        return $this->json($this->announcements->save($request->user(), $id, $request->validated()));
    }

    public function preview(Request $request): JsonResponse
    {
        $this->announcements->block($request->user());
        abort_unless($request->user()->hasPermission('ANNOUNCEMENT:CREATE') || $request->user()->hasPermission('ANNOUNCEMENT:UPDATE'), 403);
        $data = $request->validate(['content_html' => 'required|string|max:250000']);
        $html = $this->announcements->clean($data['content_html']);
        if (strlen($html) > 60 * 1024 || ! preg_match('/[^\s\x{00A0}]/u', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
            throw ValidationException::withMessages(['content_html' => 'Nội dung trống hoặc vượt 60 KB sau khi lọc.']);
        }

        return $this->json(['content_html' => $html]);
    }

    private function perform(Request $request, string $id, string $action): JsonResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'publish_at' => 'nullable|date', 'reason' => $action === 'publish' ? 'nullable|string|max:500' : 'required|string|min:5|max:500']);
        $this->announcements->action($request->user(), $id, $action, $data);

        return $action === 'archive' ? $this->json(['success' => true]) : $this->show($request, $id);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        return $this->perform($request, $id, 'publish');
    }

    public function unpublish(Request $request, string $id): JsonResponse
    {
        return $this->perform($request, $id, 'unpublish');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->perform($request, $id, 'archive');
    }

    public function attachment(Request $request, string $id, string $attachmentId): Response
    {
        $this->announcements->find($request->user(), $id);
        $file = DB::table('announcement_attachments')->where('id', $attachmentId)->where('announcement_id', $id)->firstOrFail();
        abort_unless($this->announcements->safeFile($file, $id), 404);
        $disk = Storage::disk($file->storage_disk);
        abort_unless($disk->exists($file->file_url), 404);

        if ($file->storage_disk !== 'local') {
            return $this->json(['download_url' => $disk->temporaryUrl($file->file_url, now()->addMinute(), ['ResponseContentDisposition' => 'attachment; filename="'.rawurlencode($file->file_name).'"', 'ResponseContentType' => $file->mime_type])]);
        }

        return $disk->download($file->file_url, $file->file_name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function prepareUpload(Request $request): JsonResponse
    {
        $data = $request->validate(['announcement_id' => 'required|uuid', 'file_name' => 'required|string|max:255', 'size' => 'required|integer|min:1|max:5242880', 'mime_type' => 'required|in:image/jpeg,image/png,image/webp,application/pdf']);

        return $this->json(app(AnnouncementUploadService::class)->prepare($request->user(), $data));
    }

    public function uploadLocal(Request $request, string $token): JsonResponse
    {
        $stream = $request->getContent(true);
        try {
            app(AnnouncementUploadService::class)->local($request->user(), $token, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->json(['success' => true]);
    }

    public function discardUpload(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:4096']);
        $this->announcements->block($request->user());
        app(AnnouncementUploadService::class)->discard($request->user(), $data['token']);

        return $this->json(['success' => true]);
    }
}

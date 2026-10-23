<?php

namespace App\Http\Controllers;

use App\Services\AnnouncementService;
use App\Services\ResidentAnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ResidentAnnouncementController extends Controller
{
    public function __construct(public ResidentAnnouncementService $announcements) {}

    private function json(mixed $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100', 'category' => 'nullable|in:'.implode(',', array_keys(AnnouncementService::CATEGORIES)), 'filter' => 'nullable|in:unread,acknowledgement']);
        $user = $request->user();
        $query = $this->announcements->withReceipt($this->announcements->query($user), $user);
        $unread = (clone $query)->whereNull('receipt.read_at')->count();
        if (! empty($data['search'])) {
            $query->where('announcement.title', 'like', '%'.$data['search'].'%');
        }
        if (! empty($data['category'])) {
            $query->where('announcement.category', $data['category']);
        }
        if (($data['filter'] ?? '') === 'unread') {
            $query->whereNull('receipt.read_at');
        }
        if (($data['filter'] ?? '') === 'acknowledgement') {
            $query->where('announcement.requires_acknowledgement', 1)->whereNull('receipt.acknowledged_at');
        }
        $page = $query->orderByDesc('announcement.is_pinned')->orderByDesc('announcement.publish_at')->orderByDesc('announcement.id')->paginate(15);
        $page->through(fn (object $row) => $this->announcements->serialize($row));

        return $this->json([...$page->toArray(), 'unread_count' => $unread, 'categories' => AnnouncementService::CATEGORIES, 'timezone' => config('app.timezone')]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->announcements->serialize($this->announcements->find($request->user(), $id), true));
    }

    public function read(Request $request, string $id): JsonResponse
    {
        return $this->record($request, $id, false);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        return $this->record($request, $id, true);
    }

    private function record(Request $request, string $id, bool $acknowledge): JsonResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'user_id' => 'prohibited', 'read_at' => 'prohibited', 'acknowledged_at' => 'prohibited']);

        return $this->json($this->announcements->receipt($request->user(), $id, (int) $data['version'], $acknowledge));
    }

    public function attachment(Request $request, string $id, string $attachmentId): Response
    {
        $this->announcements->find($request->user(), $id);
        $file = DB::table('announcement_attachments')->where('id', $attachmentId)->where('announcement_id', $id)->firstOrFail();
        abort_unless($this->announcements->announcements->safeFile($file, $id), 404);
        $disk = Storage::disk($file->storage_disk);
        abort_unless($disk->exists($file->file_url), 404);
        if ($file->storage_disk !== 'local') {
            return $this->json(['download_url' => $disk->temporaryUrl($file->file_url, now()->addMinute(), ['ResponseContentDisposition' => 'attachment; filename="'.rawurlencode($file->file_name).'"', 'ResponseContentType' => $file->mime_type])]);
        }

        return $disk->download($file->file_url, $file->file_name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}

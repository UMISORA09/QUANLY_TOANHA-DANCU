<?php

namespace App\Http\Controllers;

use App\Services\ManagerNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerNotificationController extends Controller
{
    public function __construct(public ManagerNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'unread' => 'nullable|boolean', 'category' => 'nullable|in:TICKET,AMENITY_BOOKING']);
        $query = $this->notifications->inbox($request->user());
        $unread = (clone $query)->where('is_read', 0)->count();
        $latest = (clone $query)->orderByDesc('created_at')->orderByDesc('id')->value('id');
        $query->when($data['category'] ?? null, fn (Builder $q, string $category) => $q->where('category', $category))
            ->when($request->boolean('unread'), fn (Builder $q) => $q->where('is_read', 0));
        $pages = max(1, (int) ceil((clone $query)->count() / 15));
        $page = min((int) ($data['page'] ?? 1), $pages);

        $items = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, 15)->get(['n.id', 'title', 'body_message', 'deep_link_url', 'category', 'is_read', 'created_at']);
        $items->each(function (object $item): void {
            $item->created_at = Carbon::parse($item->created_at, config('app.timezone'))->toIso8601String();
        });

        return response()->json(['items' => $items,
            'unread_count' => $unread, 'latest_id' => $latest, 'page' => $page, 'total_pages' => $pages])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $query = $this->notifications->inbox($request->user())->where('n.id', $id);
        abort_unless((clone $query)->exists(), 404);
        $query->where('is_read', 0)->update(['is_read' => 1, 'read_at' => now()]);

        return response()->json(['unread_count' => $this->notifications->inbox($request->user())->where('is_read', 0)->count()])->header('Cache-Control', 'private, no-store');
    }
}

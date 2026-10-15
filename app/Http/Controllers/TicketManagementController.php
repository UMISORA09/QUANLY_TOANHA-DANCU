<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\ResidentTicketService;
use App\Services\TicketManagementService;
use App\Services\TicketWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketManagementController extends Controller
{
    public function __construct(public TicketManagementService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100', 'status' => 'nullable|in:NEW,RECEIVED,IN_PROGRESS,RESOLVED,CLOSED,CANCELLED', 'priority' => 'nullable|in:LOW,MEDIUM,HIGH,URGENT', 'category_id' => 'nullable|uuid', 'block_id' => 'nullable|uuid']);
        $query = $this->tickets->access->scope($request->user(), Ticket::query())->with(['category', 'apartment.block', 'creator:id,full_name', 'technician:id,full_name']);
        foreach (['status', 'priority', 'category_id'] as $key) {
            if (! empty($data[$key])) {
                $query->where($key, $data[$key]);
            }
        }
        if (! empty($data['block_id'])) {
            $query->whereHas('apartment', fn ($q) => $q->where('block_id', $data['block_id']));
        }
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$data['search'].'%')->orWhere('ticket_number', 'like', '%'.$data['search'].'%')->orWhere('location', 'like', '%'.$data['search'].'%'));
        }
        $page = $query->orderByDesc('created_at')->orderBy('id')->paginate(15);
        $page->getCollection()->each(fn (Ticket $ticket) => $ticket->setAttribute('actions', $this->tickets->access->actions($request->user(), $ticket)));

        return response()->json($page);
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->tickets->access->scope($user, Ticket::query());
        $block = $this->tickets->access->block($user);
        $blocks = DB::table('blocks')->whereNull('deleted_at')->when($block, fn ($q) => $q->where('id', $block))->select('id', 'block_name')->orderBy('block_name')->get();
        $apartments = DB::table('apartments')->whereNull('deleted_at')->whereIn('block_id', $blocks->pluck('id'))->select('id', 'block_id', 'apartment_number')->orderBy('apartment_number')->get();
        $manager = $this->tickets->access->manager($user);
        $residents = $manager ? DB::table('residents')->join('users', 'users.id', '=', 'residents.user_id')
            ->whereIn('residents.apartment_id', $apartments->pluck('id'))->where('residents.is_active', 1)->whereNull('residents.deleted_at')
            ->whereDate('stay_start_date', '<=', today())->where(fn ($q) => $q->whereNull('stay_end_date')->orWhereDate('stay_end_date', '>=', today()))
            ->where('users.status', 'ACTIVE')->whereNull('users.deleted_at')->select('users.id', 'users.full_name', 'residents.apartment_id')->distinct()->get() : collect();

        return response()->json(['categories' => TicketCategory::orderBy('category_name')->get(), 'blocks' => $blocks, 'apartments' => $manager ? $apartments : [], 'residents' => $residents,
            'can_create' => $manager && $user->hasPermission('TICKET:CREATE')]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tickets->detail($request->user(), $id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->tickets->access->manage($request->user(), 'TICKET:CREATE');
        $data = $request->validate(['reporter_user_id' => 'required|uuid', 'apartment_id' => 'required|uuid', 'title' => 'required|string|max:200', 'description' => 'required|string|min:5', 'location' => 'required|string|max:255', 'category_id' => 'required|uuid|exists:ticket_categories,id', 'priority' => 'required|in:LOW,MEDIUM,HIGH,URGENT', 'preferred_service_time' => 'nullable|date', 'idempotency_key' => 'required|string|max:100']);
        $block = $this->tickets->access->block($request->user());
        abort_unless(DB::table('apartments')->where('id', $data['apartment_id'])->whereNull('deleted_at')->when($block, fn ($q) => $q->where('block_id', $block))->exists(), 404);
        $resident = User::where('status', 'ACTIVE')->findOrFail($data['reporter_user_id']);
        $ticket = DB::transaction(function () use ($request, $resident, $data) {
            $ticket = app(ResidentTicketService::class)->create($resident, $data);
            if ($ticket->wasRecentlyCreated) {
                DB::table('ticket_history')->where('ticket_id', $ticket->id)->where('action_type', 'CREATED')->update(['performed_by_user_id' => $request->user()->id, 'notes' => 'Nhân viên tạo yêu cầu hộ cư dân.']);
                DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'table_name' => 'tickets', 'record_id' => $ticket->id, 'action' => 'INSERT', 'performed_by_user_id' => $request->user()->id, 'new_data' => json_encode($ticket->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }

            return $ticket;
        });

        return response()->json($this->tickets->detail($request->user(), $ticket->id), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'category_id' => 'sometimes|required|uuid|exists:ticket_categories,id', 'priority' => 'sometimes|required|in:LOW,MEDIUM,HIGH,URGENT', 'title' => 'sometimes|required|string|max:200', 'description' => 'sometimes|required|string|min:5', 'location' => 'sometimes|required|string|max:255', 'preferred_service_time' => 'nullable|date', 'status' => 'prohibited', 'creator_user_id' => 'prohibited', 'apartment_id' => 'prohibited']);

        return response()->json($this->tickets->update($request->user(), $id, $data));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:5|max:500']);
        $this->tickets->archive($request->user(), $id, $data);

        return response()->json(['success' => true]);
    }

    public function workflow(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:receive,assign,start,resolve,cancel', 'technician_user_id' => 'required_if:action,assign|nullable|uuid', 'note' => 'required_if:action,resolve|nullable|string|min:5|max:500', 'reason' => 'required_if:action,cancel|nullable|string|min:5|max:500']);
        app(TicketWorkflowService::class)->perform($request->user(), $id, $data['action'], (int) $data['version'], $data);

        return response()->json($this->tickets->detail($request->user(), $id));
    }

    public function technicians(Request $request, string $id): JsonResponse
    {
        $this->tickets->access->manage($request->user(), 'TICKET:ASSIGN');
        $ticket = $this->tickets->detail($request->user(), $id);
        $block = $ticket->apartment->block_id;
        $users = User::where('status', 'ACTIVE')->whereHas('roles', fn ($q) => $q->whereIn('role_code', ['TECHNICIAN', 'TECHNICAL_STAFF']))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('staff_profiles')->whereColumn('staff_profiles.user_id', 'users.id')->where(fn ($scope) => $scope->whereNull('assigned_block_id')->orWhere('assigned_block_id', $block)))->get();

        return response()->json($users->filter(fn (User $user) => $this->tickets->access->technician($user) && $user->hasPermission('TICKET:VIEW') && $user->hasPermission('TICKET:UPDATE') && $user->hasPermission('TICKET:RESOLVE'))->map(fn (User $user) => ['id' => $user->id, 'full_name' => $user->full_name])->values());
    }

    public function notifications(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1']);
        $query = DB::table('user_in_app_notifications')->where('recipient_user_id', $request->user()->id)->where('category', 'TICKET');
        $unread = (clone $query)->where('is_read', 0)->count();
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(15, ['id', 'title', 'body_message', 'deep_link_url', 'is_read', 'created_at'], 'page', $data['page'] ?? 1);

        return response()->json(['items' => $page->items(), 'unread_count' => $unread, 'page' => $page->currentPage(), 'total_pages' => $page->lastPage()]);
    }

    public function readNotification(Request $request, string $id): JsonResponse
    {
        $query = DB::table('user_in_app_notifications')->where('recipient_user_id', $request->user()->id)->where('category', 'TICKET')->where('id', $id);
        abort_unless((clone $query)->exists(), 404);
        $query->where('is_read', 0)->update(['is_read' => 1, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }
}

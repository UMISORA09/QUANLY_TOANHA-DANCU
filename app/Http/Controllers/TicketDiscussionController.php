<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketCommentRequest;
use App\Services\TicketDiscussionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketDiscussionController extends Controller
{
    public function __construct(public TicketDiscussionService $discussion) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['cursor' => 'nullable|string|max:2048', 'since' => 'nullable|string|date|max:40']);

        return response()->json($this->discussion->comments($request->user(), $id, $data['cursor'] ?? null, $data['since'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['cursor' => 'nullable|string|max:2048']);

        return response()->json($this->discussion->history($request->user(), $id, $data['cursor'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreTicketCommentRequest $request, string $id): JsonResponse
    {
        $result = $this->discussion->store($request->user(), $id, $request->validated());

        return response()->json(['data' => $result['data']], $result['created'] ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}

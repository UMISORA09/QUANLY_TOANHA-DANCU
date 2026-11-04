<?php

namespace App\Http\Controllers;

use App\Services\TicketRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketRatingController extends Controller
{
    public function __construct(public TicketRatingService $ratings) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->ratings->show($request->user(), $id))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'rating_stars' => 'required|integer|between:1,5',
            'review_comment' => 'nullable|string|max:1000',
        ]);
        $result = $this->ratings->store($request->user(), $id, $data);

        return response()->json(['data' => $result['data'], 'can_rate' => $result['can_rate'], 'ticket_version' => $result['ticket_version']], $result['created'] ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }
}

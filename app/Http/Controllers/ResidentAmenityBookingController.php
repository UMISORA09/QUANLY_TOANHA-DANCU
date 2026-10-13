<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResidentAmenityBookingRequest;
use App\Services\ResidentAmenityBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResidentAmenityBookingController extends Controller
{
    public function __construct(public ResidentAmenityBookingService $bookings) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->bookings->catalog($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function notifications(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1', 'unread' => 'nullable|boolean']);

        return response()->json($this->bookings->notifications($request->user(), 'AMENITY', $data))->header('Cache-Control', 'private, no-store');
    }

    public function readNotification(Request $request, string $id): JsonResponse
    {
        $this->bookings->readNotification($request->user(), 'AMENITY', $id);

        return response()->json(['success' => true]);
    }

    public function availability(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'apartment_id' => 'nullable|uuid']);

        return response()->json($this->bookings->availability($request->user(), $id, $data['date'], $data['apartment_id'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function store(ResidentAmenityBookingRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'booking' => $this->bookings->create($request->user(), $request->validated(), $request)], 201);
    }

    public function bookings(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => 'nullable|in:PENDING,APPROVED,CONFIRMED,CHECKED_IN,CANCELLED,REJECTED,COMPLETED', 'page' => 'nullable|integer|min:1']);

        return response()->json($this->bookings->list($request->user(), $data['status'] ?? null, (int) ($data['page'] ?? 1)))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->bookings->detail($request->user(), $id))->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return response()->json(['success' => true, 'booking' => $this->bookings->cancel($request->user(), $id, $data['reason'] ?? null, $request)]);
    }

    public function cancelAlias(Request $request, string $amenityId, string $bookingId): JsonResponse
    {
        abort_unless($this->bookings->detail($request->user(), $bookingId)['amenity_id'] === $amenityId, 404);

        return $this->cancel($request, $bookingId);
    }
}

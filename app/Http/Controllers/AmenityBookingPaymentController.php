<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmAmenityBookingPaymentRequest;
use App\Services\AmenityBookingPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AmenityBookingPaymentController extends Controller
{
    public function __construct(public AmenityBookingPaymentService $payments) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['payment' => $this->payments->resident($request, $id)])->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, string $id): JsonResponse
    {
        return response()->json(['payment' => $this->payments->resident($request, $id, true)]);
    }

    public function confirm(ConfirmAmenityBookingPaymentRequest $request, string $amenityId, string $bookingId): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['payment' => $this->payments->decide($request, $amenityId, $bookingId, $data)]);
    }

    public function reject(Request $request, string $amenityId, string $bookingId): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        return response()->json(['payment' => $this->payments->decide($request, $amenityId, $bookingId, $data, true)]);
    }
}

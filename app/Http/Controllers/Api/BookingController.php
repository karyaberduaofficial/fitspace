<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\BookingService;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly PaymentGatewayService $payments,
        private readonly AuditService $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['required', 'integer', 'exists:class_schedules,id'],
            'child_id' => ['nullable', 'integer', 'exists:children,id'],
            'booking_type' => ['required', 'in:per_session,membership'],
            'gateway' => ['nullable', 'in:midtrans,xendit'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'auto_confirm_demo' => ['nullable', 'boolean'],
        ]);

        $result = $this->bookings->createBooking($request->user(), $validated);

        // Only in explicitly enabled demo_mode when caller requests auto_confirm_demo
        if (
            ! empty($validated['auto_confirm_demo'])
            && config('fitspace.demo_mode', false)
            && ! empty($result['payment'])
        ) {
            $paid = $this->payments->markPaymentPaid((int) $result['payment']->id, 'DEMO-CONFIRMED');
            $result['payment'] = $paid['payment'];
            $result['qr'] = $paid['qr'];
            $result['booking'] = DB::table('bookings')->where('id', $result['booking']->id)->first();
        }

        PublicController::clearBootstrapCache();

        return response()->json([
            'message' => $result['booking']->status === 'confirmed'
                ? 'Booking kelas terkonfirmasi dan QR Code sekali pakai telah diterbitkan.'
                : 'Booking dibuat. Silakan selesaikan pembayaran melalui payment gateway.',
            'booking' => $result['booking'],
            'payment' => $result['payment'],
            'qr' => $result['qr'],
        ], 201);
    }

    public function reschedule(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'new_schedule_id' => ['required', 'integer', 'exists:class_schedules,id'],
            'reason' => ['required', 'string', 'max:250'],
        ]);

        $booking = $this->bookings->rescheduleBooking(
            $request->user(),
            $id,
            (int) $validated['new_schedule_id'],
            $validated['reason']
        );

        PublicController::clearBootstrapCache();

        return response()->json([
            'message' => 'Reschedule jadwal berhasil diproses dan tercatat di riwayat.',
            'booking' => $booking,
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:250'],
        ]);

        $booking = $this->bookings->cancelBooking($request->user(), $id, $validated['reason']);
        PublicController::clearBootstrapCache();

        return response()->json([
            'message' => 'Booking berhasil dibatalkan.',
            'booking' => $booking,
        ]);
    }

    public function storeReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:600'],
        ]);

        $user = $request->user();
        $booking = DB::table('bookings')->where('id', $validated['booking_id'])->first();
        if (! $booking || (int) $booking->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Akses ditolak: Anda hanya dapat mengulas sesi milik Anda.'], 403);
        }
        if ($booking->status !== 'attended') {
            return response()->json(['message' => 'Ulasan hanya dapat dikirim setelah kehadiran sesi tercatat.'], 422);
        }
        if (DB::table('coach_reviews')->where('booking_id', $booking->id)->exists()) {
            return response()->json(['message' => 'Ulasan untuk sesi ini sudah pernah dikirim.'], 409);
        }

        $schedule = DB::table('class_schedules')->where('id', $booking->schedule_id)->first();
        $now = now();

        $reviewId = DB::table('coach_reviews')->insertGetId([
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'coach_id' => $schedule->coach_id,
            'class_id' => $schedule->class_id,
            'schedule_id' => $schedule->id,
            'rating' => (int) $validated['rating'],
            'review' => $validated['review'] ?? null,
            'reviewed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->log($user->id, 'coach.reviewed', 'coach_review', $reviewId, [
            'coach_id' => $schedule->coach_id,
            'rating' => (int) $validated['rating'],
        ], $request);

        return response()->json(['message' => 'Terima kasih! Rating & ulasan Coach tersimpan.', 'id' => $reviewId], 201);
    }
}

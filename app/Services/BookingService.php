<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BookingService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function getSettingInt(string $key, int $default): int
    {
        $val = DB::table('settings')->where('key', $key)->value('value');

        return is_numeric($val) ? (int) $val : $default;
    }

    public function issueQrCode(int $bookingId, Carbon|string $expiresAt): array
    {
        $secret = (string) (config('fitspace.qr_signing_secret') ?: config('app.key'));
        if ($secret === '') {
            throw new \RuntimeException('QR signing key is not configured.');
        }
        $displayCode = 'FS-QR-'.strtoupper(Str::random(6));
        $tokenHash = hash_hmac('sha256', $displayCode.'|'.$bookingId, $secret);
        $expStr = Carbon::parse($expiresAt)->copy()->addHours(2)->format('Y-m-d H:i:s');
        $now = now();

        $existing = DB::table('attendance_qr_codes')->where('booking_id', $bookingId)->first();
        if ($existing) {
            DB::table('attendance_qr_codes')->where('id', $existing->id)->update([
                'expires_at' => $expStr,
                'updated_at' => $now,
            ]);

            return ['display_code' => $existing->display_code, 'token_hash' => $existing->token_hash];
        }

        DB::table('attendance_qr_codes')->insert([
            'booking_id' => $bookingId,
            'token_hash' => $tokenHash,
            'display_code' => $displayCode,
            'expires_at' => $expStr,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['display_code' => $displayCode, 'token_hash' => $tokenHash];
    }

    public function createBooking(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            $schedule = DB::table('class_schedules')
                ->where('id', $data['schedule_id'])
                ->lockForUpdate()
                ->first();

            if (! $schedule || $schedule->status !== 'scheduled') {
                throw ValidationException::withMessages(['schedule_id' => ['Jadwal kelas tidak tersedia.']]);
            }

            if (Carbon::parse($schedule->starts_at)->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['schedule_id' => ['Jadwal kelas sudah dimulai atau berlalu.']]);
            }

            // Business Rule #6: Capacity enforcement inside transaction lock
            if ((int) $schedule->booked_count >= (int) $schedule->capacity) {
                throw ValidationException::withMessages(['schedule_id' => ['Kuota jadwal kelas sudah penuh.']]);
            }

            $class = DB::table('classes as c')
                ->join('class_categories as cat', 'cat.id', '=', 'c.category_id')
                ->select('c.*', 'cat.slug as category_slug', 'cat.name as category_name')
                ->where('c.id', $schedule->class_id)
                ->first();

            if (! $class || ! $class->is_active) {
                throw ValidationException::withMessages(['schedule_id' => ['Kelas tidak aktif.']]);
            }

            // Child & Guardian validation (Anti-IDOR + Business Rule #17 Age Range)
            $childId = isset($data['child_id']) && $data['child_id'] ? (int) $data['child_id'] : null;
            if ($childId !== null) {
                $guardian = DB::table('guardians')->where('user_id', $user->id)->first();
                $child = DB::table('children')->where('id', $childId)->where('is_active', true)->first();

                if (! $child) {
                    throw ValidationException::withMessages(['child_id' => ['Profil anak tidak ditemukan.']]);
                }
                if ($user->role !== 'admin' && (! $guardian || (int) $child->guardian_id !== (int) $guardian->id)) {
                    throw new HttpException(403, 'Akses ditolak: Anda hanya dapat mendaftarkan anak di bawah perwalian Anda.');
                }

                $age = Carbon::parse($child->birth_date)->age;
                if ($age < (int) $class->min_age || $age > (int) $class->max_age) {
                    throw ValidationException::withMessages([
                        'child_id' => ["Usia anak ({$age} thn) berada di luar rentang kelas {$class->name} ({$class->min_age}–{$class->max_age} thn)."],
                    ]);
                }
            } elseif ($user->role === 'guardian' && (int) $class->max_age <= 14) {
                throw ValidationException::withMessages([
                    'child_id' => ['Silakan pilih profil anak untuk mendaftar kelas anak.'],
                ]);
            }

            // Prevent duplicate active booking for same user/child on same schedule
            $dupQuery = DB::table('bookings')
                ->where('schedule_id', $schedule->id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['pending_payment', 'confirmed', 'attended']);
            if ($childId !== null) {
                $dupQuery->where('child_id', $childId);
            } else {
                $dupQuery->whereNull('child_id');
            }
            if ($dupQuery->exists()) {
                throw ValidationException::withMessages(['schedule_id' => ['Peserta sudah terdaftar pada jadwal kelas ini.']]);
            }

            $now = now();
            $bookingType = ($data['booking_type'] ?? 'per_session') === 'membership' ? 'membership' : 'per_session';
            $grossAmount = (float) $class->price_per_session;
            $discountAmount = 0.0;
            $finalAmount = $grossAmount;
            $couponId = null;
            $membershipId = null;
            $status = 'pending_payment';

            if ($bookingType === 'membership') {
                // Business Rule #9: Validate active membership, expiration, quota, allowed category & daily limit
                $membership = DB::table('memberships as m')
                    ->join('membership_plans as p', 'p.id', '=', 'm.plan_id')
                    ->select('m.*', 'p.allowed_categories', 'p.usage_limit_per_day', 'p.includes_children')
                    ->where('m.user_id', $user->id)
                    ->where('m.status', 'active')
                    ->where('m.expires_at', '>', $now)
                    ->where('m.remaining_quota', '>', 0)
                    ->lockForUpdate()
                    ->first();

                if (! $membership) {
                    throw ValidationException::withMessages([
                        'booking_type' => ['Membership aktif atau sisa kuota tidak tersedia. Gunakan booking per sesi atau beli paket membership.'],
                    ]);
                }

                if ($membership->allowed_categories) {
                    $allowed = json_decode((string) $membership->allowed_categories, true);
                    if (is_array($allowed) && count($allowed) > 0 && ! in_array($class->category_slug, $allowed, true)) {
                        throw ValidationException::withMessages([
                            'booking_type' => ["Membership Anda tidak mencakup kategori kelas {$class->category_name}."],
                        ]);
                    }
                }

                $todayUsed = DB::table('bookings')
                    ->where('membership_id', $membership->id)
                    ->whereDate('created_at', $now->toDateString())
                    ->whereIn('status', ['confirmed', 'attended'])
                    ->count();

                if ($todayUsed >= (int) $membership->usage_limit_per_day) {
                    throw ValidationException::withMessages([
                        'booking_type' => ['Batas pemakaian harian membership Anda sudah tercapai hari ini.'],
                    ]);
                }

                DB::table('memberships')->where('id', $membership->id)->update([
                    'remaining_quota' => (int) $membership->remaining_quota - 1,
                    'updated_at' => $now,
                ]);

                $membershipId = (int) $membership->id;
                $finalAmount = 0.0;
                $status = 'confirmed';
            } else {
                // Optional coupon validation
                $couponCode = trim((string) ($data['coupon_code'] ?? ''));
                if ($couponCode !== '') {
                    $coupon = DB::table('coupons as c')
                        ->join('promotions as p', 'p.id', '=', 'c.promotion_id')
                        ->select('c.*', 'p.discount_type', 'p.discount_value', 'p.starts_at', 'p.ends_at', 'p.is_active as promo_active')
                        ->whereRaw('UPPER(c.code) = ?', [strtoupper($couponCode)])
                        ->where('c.is_active', true)
                        ->lockForUpdate()
                        ->first();

                    if (! $coupon || ! $coupon->promo_active) {
                        throw ValidationException::withMessages(['coupon_code' => ['Kode voucher tidak valid atau sudah tidak aktif.']]);
                    }
                    if ($coupon->starts_at && $now->lt(Carbon::parse($coupon->starts_at))) {
                        throw ValidationException::withMessages(['coupon_code' => ['Voucher belum memasuki masa berlaku.']]);
                    }
                    if ($coupon->ends_at && $now->gt(Carbon::parse($coupon->ends_at))) {
                        throw ValidationException::withMessages(['coupon_code' => ['Voucher sudah kedaluwarsa.']]);
                    }
                    if ((int) $coupon->used_count >= (int) $coupon->max_uses) {
                        throw ValidationException::withMessages(['coupon_code' => ['Kuota penggunaan voucher sudah habis.']]);
                    }
                    if ($grossAmount < (float) $coupon->min_transaction) {
                        throw ValidationException::withMessages(['coupon_code' => ['Nominal transaksi belum memenuhi minimal penggunaan voucher.']]);
                    }

                    $discountAmount = $coupon->discount_type === 'percent'
                        ? round($grossAmount * ((float) $coupon->discount_value / 100), 2)
                        : min($grossAmount, (float) $coupon->discount_value);
                    $finalAmount = max(0, $grossAmount - $discountAmount);
                    $couponId = (int) $coupon->id;

                    DB::table('coupons')->where('id', $couponId)->increment('used_count');
                }
            }

            $bookingCode = 'FS-BK-'.date('Y').'-'.strtoupper(Str::random(6));
            $bookingId = DB::table('bookings')->insertGetId([
                'booking_code' => $bookingCode,
                'user_id' => $user->id,
                'child_id' => $childId,
                'schedule_id' => $schedule->id,
                'membership_id' => $membershipId,
                'coupon_id' => $couponId,
                'booking_type' => $bookingType,
                'gross_amount' => $grossAmount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'status' => $status,
                'reschedule_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $memberId = DB::table('members')->where('user_id', $user->id)->value('id');
            DB::table('booking_participants')->insert([
                'booking_id' => $bookingId,
                'participant_type' => $childId ? 'child' : 'member',
                'member_id' => $childId ? null : $memberId,
                'child_id' => $childId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('class_schedules')->where('id', $schedule->id)->increment('booked_count');

            if ($couponId) {
                DB::table('coupon_usages')->insert([
                    'coupon_id' => $couponId,
                    'user_id' => $user->id,
                    'booking_id' => $bookingId,
                    'discount_applied' => $discountAmount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $qr = null;
            $payment = null;

            if ($bookingType === 'membership') {
                DB::table('membership_transactions')->insert([
                    'membership_id' => $membershipId,
                    'booking_id' => $bookingId,
                    'type' => 'deduct_quota',
                    'quota_delta' => -1,
                    'notes' => "Pemakaian 1 kuota untuk booking {$bookingCode}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $qr = $this->issueQrCode($bookingId, $schedule->ends_at);
            } else {
                $gateway = ($data['gateway'] ?? config('fitspace.payment.default_gateway', 'midtrans')) === 'xendit' ? 'xendit' : 'midtrans';
                $orderId = 'INV-'.date('Ymd').'-'.strtoupper(Str::random(6));
                $paymentId = DB::table('payments')->insertGetId([
                    'order_id' => $orderId,
                    'user_id' => $user->id,
                    'branch_id' => $schedule->branch_id,
                    'booking_id' => $bookingId,
                    'gateway' => $gateway,
                    'payment_method' => $gateway === 'xendit' ? 'va_invoice' : 'qris',
                    'amount' => $finalAmount,
                    'status' => 'pending',
                    'expired_at' => $now->copy()->addHours(2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $payment = DB::table('payments')->where('id', $paymentId)->first();
            }

            $this->audit->log($user->id, 'booking.created', 'booking', $bookingId, [
                'booking_code' => $bookingCode,
                'booking_type' => $bookingType,
                'schedule_id' => $schedule->id,
                'child_id' => $childId,
                'final_amount' => $finalAmount,
            ]);

            $this->audit->notify(
                $user->id,
                'booking_created',
                $status === 'confirmed' ? 'Booking Kelas Terkonfirmasi' : 'Menunggu Pembayaran Booking',
                "Booking {$bookingCode} untuk {$class->name} telah dibuat.",
                ['booking_id' => $bookingId]
            );

            return [
                'booking' => DB::table('bookings')->where('id', $bookingId)->first(),
                'payment' => $payment,
                'qr' => $qr,
            ];
        });
    }

    public function rescheduleBooking(User $user, int $bookingId, int $newScheduleId, string $reason): object
    {
        return DB::transaction(function () use ($user, $bookingId, $newScheduleId, $reason) {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            if (! $booking) {
                throw new HttpException(404, 'Booking tidak ditemukan.');
            }
            if ($user->role !== 'admin' && (int) $booking->user_id !== (int) $user->id) {
                throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin mengubah booking ini.');
            }
            if ($booking->status !== 'confirmed') {
                throw ValidationException::withMessages(['booking' => ['Hanya booking berstatus confirmed yang dapat di-reschedule.']]);
            }
            if ((int) $booking->schedule_id === $newScheduleId) {
                throw ValidationException::withMessages(['new_schedule_id' => ['Jadwal pengganti harus berbeda dari jadwal sebelumnya.']]);
            }

            $oldSchedule = DB::table('class_schedules')->where('id', $booking->schedule_id)->lockForUpdate()->first();
            $newSchedule = DB::table('class_schedules')->where('id', $newScheduleId)->lockForUpdate()->first();

            if (! $newSchedule || $newSchedule->status !== 'scheduled') {
                throw ValidationException::withMessages(['new_schedule_id' => ['Jadwal pengganti tidak tersedia.']]);
            }
            if ((int) $newSchedule->booked_count >= (int) $newSchedule->capacity) {
                throw ValidationException::withMessages(['new_schedule_id' => ['Kuota jadwal pengganti sudah penuh.']]);
            }
            if (Carbon::parse($newSchedule->starts_at)->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['new_schedule_id' => ['Jadwal pengganti harus berada di masa mendatang.']]);
            }

            // Business Rule #10: Hard H-8 Hours enforcement + max reschedule count
            $cutoffHours = $this->getSettingInt('reschedule_cutoff_hours', (int) config('fitspace.reschedule_cutoff_hours', 8));
            $maxCount = $this->getSettingInt('max_reschedule_count', (int) config('fitspace.max_reschedule_count', 1));

            if ((int) $booking->reschedule_count >= $maxCount) {
                throw ValidationException::withMessages([
                    'booking' => ["Batas maksimal reschedule ({$maxCount}x) untuk booking ini telah tercapai."],
                ]);
            }

            $hoursBeforeStart = round(now()->diffInMinutes(Carbon::parse($oldSchedule->starts_at), false) / 60, 2);
            if ($hoursBeforeStart < $cutoffHours) {
                throw ValidationException::withMessages([
                    'booking' => ["Reschedule ditolak: perubahan jadwal minimal H-{$cutoffHours} jam sebelum kelas dimulai (tersisa {$hoursBeforeStart} jam)."],
                ]);
            }

            $now = now();
            if ((int) $oldSchedule->booked_count > 0) {
                DB::table('class_schedules')->where('id', $oldSchedule->id)->decrement('booked_count');
            }
            DB::table('class_schedules')->where('id', $newSchedule->id)->increment('booked_count');

            DB::table('bookings')->where('id', $booking->id)->update([
                'schedule_id' => $newSchedule->id,
                'reschedule_count' => (int) $booking->reschedule_count + 1,
                'updated_at' => $now,
            ]);

            DB::table('reschedule_histories')->insert([
                'booking_id' => $booking->id,
                'from_schedule_id' => $oldSchedule->id,
                'to_schedule_id' => $newSchedule->id,
                'requested_by' => $user->id,
                'reason' => $reason,
                'hours_before_start' => max(0, $hoursBeforeStart),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('attendance_qr_codes')->where('booking_id', $booking->id)->update([
                'expires_at' => Carbon::parse($newSchedule->ends_at)->copy()->addHours(2)->format('Y-m-d H:i:s'),
                'updated_at' => $now,
            ]);

            $this->audit->log($user->id, 'booking.rescheduled', 'booking', $booking->id, [
                'from_schedule_id' => $oldSchedule->id,
                'to_schedule_id' => $newSchedule->id,
                'hours_before_start' => $hoursBeforeStart,
            ]);

            $this->audit->notify(
                (int) $booking->user_id,
                'booking_rescheduled',
                'Reschedule Jadwal Berhasil',
                "Booking {$booking->booking_code} berhasil dipindahkan ke jadwal baru.",
                ['booking_id' => $booking->id, 'new_schedule_id' => $newSchedule->id]
            );

            return DB::table('bookings')->where('id', $booking->id)->first();
        });
    }

    public function cancelBooking(User $user, int $bookingId, string $reason): object
    {
        return DB::transaction(function () use ($user, $bookingId, $reason) {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            if (! $booking) {
                throw new HttpException(404, 'Booking tidak ditemukan.');
            }
            if ($user->role !== 'admin' && (int) $booking->user_id !== (int) $user->id) {
                throw new HttpException(403, 'Akses ditolak: Anda tidak berhak membatalkan booking ini.');
            }
            if (! in_array($booking->status, ['pending_payment', 'confirmed'], true)) {
                throw ValidationException::withMessages(['booking' => ['Booking tidak dapat dibatalkan pada status saat ini.']]);
            }

            $now = now();
            DB::table('bookings')->where('id', $booking->id)->update([
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancel_reason' => $reason,
                'updated_at' => $now,
            ]);

            $sched = DB::table('class_schedules')->where('id', $booking->schedule_id)->lockForUpdate()->first();
            if ($sched && (int) $sched->booked_count > 0) {
                DB::table('class_schedules')->where('id', $sched->id)->decrement('booked_count');
            }

            if ($booking->booking_type === 'membership' && $booking->membership_id) {
                DB::table('memberships')->where('id', $booking->membership_id)->increment('remaining_quota');
                DB::table('membership_transactions')->insert([
                    'membership_id' => $booking->membership_id,
                    'booking_id' => $booking->id,
                    'type' => 'refund_quota',
                    'quota_delta' => 1,
                    'notes' => "Pengembalian 1 kuota pembatalan booking {$booking->booking_code}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Expire unused QR code immediately
            DB::table('attendance_qr_codes')->where('booking_id', $booking->id)->whereNull('used_at')->update([
                'expires_at' => $now->copy()->subMinute(),
                'updated_at' => $now,
            ]);

            $this->audit->log($user->id, 'booking.cancelled', 'booking', $booking->id, [
                'reason' => $reason,
            ]);

            return DB::table('bookings')->where('id', $booking->id)->first();
        });
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AttendanceService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function scanQr(User $actor, string $qrToken, string $status = 'present', ?string $notes = null): array
    {
        $cleanToken = trim($qrToken);
        if ($cleanToken === '') {
            throw ValidationException::withMessages(['qr_token' => ['Token atau kode QR wajib diisi.']]);
        }

        $allowedStatuses = ['present', 'late', 'excused', 'absent'];
        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'present';
        }

        return DB::transaction(function () use ($actor, $cleanToken, $status, $notes) {
            $secret = (string) (config('fitspace.qr_signing_secret') ?: config('app.key'));
            if ($secret === '') {
                throw new \RuntimeException('QR signing key is not configured.');
            }
            $computedHash = hash_hmac('sha256', $cleanToken, $secret);

            $qr = DB::table('attendance_qr_codes')
                ->where('display_code', $cleanToken)
                ->orWhere('token_hash', $cleanToken)
                ->orWhere('token_hash', $computedHash)
                ->lockForUpdate()
                ->first();

            if (! $qr) {
                throw new HttpException(404, 'QR Code kehadiran tidak valid atau tidak ditemukan.');
            }

            // Business Rule #11: Single-use anti-replay check
            if ($qr->used_at !== null) {
                throw new HttpException(409, 'QR Code ini sudah pernah digunakan (anti-duplikasi absensi).');
            }

            if (Carbon::parse($qr->expires_at)->isPast()) {
                throw ValidationException::withMessages(['qr_token' => ['QR Code sudah kedaluwarsa.']]);
            }

            $booking = DB::table('bookings')->where('id', $qr->booking_id)->lockForUpdate()->first();
            if (! $booking || $booking->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'qr_token' => ['Absensi ditolak: booking belum terkonfirmasi atau sudah dibatalkan/hadir.'],
                ]);
            }

            $schedule = DB::table('class_schedules')->where('id', $booking->schedule_id)->first();
            if (! $schedule) {
                throw new HttpException(404, 'Jadwal kelas tidak ditemukan.');
            }

            // Coach authorization check: coach must be assigned to this schedule
            if ($actor->role === 'coach') {
                $coachId = DB::table('coaches')->where('user_id', $actor->id)->value('id');
                $assigned = $coachId && (
                    (int) $schedule->coach_id === (int) $coachId
                    || DB::table('schedule_coaches')->where('schedule_id', $schedule->id)->where('coach_id', $coachId)->exists()
                );
                if (! $assigned) {
                    throw new HttpException(403, 'Akses ditolak: Anda bukan Coach yang bertugas pada jadwal kelas ini.');
                }
            }

            $now = now();
            DB::table('attendance_qr_codes')->where('id', $qr->id)->update([
                'used_at' => $now,
                'used_by' => $actor->id,
                'updated_at' => $now,
            ]);

            $attendanceId = DB::table('attendance')->insertGetId([
                'booking_id' => $booking->id,
                'schedule_id' => $schedule->id,
                'user_id' => $booking->user_id,
                'child_id' => $booking->child_id,
                'recorded_by' => $actor->id,
                'status' => $status,
                'checked_in_at' => $now,
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('bookings')->where('id', $booking->id)->update([
                'status' => 'attended',
                'updated_at' => $now,
            ]);

            $this->audit->log($actor->id, 'attendance.qr_scanned', 'attendance', $attendanceId, [
                'booking_id' => $booking->id,
                'display_code' => $qr->display_code,
                'status' => $status,
            ]);

            $this->audit->notify(
                (int) $booking->user_id,
                'attendance_checked_in',
                'Kehadiran Kelas Tercatat',
                "Absensi untuk booking {$booking->booking_code} berhasil dicatat dengan status {$status}.",
                ['attendance_id' => $attendanceId, 'booking_id' => $booking->id]
            );

            return [
                'attendance' => DB::table('attendance')->where('id', $attendanceId)->first(),
                'booking_code' => $booking->booking_code,
            ];
        });
    }
}

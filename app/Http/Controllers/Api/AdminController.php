<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BookingService;
use App\Services\FinanceService;
use App\Services\PaymentGatewayService;
use App\Services\ScheduleConflictService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly ScheduleConflictService $scheduler,
        private readonly PaymentGatewayService $payments,
        private readonly AuditService $audit,
        private readonly BookingService $bookings,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $branchId = is_numeric($request->query('branch_id')) ? (int) $request->query('branch_id') : null;
        $finSummary = $this->finance->getSummary($branchId);

        $confirmedBookings = DB::table('bookings')->whereIn('status', ['confirmed', 'attended'])->count();
        $totalCap = (int) DB::table('class_schedules')->where('status', 'scheduled')->sum('capacity');
        $totalBooked = (int) DB::table('class_schedules')->where('status', 'scheduled')->sum('booked_count');
        $occupancy = $totalCap > 0 ? round(($totalBooked / $totalCap) * 100, 1) : 0.0;

        $recentPayments = DB::table('payments as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->select('p.id', 'p.order_id', 'p.gateway', 'p.amount', 'p.status', 'p.created_at', 'u.name as user_name')
            ->orderByDesc('p.created_at')
            ->limit(10)
            ->get();

        $catRows = DB::table('bookings as bk')
            ->join('class_schedules as s', 's.id', '=', 'bk.schedule_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('class_categories as cat', 'cat.id', '=', 'c.category_id')
            ->select('cat.name', DB::raw('COUNT(*) as total'))
            ->whereIn('bk.status', ['confirmed', 'attended', 'pending_payment'])
            ->groupBy('cat.name')
            ->orderByDesc('total')
            ->get();
        $catSum = max(1, (int) $catRows->sum('total'));
        $categoryBreakdown = $catRows->map(fn ($r) => [
            'name' => $r->name,
            'count' => (int) $r->total,
            'pct' => round(((int) $r->total / $catSum) * 100, 1),
        ])->values()->all();

        return response()->json([
            'finance' => $finSummary,
            'category_breakdown' => $categoryBreakdown,
            'stats' => [
                'confirmed_bookings' => $confirmedBookings,
                'occupancy_rate' => $occupancy,
                'active_classes' => DB::table('classes')->where('is_active', true)->count(),
                'active_coaches' => DB::table('coaches')->where('is_active', true)->count(),
                'branches_count' => DB::table('branches')->where('is_active', true)->count(),
                'active_memberships' => DB::table('memberships')->where('status', 'active')->where('expires_at', '>', now())->count(),
                'pending_coaches' => DB::table('users')->where('role', 'coach')->where('approval_status', 'pending')->count(),
                'pending_availabilities' => DB::table('coach_availabilities')->where('status', 'pending')->count(),
                'pending_coach_reschedules' => DB::table('coach_schedule_reschedules')->where('status', 'pending')->count(),
                'pending_bookings' => DB::table('bookings')->where('status', 'pending_payment')->count(),
                'pending_memberships' => DB::table('memberships')->where('status', 'pending_payment')->count(),
                'open_issues' => DB::table('support_issues')->whereIn('status', ['open', 'in_progress'])->count(),
                'pending_participants' => DB::table('users')->whereIn('role', ['member', 'guardian'])->where('approval_status', 'pending')->count(),
            ],
            'recent_payments' => $recentPayments,
        ]);
    }

    /**
     * Lists Coach applicants requiring Admin approval + Participant account overview for Admin troubleshooting.
     */
    public function approvals(): JsonResponse
    {
        $coaches = DB::table('users as u')
            ->leftJoin('coaches as co', 'co.user_id', '=', 'u.id')
            ->select(
                'u.id', 'u.name', 'u.email', 'u.phone', 'u.approval_status', 'u.approval_notes', 'u.approved_at', 'u.created_at',
                'co.id as coach_id', 'co.certifications', 'co.experience_years', 'co.bio', 'co.is_active as coach_active'
            )
            ->where('u.role', 'coach')
            ->orderByRaw("CASE WHEN u.approval_status = 'pending' THEN 0 WHEN u.approval_status = 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('u.created_at')
            ->get();

        $coachIds = $coaches->pluck('coach_id')->filter()->values()->all();
        $specByCoach = count($coachIds) > 0
            ? DB::table('coach_specializations as cs')
                ->join('specializations as sp', 'sp.id', '=', 'cs.specialization_id')
                ->select('cs.coach_id', 'sp.name')
                ->whereIn('cs.coach_id', $coachIds)
                ->get()
                ->groupBy('coach_id')
            : collect();

        $coaches = $coaches->map(function ($c) use ($specByCoach) {
            $c->specializations = $c->coach_id
                ? ($specByCoach->get($c->coach_id) ?? collect())->pluck('name')->all()
                : [];

            return $c;
        });

        $participants = DB::table('users as u')
            ->leftJoin('users as ap', 'ap.id', '=', 'u.approved_by')
            ->select(
                'u.id', 'u.name', 'u.email', 'u.phone', 'u.role', 'u.birth_date',
                'u.approval_status', 'u.approval_notes', 'u.approved_at', 'u.created_at',
                'ap.name as approved_by_name'
            )
            ->whereIn('u.role', ['member', 'guardian'])
            ->where('u.is_guest', false)
            ->orderByRaw("CASE WHEN u.approval_status = 'pending' THEN 0 WHEN u.approval_status = 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('u.created_at')
            ->limit(60)
            ->get();
        $participants = $participants->map(function ($p) {
            $p->age = $p->birth_date ? Carbon::parse($p->birth_date)->age : null;

            return $p;
        });

        $guardianIds = $participants->where('role', 'guardian')->pluck('id')->all();
        $kidsByUser = [];
        if (count($guardianIds) > 0) {
            $kidRows = DB::table('guardians as g')
                ->join('children as ch', 'ch.guardian_id', '=', 'g.id')
                ->select('g.user_id', 'ch.id', 'ch.full_name', 'ch.birth_date')
                ->whereIn('g.user_id', $guardianIds)
                ->get();
            foreach ($kidRows as $kr) {
                $kr->age = Carbon::parse($kr->birth_date)->age;
                $kidsByUser[$kr->user_id][] = $kr;
            }
        }
        $participants = $participants->map(function ($p) use ($kidsByUser) {
            $p->children = $kidsByUser[$p->id] ?? [];

            return $p;
        });

        $pendingBookings = DB::table('bookings as bk')
            ->join('class_schedules as s', 's.id', '=', 'bk.schedule_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('users as u', 'u.id', '=', 'bk.user_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'bk.child_id')
            ->select(
                'bk.id', 'bk.booking_code', 'bk.status', 'bk.final_amount', 'bk.booking_type', 'bk.created_at',
                's.starts_at', 'c.name as class_name', 'u.name as user_name', 'u.role as user_role',
                'ch.full_name as child_name'
            )
            ->where('bk.status', 'pending_payment')
            ->orderByDesc('bk.created_at')
            ->limit(40)
            ->get();

        $pendingMemberships = DB::table('memberships as m')
            ->join('membership_plans as p', 'p.id', '=', 'm.plan_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->select(
                'm.id', 'm.status', 'm.total_quota', 'm.created_at',
                'p.name as plan_name', 'p.price', 'p.duration_days',
                'u.name as user_name', 'u.role as user_role', 'u.email as user_email'
            )
            ->where('m.status', 'pending_payment')
            ->orderByDesc('m.created_at')
            ->limit(40)
            ->get();

        return response()->json([
            'coaches' => $coaches,
            'participants' => $participants,
            'pending_bookings' => $pendingBookings,
            'pending_memberships' => $pendingMemberships,
        ]);
    }

    /**
     * Admin approves or rejects a Coach / Pelatih account.
     */
    public function decideCoach(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $coachUser = User::where('id', $id)->where('role', 'coach')->first();
        if (! $coachUser) {
            return response()->json(['message' => 'Akun Coach tidak ditemukan.'], 404);
        }

        $decision = (string) $validated['decision'];
        $notes = $validated['notes'] ?? null;
        $now = now();

        DB::transaction(function () use ($coachUser, $decision, $notes, $request, $now) {
            $coachUser->forceFill([
                'approval_status' => $decision,
                'approved_by' => $request->user()->id,
                'approved_at' => $now,
                'approval_notes' => $notes,
            ])->save();

            DB::table('coaches')
                ->where('user_id', $coachUser->id)
                ->update([
                    'is_active' => $decision === 'approved',
                    'updated_at' => $now,
                ]);
        });

        $this->audit->log($request->user()->id, "admin.coach_{$decision}", 'user', $coachUser->id, [
            'notes' => $notes,
        ], $request);

        $this->audit->notify(
            $coachUser->id,
            "coach_{$decision}",
            $decision === 'approved' ? 'Akun Coach Anda Disetujui Admin' : 'Pengajuan Akun Coach Ditolak',
            $decision === 'approved'
                ? 'Selamat! Admin FitSpace telah menyetujui akun Coach Anda. Anda kini dapat login, menerima jadwal resmi, mengelola sesi latihan, serta mengajukan reschedule jika berhalangan.'
                : ('Pengajuan akun Coach Anda belum dapat disetujui.'.($notes ? " Catatan: {$notes}" : '')),
            ['approved_by' => $request->user()->id]
        );

        return response()->json([
            'message' => $decision === 'approved'
                ? "Akun Coach {$coachUser->name} berhasil disetujui dan diaktifkan."
                : "Pengajuan akun Coach {$coachUser->name} telah ditolak.",
            'user' => [
                'id' => $coachUser->id,
                'name' => $coachUser->name,
                'role' => $coachUser->role,
                'approval_status' => $coachUser->approval_status,
            ],
        ]);
    }

    /**
     * Admin troubleshooting override to approve/reject a Member or Guardian account directly.
     */
    public function decideUserAccount(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $target = User::where('id', $id)->whereIn('role', ['member', 'guardian'])->where('is_guest', false)->first();
        if (! $target) {
            return response()->json(['message' => 'Akun Member atau Orang Tua tidak ditemukan.'], 404);
        }

        $decision = (string) $validated['decision'];
        $notes = $validated['notes'] ?? null;

        $target->forceFill([
            'approval_status' => $decision,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_notes' => $notes,
        ])->save();

        $this->audit->log($request->user()->id, "admin.user_{$decision}", 'user', $target->id, [
            'role' => $target->role,
            'notes' => $notes,
        ], $request);

        $this->audit->notify(
            $target->id,
            "account_{$decision}",
            $decision === 'approved' ? 'Akun FitSpace Anda Telah Disetujui' : 'Status Verifikasi Akun Diperbarui',
            $decision === 'approved'
                ? 'Akun Anda telah disetujui oleh Admin FitSpace. Seluruh fitur booking, membership, QR absensi, dan laporan kini aktif.'
                : ('Status akun diperbarui oleh Admin.'.($notes ? " Catatan: {$notes}" : '')),
            ['approved_by' => $request->user()->id]
        );

        return response()->json([
            'message' => "Status akun {$target->name} diperbarui menjadi {$decision}.",
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'role' => $target->role,
                'approval_status' => $target->approval_status,
            ],
        ]);
    }

    /**
     * Admin approves a Member or Orang Tua class booking -> marks paid, confirms booking, issues single-use QR!
     */
    public function approveBooking(Request $request, int $id): JsonResponse
    {
        $booking = DB::table('bookings')->where('id', $id)->first();
        if (! $booking) {
            return response()->json(['message' => 'Booking kelas tidak ditemukan.'], 404);
        }
        if (in_array($booking->status, ['confirmed', 'attended'], true)) {
            return response()->json(['message' => 'Booking kelas sudah terkonfirmasi.', 'booking' => $booking]);
        }

        $payment = DB::table('payments')->where('booking_id', $booking->id)->first();
        if ($payment && $payment->status !== 'paid') {
            $res = $this->payments->markPaymentPaid((int) $payment->id, 'ADMIN-ACC-'.$request->user()->id);
            $qr = $res['qr'];
        } else {
            $schedule = DB::table('class_schedules')->where('id', $booking->schedule_id)->first();
            DB::table('bookings')->where('id', $booking->id)->update(['status' => 'confirmed', 'updated_at' => now()]);
            $qr = $this->bookings->issueQrCode((int) $booking->id, $schedule?->ends_at ?? now()->addDay());
        }

        PublicController::clearBootstrapCache();
        $this->audit->log($request->user()->id, 'admin.booking_approved', 'booking', $booking->id, [], $request);
        $this->audit->notify(
            (int) $booking->user_id,
            'booking_confirmed',
            'Pemilihan Kelas Disetujui Admin!',
            "Admin FitSpace telah menyetujui booking {$booking->booking_code}. Tiket QR absensi Anda kini aktif.",
            ['booking_id' => $booking->id]
        );

        return response()->json([
            'message' => "Booking {$booking->booking_code} berhasil disetujui Admin & QR Code diterbitkan.",
            'booking' => DB::table('bookings')->where('id', $booking->id)->first(),
            'qr' => $qr,
        ]);
    }

    /**
     * Admin approves a Member or Orang Tua membership purchase -> marks paid & activates quota!
     */
    public function approveMembership(Request $request, int $id): JsonResponse
    {
        $membership = DB::table('memberships as m')
            ->join('membership_plans as p', 'p.id', '=', 'm.plan_id')
            ->select('m.*', 'p.name as plan_name')
            ->where('m.id', $id)
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'Pengajuan membership tidak ditemukan.'], 404);
        }
        if ($membership->status === 'active') {
            return response()->json(['message' => 'Membership sudah aktif.', 'membership' => $membership]);
        }

        $payment = DB::table('payments')->where('membership_id', $membership->id)->first();
        if ($payment && $payment->status !== 'paid') {
            $this->payments->markPaymentPaid((int) $payment->id, 'ADMIN-MEM-ACC-'.$request->user()->id);
        } else {
            DB::table('memberships')->where('id', $membership->id)->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
        }

        $this->audit->log($request->user()->id, 'admin.membership_approved', 'membership', $membership->id, [], $request);
        $this->audit->notify(
            (int) $membership->user_id,
            'membership_approved',
            'Paket Membership Disetujui & Aktif!',
            "Admin FitSpace telah menyetujui & mengaktifkan paket {$membership->plan_name} ({$membership->total_quota} kuota sesi) Anda.",
            ['membership_id' => $membership->id]
        );

        return response()->json([
            'message' => "Paket {$membership->plan_name} berhasil disetujui & diaktifkan untuk peserta.",
            'membership' => DB::table('memberships')->where('id', $membership->id)->first(),
        ]);
    }

    /**
     * Unified Schedule Synchronization Dashboard between Coaches and Members/Guardians.
     */
    public function scheduleSync(): JsonResponse
    {
        $availabilities = DB::table('coach_availabilities as ca')
            ->join('coaches as co', 'co.id', '=', 'ca.coach_id')
            ->join('users as u', 'u.id', '=', 'co.user_id')
            ->join('branches as b', 'b.id', '=', 'ca.branch_id')
            ->leftJoin('users as rv', 'rv.id', '=', 'ca.reviewed_by')
            ->select(
                'ca.*', 'u.name as coach_name', 'b.name as branch_name', 'rv.name as reviewed_by_name'
            )
            ->orderByRaw("CASE WHEN ca.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('ca.created_at')
            ->limit(40)
            ->get();

        $scheduleReschedules = DB::table('coach_schedule_reschedules as cr')
            ->join('class_schedules as s', 's.id', '=', 'cr.schedule_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('coaches as co', 'co.id', '=', 'cr.coach_id')
            ->join('users as cu', 'cu.id', '=', 'co.user_id')
            ->join('rooms as r', 'r.id', '=', 's.room_id')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->leftJoin('users as rv', 'rv.id', '=', 'cr.reviewed_by')
            ->select(
                'cr.*', 'c.name as class_name', 'cu.name as coach_name',
                'r.name as room_name', 'b.name as branch_name', 's.booked_count',
                'rv.name as reviewed_by_name'
            )
            ->orderByRaw("CASE WHEN cr.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('cr.created_at')
            ->limit(40)
            ->get();

        $schedules = DB::table('class_schedules as s')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('coaches as co', 'co.id', '=', 's.coach_id')
            ->join('users as cu', 'cu.id', '=', 'co.user_id')
            ->join('rooms as r', 'r.id', '=', 's.room_id')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->select(
                's.id', 's.starts_at', 's.ends_at', 's.capacity', 's.booked_count', 's.status',
                'c.name as class_name', 'cu.name as coach_name', 'r.name as room_name', 'b.name as branch_name'
            )
            ->where('s.status', 'scheduled')
            ->orderBy('s.starts_at')
            ->limit(50)
            ->get();

        return response()->json([
            'availabilities' => $availabilities,
            'schedule_reschedules' => $scheduleReschedules,
            'schedules' => $schedules,
        ]);
    }

    /**
     * Admin approves/rejects a Coach's schedule reschedule request and automatically forwards approved changes to all affected Members & Orang Tua!
     */
    public function decideCoachScheduleReschedule(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'admin_notes' => ['nullable', 'string', 'max:400'],
        ]);

        $reqRow = DB::table('coach_schedule_reschedules as cr')
            ->join('class_schedules as s', 's.id', '=', 'cr.schedule_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('coaches as co', 'co.id', '=', 'cr.coach_id')
            ->join('users as cu', 'cu.id', '=', 'co.user_id')
            ->select(
                'cr.*', 's.room_id', 's.branch_id', 's.coach_id as sched_coach_id',
                'c.name as class_name', 'cu.id as coach_user_id', 'cu.name as coach_name'
            )
            ->where('cr.id', $id)
            ->first();

        if (! $reqRow) {
            return response()->json(['message' => 'Pengajuan reschedule jadwal Coach tidak ditemukan.'], 404);
        }
        if ($reqRow->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan reschedule ini sudah diproses sebelumnya.'], 422);
        }

        $decision = (string) $validated['decision'];
        $notes = $validated['admin_notes'] ?? null;
        $now = now();
        $affectedCount = 0;

        if ($decision === 'approved') {
            $startStr = Carbon::parse($reqRow->proposed_starts_at)->format('Y-m-d H:i:s');
            $endStr = Carbon::parse($reqRow->proposed_ends_at)->format('Y-m-d H:i:s');

            // Anti-conflict verification excluding the schedule being moved
            $coachConflict = DB::table('class_schedules')
                ->where('id', '!=', $reqRow->schedule_id)
                ->where('coach_id', $reqRow->sched_coach_id)
                ->where('status', '!=', 'cancelled')
                ->where('starts_at', '<', $endStr)
                ->where('ends_at', '>', $startStr)
                ->exists();
            if ($coachConflict) {
                throw ValidationException::withMessages([
                    'proposed_starts_at' => ['Waktu pengganti bentrok dengan jadwal Coach lain pada rentang jam tersebut.'],
                ]);
            }

            $roomConflict = DB::table('class_schedules')
                ->where('id', '!=', $reqRow->schedule_id)
                ->where('room_id', $reqRow->room_id)
                ->where('status', '!=', 'cancelled')
                ->where('starts_at', '<', $endStr)
                ->where('ends_at', '>', $startStr)
                ->exists();
            if ($roomConflict) {
                throw ValidationException::withMessages([
                    'proposed_starts_at' => ['Waktu pengganti bentrok dengan penggunaan ruangan studio lain.'],
                ]);
            }

            $affectedBookings = DB::transaction(function () use ($reqRow, $startStr, $endStr, $request, $notes, $now) {
                DB::table('class_schedules')->where('id', $reqRow->schedule_id)->update([
                    'starts_at' => $startStr,
                    'ends_at' => $endStr,
                    'updated_at' => $now,
                ]);

                $bookings = DB::table('bookings as b')
                    ->leftJoin('children as ch', 'ch.id', '=', 'b.child_id')
                    ->select('b.id', 'b.user_id', 'b.booking_code', 'ch.full_name as child_name')
                    ->where('b.schedule_id', $reqRow->schedule_id)
                    ->whereIn('b.status', ['pending_payment', 'confirmed'])
                    ->get();

                $bookingIds = $bookings->pluck('id')->all();
                if (count($bookingIds) > 0) {
                    DB::table('attendance_qr_codes')
                        ->whereIn('booking_id', $bookingIds)
                        ->whereNull('used_at')
                        ->update([
                            'expires_at' => Carbon::parse($endStr)->copy()->addHours(2)->format('Y-m-d H:i:s'),
                            'updated_at' => $now,
                        ]);
                }

                DB::table('coach_schedule_reschedules')->where('id', $reqRow->id)->update([
                    'status' => 'approved',
                    'reviewed_by' => $request->user()->id,
                    'admin_notes' => $notes,
                    'reviewed_at' => $now,
                    'affected_participants_count' => $bookings->count(),
                    'updated_at' => $now,
                ]);

                return $bookings;
            });

            $affectedCount = $affectedBookings->count();
            $oldFmt = Carbon::parse($reqRow->original_starts_at)->format('d M Y H:i').' WIB';
            $newFmt = Carbon::parse($reqRow->proposed_starts_at)->format('d M Y H:i').' WIB';

            foreach ($affectedBookings as $bk) {
                $targetLabel = $bk->child_name ? " ({$bk->child_name})" : '';
                $this->audit->notify(
                    (int) $bk->user_id,
                    'coach_schedule_rescheduled',
                    "Perubahan Jadwal {$reqRow->class_name} Disetujui Admin",
                    "Jadwal {$reqRow->class_name}{$targetLabel} bersama {$reqRow->coach_name} berubah dari {$oldFmt} menjadi {$newFmt} atas persetujuan Admin. Alasan Coach: {$reqRow->reason}. Tiket QR Anda diperbarui otomatis.",
                    [
                        'schedule_id' => $reqRow->schedule_id,
                        'booking_id' => $bk->id,
                        'original_starts_at' => $reqRow->original_starts_at,
                        'proposed_starts_at' => $reqRow->proposed_starts_at,
                    ]
                );
            }

            $this->audit->notify(
                (int) $reqRow->coach_user_id,
                'coach_reschedule_approved',
                'Pengajuan Reschedule Jadwal Anda Disetujui Admin',
                "Admin menyetujui perubahan jadwal {$reqRow->class_name} ke {$newFmt} dan telah meneruskan pemberitahuan ke {$affectedCount} Member / Orang Tua terdaftar.",
                ['reschedule_id' => $reqRow->id]
            );

            PublicController::clearBootstrapCache();
        } else {
            DB::table('coach_schedule_reschedules')->where('id', $reqRow->id)->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'admin_notes' => $notes,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);

            $this->audit->notify(
                (int) $reqRow->coach_user_id,
                'coach_reschedule_rejected',
                'Pengajuan Reschedule Jadwal Ditolak Admin',
                "Pengajuan perubahan jadwal {$reqRow->class_name} belum dapat disetujui Admin.".($notes ? " Catatan: {$notes}" : ''),
                ['reschedule_id' => $reqRow->id]
            );
        }

        $this->audit->log($request->user()->id, "admin.coach_reschedule_{$decision}", 'coach_schedule_reschedule', $id, [
            'schedule_id' => $reqRow->schedule_id,
            'affected_participants_count' => $affectedCount,
        ], $request);

        return response()->json([
            'message' => $decision === 'approved'
                ? "Reschedule Coach disetujui Admin & pemberitahuan otomatis diteruskan ke {$affectedCount} Member / Orang Tua."
                : 'Pengajuan reschedule jadwal Coach ditolak.',
            'status' => $decision,
            'affected_participants_count' => $affectedCount,
        ]);
    }

    /**
     * Admin approves or rejects a Coach's schedule availability sync request.
     */
    public function decideAvailability(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'notes' => ['nullable', 'string', 'max:250'],
        ]);

        $avail = DB::table('coach_availabilities as ca')
            ->join('coaches as co', 'co.id', '=', 'ca.coach_id')
            ->select('ca.*', 'co.user_id as coach_user_id')
            ->where('ca.id', $id)
            ->first();

        if (! $avail) {
            return response()->json(['message' => 'Pengajuan ketersediaan Coach tidak ditemukan.'], 404);
        }

        $decision = (string) $validated['decision'];
        $now = now();

        DB::table('coach_availabilities')->where('id', $id)->update([
            'status' => $decision,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => $now,
            'request_notes' => ! empty($validated['notes'])
                ? trim(($avail->request_notes ?? '').' | Catatan Admin: '.$validated['notes'])
                : $avail->request_notes,
            'updated_at' => $now,
        ]);

        $this->audit->log($request->user()->id, "admin.availability_{$decision}", 'coach_availability', $id, [
            'coach_id' => $avail->coach_id,
        ], $request);

        $this->audit->notify(
            (int) $avail->coach_user_id,
            "availability_{$decision}",
            $decision === 'approved' ? 'Jadwal Ketersediaan Disinkronkan Admin' : 'Pengajuan Jadwal Ketersediaan Ditolak',
            $decision === 'approved'
                ? 'Admin telah menyetujui & menyinkronkan pengajuan ketersediaan jadwal Anda dengan jadwal peserta.'
                : ('Pengajuan ketersediaan jadwal Anda ditolak Admin.'.(! empty($validated['notes']) ? ' Catatan: '.$validated['notes'] : '')),
            ['availability_id' => $id]
        );

        return response()->json([
            'message' => $decision === 'approved'
                ? 'Pengajuan jadwal Coach disetujui dan siap disinkronkan dengan kelas member.'
                : 'Pengajuan jadwal Coach ditolak.',
            'status' => $decision,
        ]);
    }

    /**
     * Admin Issue Resolution Center — view all issues from Coaches, Members & Guardians.
     */
    public function issues(): JsonResponse
    {
        $issues = DB::table('support_issues as si')
            ->join('users as u', 'u.id', '=', 'si.user_id')
            ->leftJoin('users as rv', 'rv.id', '=', 'si.resolved_by')
            ->leftJoin('branches as b', 'b.id', '=', 'si.branch_id')
            ->select(
                'si.*', 'u.name as reporter_name', 'u.role as reporter_role', 'u.email as reporter_email',
                'rv.name as resolved_by_name', 'b.name as branch_name'
            )
            ->orderByRaw("CASE WHEN si.status = 'open' THEN 0 WHEN si.status = 'in_progress' THEN 1 ELSE 2 END")
            ->orderByDesc('si.created_at')
            ->limit(80)
            ->get();

        return response()->json(['issues' => $issues]);
    }

    /**
     * Admin updates & resolves operational / member / coach issues.
     */
    public function resolveIssue(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:in_progress,resolved'],
            'resolution_notes' => ['required', 'string', 'max:1000'],
        ]);

        $issue = DB::table('support_issues')->where('id', $id)->first();
        if (! $issue) {
            return response()->json(['message' => 'Tiket masalah tidak ditemukan.'], 404);
        }

        $now = now();
        DB::table('support_issues')->where('id', $id)->update([
            'status' => $validated['status'],
            'resolved_by' => $request->user()->id,
            'resolution_notes' => $validated['resolution_notes'],
            'resolved_at' => $validated['status'] === 'resolved' ? $now : null,
            'updated_at' => $now,
        ]);

        $this->audit->log($request->user()->id, 'admin.issue_'.$validated['status'], 'support_issue', $id, [
            'ticket_code' => $issue->ticket_code,
        ], $request);

        $this->audit->notify(
            (int) $issue->user_id,
            'issue_updated',
            "Tiket {$issue->ticket_code}: ".($validated['status'] === 'resolved' ? 'Masalah Diselesaikan Admin' : 'Sedang Ditangani Admin'),
            $validated['resolution_notes'],
            ['ticket_code' => $issue->ticket_code]
        );

        return response()->json([
            'message' => $validated['status'] === 'resolved'
                ? "Tiket {$issue->ticket_code} berhasil diselesaikan."
                : "Tiket {$issue->ticket_code} ditandai sedang ditangani.",
            'issue' => DB::table('support_issues')->where('id', $id)->first(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:400'],
            'color_token' => ['nullable', 'in:pilates,zumba,boxing,swimming,martial,yoga'],
        ]);

        $baseSlug = Str::slug($validated['name']);
        $slug = DB::table('class_categories')->where('slug', $baseSlug)->exists()
            ? $baseSlug.'-'.strtolower(Str::random(4))
            : $baseSlug;
        $now = now();

        $categoryId = DB::transaction(function () use ($validated, $slug, $now) {
            $specId = DB::table('specializations')->where('slug', $slug)->value('id');
            if (! $specId) {
                $specId = DB::table('specializations')->insertGetId([
                    'slug' => $slug,
                    'name' => $validated['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return DB::table('class_categories')->insertGetId([
                'slug' => $slug,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'specialization_id' => $specId,
                'color_token' => $validated['color_token'] ?? 'pilates',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $this->audit->log($request->user()->id, 'category.created', 'class_category', $categoryId, [
            'name' => $validated['name'],
        ], $request);
        PublicController::clearBootstrapCache();

        return response()->json([
            'message' => 'Kategori olahraga dinamis baru berhasil ditambahkan.',
            'category' => DB::table('class_categories')->where('id', $categoryId)->first(),
        ], 201);
    }

    public function storeClass(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', 'exists:class_categories,id'],
            'level_id' => ['required', 'integer', 'exists:class_levels,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_per_session' => ['required', 'numeric', 'min:0'],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:200'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:240'],
            'min_age' => ['required', 'integer', 'min:3', 'max:90'],
            'max_age' => ['required', 'integer', 'gte:min_age', 'max:100'],
            'required_facilities' => ['nullable', 'string', 'max:250'],
            'indicators' => ['nullable', 'array'],
            'indicators.*' => ['string', 'max:120'],
        ]);

        $baseSlug = Str::slug($validated['name']);
        $slug = DB::table('classes')->where('slug', $baseSlug)->exists()
            ? $baseSlug.'-'.strtolower(Str::random(4))
            : $baseSlug;
        $now = now();

        $classId = DB::transaction(function () use ($validated, $slug, $now) {
            $cId = DB::table('classes')->insertGetId([
                'category_id' => $validated['category_id'],
                'level_id' => $validated['level_id'],
                'slug' => $slug,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? 'Kelas olahraga dinamis FitSpace.',
                'min_age' => $validated['min_age'],
                'max_age' => $validated['max_age'],
                'duration_minutes' => $validated['duration_minutes'],
                'max_capacity' => $validated['max_capacity'],
                'price_per_session' => $validated['price_per_session'],
                'required_facilities' => $validated['required_facilities'] ?? null,
                'evaluation_enabled' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (($validated['indicators'] ?? []) as $idx => $indName) {
                $trimmed = trim($indName);
                if ($trimmed === '') {
                    continue;
                }
                DB::table('class_evaluation_indicators')->insert([
                    'class_id' => $cId,
                    'name' => $trimmed,
                    'sort_order' => $idx + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $cId;
        });

        $this->audit->log($request->user()->id, 'class.created', 'class', $classId, [
            'name' => $validated['name'],
        ], $request);
        PublicController::clearBootstrapCache();

        return response()->json([
            'message' => 'Kelas dinamis baru beserta indikator evaluasinya berhasil dibuat.',
            'class' => DB::table('classes')->where('id', $classId)->first(),
        ], 201);
    }

    public function scheduleMeta(): JsonResponse
    {
        $coaches = DB::table('coaches as co')
            ->join('users as u', 'u.id', '=', 'co.user_id')
            ->select('co.id', 'u.name')
            ->where('co.is_active', true)
            ->where('u.approval_status', 'approved')
            ->get()
            ->map(function ($c) {
                $c->specializations = DB::table('coach_specializations as cs')
                    ->join('specializations as sp', 'sp.id', '=', 'cs.specialization_id')
                    ->where('cs.coach_id', $c->id)
                    ->pluck('sp.name')
                    ->all();

                return $c;
            });

        $rooms = DB::table('rooms as r')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')
            ->select('r.id', 'r.name', 'r.max_capacity', 'r.branch_id', 'b.name as branch_name')
            ->where('r.is_active', true)
            ->get();

        return response()->json(['coaches' => $coaches, 'rooms' => $rooms]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'coach_id' => ['required', 'integer', 'exists:coaches,id'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'capacity' => ['required', 'integer', 'min:1'],
            'is_recurring' => ['nullable', 'boolean'],
            'recurrence_rule' => ['nullable', 'string', 'max:80'],
        ]);

        $id = $this->scheduler->validateAndCreate($validated, $request->user()->id, $this->audit);
        PublicController::clearBootstrapCache();

        return response()->json([
            'message' => 'Jadwal kelas resmi berhasil dibuat & disinkronkan tanpa bentrok Coach maupun Ruangan.',
            'schedule' => DB::table('class_schedules')->where('id', $id)->first(),
        ], 201);
    }

    public function bookings(): JsonResponse
    {
        $rows = DB::table('bookings as b')
            ->join('users as u', 'u.id', '=', 'b.user_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'b.child_id')
            ->join('class_schedules as s', 's.id', '=', 'b.schedule_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->leftJoin('payments as p', 'p.booking_id', '=', 'b.id')
            ->select(
                'b.id', 'b.booking_code', 'b.final_amount', 'b.status', 'b.booking_type',
                's.starts_at', 'c.name as class_name',
                'p.id as payment_id', 'p.status as payment_status',
                DB::raw('COALESCE(ch.full_name, u.name) as participant_name')
            )
            ->orderByDesc('b.created_at')
            ->limit(100)
            ->get();

        return response()->json(['bookings' => $rows]);
    }

    public function refundPayment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:250'],
        ]);

        $refund = $this->payments->processRefund(
            $request->user(),
            $id,
            isset($validated['amount']) ? (float) $validated['amount'] : null,
            $validated['reason']
        );

        return response()->json([
            'message' => 'Refund pembayaran diproses dan dikurangkan dari pendapatan bersih.',
            'refund' => $refund,
        ]);
    }

    public function finance(Request $request): JsonResponse
    {
        $branchId = is_numeric($request->query('branch_id')) ? (int) $request->query('branch_id') : null;

        return response()->json([
            'summary' => $this->finance->getSummary($branchId),
            'expense_categories' => DB::table('expense_categories')->orderBy('id')->get(),
            'recent_expenses' => DB::table('expenses as e')
                ->join('expense_categories as ec', 'ec.id', '=', 'e.expense_category_id')
                ->join('branches as b', 'b.id', '=', 'e.branch_id')
                ->select('e.*', 'ec.name as category_name', 'b.name as branch_name')
                ->orderByDesc('e.incurred_on')
                ->limit(30)
                ->get(),
        ]);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
            'incurred_on' => ['required', 'date'],
        ]);

        $now = now();
        $id = DB::table('expenses')->insertGetId([
            'branch_id' => $validated['branch_id'],
            'expense_category_id' => $validated['expense_category_id'],
            'recorded_by' => $request->user()->id,
            'amount' => $validated['amount'],
            'description' => $validated['description'],
            'incurred_on' => $validated['incurred_on'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->log($request->user()->id, 'expense.recorded', 'expense', $id, [
            'amount' => (float) $validated['amount'],
        ], $request);

        return response()->json(['message' => 'Pengeluaran operasional berhasil dicatat.', 'id' => $id], 201);
    }

    public function auditLogs(): JsonResponse
    {
        $logs = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.*', 'u.name as user_name')
            ->orderByDesc('a.created_at')
            ->limit(60)
            ->get();

        $settings = DB::table('settings')->orderBy('key')->get();

        return response()->json(['logs' => $logs, 'settings' => $settings]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable', 'string', 'max:250'],
        ]);

        $allowedKeys = ['reschedule_cutoff_hours', 'max_reschedule_count', 'cancel_refund_policy', 'brand_name'];
        $now = now();

        foreach ($validated['settings'] as $key => $val) {
            if (! in_array($key, $allowedKeys, true)) {
                continue;
            }
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => (string) $val, 'updated_by' => $request->user()->id, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $this->audit->log($request->user()->id, 'settings.updated', 'settings', null, [], $request);

        return response()->json(['message' => 'Pengaturan kebijakan sistem diperbarui.']);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:24', 'unique:branches,code'],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'operating_hours' => ['nullable', 'string', 'max:120'],
        ]);

        $now = now();
        $id = DB::table('branches')->insertGetId(array_merge($validated, [
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $this->audit->log($request->user()->id, 'branch.created', 'branch', $id, ['code' => $validated['code']], $request);

        return response()->json(['message' => 'Cabang baru berhasil ditambahkan.', 'id' => $id], 201);
    }

    public function storePromotion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'min:1'],
            'code' => ['required', 'string', 'max:40', 'unique:coupons,code'],
            'max_uses' => ['required', 'integer', 'min:1'],
            'min_transaction' => ['nullable', 'numeric', 'min:0'],
        ]);

        $now = now();
        $promoId = DB::transaction(function () use ($validated, $now) {
            $pId = DB::table('promotions')->insertGetId([
                'name' => $validated['name'],
                'discount_type' => $validated['discount_type'],
                'discount_value' => $validated['discount_value'],
                'starts_at' => $now,
                'ends_at' => $now->copy()->addDays(60),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('coupons')->insert([
                'promotion_id' => $pId,
                'code' => strtoupper($validated['code']),
                'max_uses' => $validated['max_uses'],
                'used_count' => 0,
                'min_transaction' => $validated['min_transaction'] ?? 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $pId;
        });

        $this->audit->log($request->user()->id, 'promotion.created', 'promotion', $promoId, ['code' => strtoupper($validated['code'])], $request);

        return response()->json(['message' => 'Promo & kode voucher berhasil dibuat.', 'id' => $promoId], 201);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\AuditService;
use App\Services\BookingService;
use App\Services\PaymentGatewayService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoachController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditService $audit,
        private readonly BookingService $bookings,
        private readonly PaymentGatewayService $payments,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $coach = DB::table('coaches')->where('user_id', $user->id)->first();
        $coachId = $coach?->id ?? 0;

        $schedules = DB::table('class_schedules as s')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('rooms as r', 'r.id', '=', 's.room_id')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->select('s.*', 'c.name as class_name', 'r.name as room_name', 'b.name as branch_name')
            ->when($user->role === 'coach', fn ($q) => $q->where('s.coach_id', $coachId))
            ->orderBy('s.starts_at')
            ->get();

        $evaluableBookings = DB::table('bookings as bk')
            ->join('class_schedules as s', 's.id', '=', 'bk.schedule_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('users as u', 'u.id', '=', 'bk.user_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'bk.child_id')
            ->select(
                'bk.id', 'bk.booking_code', 'bk.status', 's.starts_at',
                'c.id as class_id', 'c.name as class_name',
                DB::raw('COALESCE(ch.full_name, u.name) as participant_name')
            )
            ->when($user->role === 'coach', fn ($q) => $q->where('s.coach_id', $coachId))
            ->whereIn('bk.status', ['confirmed', 'attended'])
            ->orderByDesc('bk.created_at')
            ->limit(40)
            ->get();

        $recentReports = DB::table('progress_reports as pr')
            ->join('classes as c', 'c.id', '=', 'pr.class_id')
            ->join('users as u', 'u.id', '=', 'pr.user_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'pr.child_id')
            ->select(
                'pr.id', 'pr.average_score', 'pr.material_studied', 'pr.progress_notes',
                'pr.evaluated_at', 'pr.is_published', 'c.name as class_name',
                DB::raw('COALESCE(ch.full_name, u.name) as participant_name')
            )
            ->when($user->role === 'coach', fn ($q) => $q->where('pr.coach_id', $coachId))
            ->orderByDesc('pr.created_at')
            ->limit(30)
            ->get();

        $availabilities = DB::table('coach_availabilities as ca')
            ->join('branches as b', 'b.id', '=', 'ca.branch_id')
            ->select('ca.*', 'b.name as branch_name')
            ->when($user->role === 'coach', fn ($q) => $q->where('ca.coach_id', $coachId))
            ->orderByDesc('ca.created_at')
            ->limit(20)
            ->get();

        $pendingAccountsCount = DB::table('users')
            ->whereIn('role', ['member', 'guardian'])
            ->where('is_guest', false)
            ->where('approval_status', 'pending')
            ->count();

        return response()->json([
            'coach' => [
                'id' => $coachId,
                'name' => $user->name,
                'experience_years' => $coach?->experience_years ?? 0,
                'pending_accounts_count' => $pendingAccountsCount,
            ],
            'schedules' => $schedules,
            'evaluable_bookings' => $evaluableBookings,
            'recent_reports' => $recentReports,
            'availabilities' => $availabilities,
        ]);
    }

    /**
     * Coach views pending & reviewed Member (18+) and Orang Tua / Wali accounts + pending class bookings.
     * Batch-loaded without N+1 queries for instant rendering.
     */
    public function pendingAccounts(Request $request): JsonResponse
    {
        $accounts = DB::table('users as u')
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

        $guardianUserIds = $accounts->where('role', 'guardian')->pluck('id')->all();
        $childrenByUserId = [];
        if (count($guardianUserIds) > 0) {
            $rows = DB::table('guardians as g')
                ->join('children as ch', 'ch.guardian_id', '=', 'g.id')
                ->select('g.user_id', 'ch.id', 'ch.full_name', 'ch.birth_date')
                ->whereIn('g.user_id', $guardianUserIds)
                ->get();
            foreach ($rows as $ch) {
                $ch->age = Carbon::parse($ch->birth_date)->age;
                $childrenByUserId[$ch->user_id][] = $ch;
            }
        }

        $accounts = $accounts->map(function ($acc) use ($childrenByUserId) {
            $acc->age = $acc->birth_date ? Carbon::parse($acc->birth_date)->age : null;
            $acc->children = $childrenByUserId[$acc->id] ?? [];

            return $acc;
        });

        $pendingBookings = DB::table('bookings as bk')
            ->join('class_schedules as s', 's.id', '=', 'bk.schedule_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('users as u', 'u.id', '=', 'bk.user_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'bk.child_id')
            ->select(
                'bk.id', 'bk.booking_code', 'bk.status', 'bk.final_amount', 'bk.created_at',
                's.starts_at', 'c.name as class_name', 'u.name as user_name', 'u.role as user_role',
                'ch.full_name as child_name'
            )
            ->where('bk.status', 'pending_payment')
            ->orderByDesc('bk.created_at')
            ->limit(30)
            ->get();

        return response()->json([
            'accounts' => $accounts,
            'pending_bookings' => $pendingBookings,
        ]);
    }

    /**
     * Coach confirms a pending Member/Guardian class booking immediately and issues its QR Pass!
     */
    public function confirmBooking(Request $request, int $id): JsonResponse
    {
        $booking = DB::table('bookings')->where('id', $id)->first();
        if (! $booking) {
            return response()->json(['message' => 'Booking kelas tidak ditemukan.'], 404);
        }
        if ($booking->status === 'confirmed' || $booking->status === 'attended') {
            return response()->json(['message' => 'Booking kelas sudah berstatus terkonfirmasi.', 'booking' => $booking]);
        }

        $payment = DB::table('payments')->where('booking_id', $booking->id)->first();
        if ($payment && $payment->status !== 'paid') {
            $res = $this->payments->markPaymentPaid((int) $payment->id, 'COACH-APPROVED-'.$request->user()->id);
            $qr = $res['qr'];
        } else {
            $schedule = DB::table('class_schedules')->where('id', $booking->schedule_id)->first();
            DB::table('bookings')->where('id', $booking->id)->update(['status' => 'confirmed', 'updated_at' => now()]);
            $qr = $this->bookings->issueQrCode((int) $booking->id, $schedule->ends_at);
        }

        PublicController::clearBootstrapCache();
        $this->audit->log($request->user()->id, 'coach.booking_confirmed', 'booking', $booking->id, [], $request);
        $this->audit->notify(
            (int) $booking->user_id,
            'booking_confirmed',
            'Booking Kelas Anda Disetujui Coach!',
            "Coach {$request->user()->name} telah menyetujui booking {$booking->booking_code}. Tiket QR absensi Anda kini aktif.",
            ['booking_id' => $booking->id]
        );

        return response()->json([
            'message' => "Booking {$booking->booking_code} berhasil disetujui & QR Code diterbitkan untuk peserta.",
            'booking' => DB::table('bookings')->where('id', $booking->id)->first(),
            'qr' => $qr,
        ]);
    }

    /**
     * Coach approves or rejects Member (18+) or Orang Tua / Wali accounts.
     * STRICT RBAC: Coach can NEVER approve Coach or Admin accounts!
     */
    public function decideAccount(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $target = User::find($id);
        if (! $target || $target->isGuestAccount()) {
            return response()->json(['message' => 'Akun peserta tidak ditemukan.'], 404);
        }

        if (! in_array($target->role, ['member', 'guardian'], true)) {
            return response()->json([
                'message' => 'Akses ditolak: Coach hanya berwenang menyetujui akun Member (18+) atau Orang Tua / Wali.',
            ], 403);
        }

        $decision = (string) $validated['decision'];
        $notes = $validated['notes'] ?? null;

        $target->forceFill([
            'approval_status' => $decision,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_notes' => $notes,
        ])->save();

        $this->audit->log($request->user()->id, "coach.account_{$decision}", 'user', $target->id, [
            'target_role' => $target->role,
            'notes' => $notes,
        ], $request);

        $roleLabel = $target->role === 'guardian' ? 'Orang Tua / Wali' : 'Member (18+)';
        $this->audit->notify(
            $target->id,
            "account_{$decision}",
            $decision === 'approved'
                ? "Akun {$roleLabel} Anda Disetujui Coach"
                : "Pengajuan Akun {$roleLabel} Ditolak Coach",
            $decision === 'approved'
                ? "Coach {$request->user()->name} telah menyetujui akun Anda. Anda kini dapat memilih membership, booking kelas, menyesuaikan jadwal, dan melihat rapor perkembangan."
                : ("Pengajuan akun Anda ditolak oleh Coach {$request->user()->name}.".($notes ? " Catatan: {$notes}" : '')),
            ['approved_by' => $request->user()->id]
        );

        return response()->json([
            'message' => $decision === 'approved'
                ? "Akun {$roleLabel} {$target->name} berhasil disetujui."
                : "Akun {$roleLabel} {$target->name} ditolak.",
            'account' => [
                'id' => $target->id,
                'name' => $target->name,
                'role' => $target->role,
                'approval_status' => $target->approval_status,
            ],
        ]);
    }

    public function scanQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'max:128'],
            'status' => ['nullable', 'in:present,late,excused,absent'],
            'notes' => ['nullable', 'string', 'max:250'],
        ]);

        $res = $this->attendance->scanQr(
            $request->user(),
            $validated['qr_token'],
            $validated['status'] ?? 'present',
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => "Kehadiran booking {$res['booking_code']} berhasil divalidasi.",
            'attendance' => $res['attendance'],
        ]);
    }

    public function storeMaterial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'title' => ['required', 'string', 'max:150'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:180'],
            'equipment_needed' => ['nullable', 'string', 'max:250'],
            'learning_objective' => ['required', 'string', 'max:500'],
            'difficulty' => ['nullable', 'in:beginner,intermediate,advanced'],
        ]);

        $seq = (int) DB::table('class_materials')->where('class_id', $validated['class_id'])->max('sequence_order') + 1;
        $now = now();

        $id = DB::table('class_materials')->insertGetId([
            'class_id' => $validated['class_id'],
            'created_by' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['learning_objective'],
            'learning_objective' => $validated['learning_objective'],
            'difficulty' => $validated['difficulty'] ?? 'beginner',
            'sequence_order' => $seq,
            'duration_minutes' => $validated['duration_minutes'],
            'equipment_needed' => $validated['equipment_needed'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->log($request->user()->id, 'material.created', 'class_material', $id, [
            'class_id' => $validated['class_id'],
        ], $request);

        return response()->json(['message' => 'Materi pembelajaran berhasil disimpan.', 'id' => $id], 201);
    }

    public function storeEvaluation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'material_studied' => ['required', 'string', 'max:250'],
            'progress_notes' => ['required', 'string', 'max:1000'],
            'improvement_areas' => ['nullable', 'string', 'max:500'],
            'next_recommendation' => ['required', 'string', 'max:500'],
            'is_published' => ['nullable', 'boolean'],
            'scores' => ['nullable', 'array'],
            'scores.*.indicator_id' => ['nullable', 'integer'],
            'scores.*.indicator_name' => ['nullable', 'string', 'max:120'],
            'scores.*.score' => ['required_with:scores', 'integer', 'min:1', 'max:4'],
            'score_1' => ['nullable', 'integer', 'min:1', 'max:4'],
            'score_2' => ['nullable', 'integer', 'min:1', 'max:4'],
            'score_3' => ['nullable', 'integer', 'min:1', 'max:4'],
            'score_4' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        $user = $request->user();
        $booking = DB::table('bookings')->where('id', $validated['booking_id'])->first();
        $schedule = DB::table('class_schedules')->where('id', $booking->schedule_id)->first();

        $coachId = DB::table('coaches')->where('user_id', $user->id)->value('id');
        if ($user->role === 'coach') {
            $assigned = $coachId && (
                (int) $schedule->coach_id === (int) $coachId
                || DB::table('schedule_coaches')->where('schedule_id', $schedule->id)->where('coach_id', $coachId)->exists()
            );
            if (! $assigned) {
                return response()->json([
                    'message' => 'Akses ditolak: Coach hanya boleh menilai peserta pada jadwal yang diajarnya.',
                ], 403);
            }
        } else {
            $coachId = (int) $schedule->coach_id;
        }

        $scoreLabels = config('fitspace.score_labels', [
            1 => 'Belum Menguasai',
            2 => 'Mulai Berkembang',
            3 => 'Berkembang Sesuai Target',
            4 => 'Sangat Baik',
        ]);

        $classIndicators = DB::table('class_evaluation_indicators')
            ->where('class_id', $schedule->class_id)
            ->orderBy('sort_order')
            ->get()
            ->values();

        $detailRows = [];
        if (! empty($validated['scores'])) {
            foreach ($validated['scores'] as $entry) {
                $ind = isset($entry['indicator_id'])
                    ? $classIndicators->firstWhere('id', (int) $entry['indicator_id'])
                    : null;
                $s = (int) $entry['score'];
                $detailRows[] = [
                    'indicator_id' => $ind?->id,
                    'indicator_name' => $entry['indicator_name'] ?? $ind?->name ?? 'Indikator Latihan',
                    'score' => $s,
                    'score_label' => $scoreLabels[$s] ?? 'Tercatat',
                ];
            }
        } else {
            $quickScores = array_values(array_filter([
                $validated['score_1'] ?? null,
                $validated['score_2'] ?? null,
                $validated['score_3'] ?? null,
                $validated['score_4'] ?? null,
            ], static fn ($v) => $v !== null));

            if (count($quickScores) === 0) {
                return response()->json(['message' => 'Minimal 1 skor indikator (1–4) wajib diisi.'], 422);
            }

            foreach ($quickScores as $idx => $sVal) {
                $s = (int) $sVal;
                $ind = $classIndicators->get($idx);
                $detailRows[] = [
                    'indicator_id' => $ind?->id,
                    'indicator_name' => $ind?->name ?? ('Indikator '.($idx + 1)),
                    'score' => $s,
                    'score_label' => $scoreLabels[$s] ?? 'Tercatat',
                ];
            }
        }

        $avg = round(array_sum(array_column($detailRows, 'score')) / max(1, count($detailRows)), 2);
        $isPublished = array_key_exists('is_published', $validated) ? (bool) $validated['is_published'] : true;
        $now = now();

        $reportId = DB::transaction(function () use ($booking, $schedule, $coachId, $validated, $avg, $isPublished, $detailRows, $now) {
            $rId = DB::table('progress_reports')->insertGetId([
                'booking_id' => $booking->id,
                'schedule_id' => $schedule->id,
                'class_id' => $schedule->class_id,
                'coach_id' => $coachId,
                'user_id' => $booking->user_id,
                'child_id' => $booking->child_id,
                'material_studied' => $validated['material_studied'],
                'average_score' => $avg,
                'progress_notes' => $validated['progress_notes'],
                'improvement_areas' => $validated['improvement_areas'] ?? null,
                'next_recommendation' => $validated['next_recommendation'],
                'evaluated_at' => $now->toDateString(),
                'is_published' => $isPublished,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($detailRows as $d) {
                DB::table('progress_details')->insert(array_merge($d, [
                    'progress_report_id' => $rId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }

            return $rId;
        });

        $this->audit->log($user->id, 'progress.evaluated', 'progress_report', $reportId, [
            'booking_id' => $booking->id,
            'average_score' => $avg,
            'is_published' => $isPublished,
        ], $request);

        if ($isPublished) {
            $this->audit->notify(
                (int) $booking->user_id,
                'progress_evaluated',
                'Nilai & Laporan Perkembangan Baru Dipublikasikan',
                "Coach {$user->name} telah memunculkan nilai perkembangan sesi Anda dengan skor rata-rata {$avg}/4.0.",
                ['progress_report_id' => $reportId]
            );
        }

        return response()->json([
            'message' => $isPublished
                ? 'Nilai evaluasi & laporan perkembangan berhasil dipublikasikan ke peserta.'
                : 'Draft nilai evaluasi berhasil disimpan (belum dipublikasikan).',
            'progress_report_id' => $reportId,
            'average_score' => $avg,
            'is_published' => $isPublished,
        ], 201);
    }

    /**
     * Coach toggles publication visibility of an evaluation / progress report.
     */
    public function togglePublishEvaluation(Request $request, int $id): JsonResponse
    {
        $report = DB::table('progress_reports')->where('id', $id)->first();
        if (! $report) {
            return response()->json(['message' => 'Laporan evaluasi tidak ditemukan.'], 404);
        }

        $user = $request->user();
        if ($user->role === 'coach') {
            $coachId = (int) DB::table('coaches')->where('user_id', $user->id)->value('id');
            if ((int) $report->coach_id !== $coachId) {
                return response()->json(['message' => 'Akses ditolak: Anda hanya dapat mengubah publikasi nilai kelas Anda sendiri.'], 403);
            }
        }

        $next = $request->has('is_published')
            ? filter_var($request->input('is_published'), FILTER_VALIDATE_BOOLEAN)
            : ! ((bool) ($report->is_published ?? true));

        DB::table('progress_reports')->where('id', $id)->update([
            'is_published' => $next,
            'updated_at' => now(),
        ]);

        if ($next) {
            $this->audit->notify(
                (int) $report->user_id,
                'progress_published',
                'Laporan Nilai Latihan Kini Tersedia',
                "Coach {$user->name} memunculkan laporan nilai perkembangan Anda (skor {$report->average_score}/4.0).",
                ['progress_report_id' => $id]
            );
        }

        $this->audit->log($user->id, $next ? 'progress.published' : 'progress.unpublished', 'progress_report', $id, [], $request);

        return response()->json([
            'message' => $next ? 'Nilai & laporan telah dimunculkan ke peserta.' : 'Nilai & laporan disembunyikan (mode draft).',
            'is_published' => $next,
        ]);
    }

    public function storeAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'start_time' => ['required', 'string', 'max:10'],
            'end_time' => ['required', 'string', 'max:10'],
            'request_notes' => ['required', 'string', 'max:250'],
        ]);

        $coachId = DB::table('coaches')->where('user_id', $request->user()->id)->value('id');
        if (! $coachId) {
            return response()->json(['message' => 'Profil Coach tidak ditemukan.'], 404);
        }

        $now = now();
        $id = DB::table('coach_availabilities')->insertGetId([
            'coach_id' => $coachId,
            'branch_id' => $validated['branch_id'],
            'day_of_week' => $validated['day_of_week'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'status' => 'pending',
            'request_notes' => $validated['request_notes'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->log($request->user()->id, 'coach.availability_requested', 'coach_availability', $id, [], $request);

        return response()->json([
            'message' => 'Pengajuan ketersediaan telah dikirim ke Admin untuk disinkronkan.',
            'id' => $id,
            'status' => 'pending',
        ], 201);
    }
}

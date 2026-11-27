<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\ReportExportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class GuardianController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ReportExportService $reports,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $guardian = $this->resolveGuardian($user->id, $user->branch_id);

        $children = DB::table('children')
            ->where('guardian_id', $guardian->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(function ($ch) {
                $ch->age = Carbon::parse($ch->birth_date)->age;

                return $ch;
            });

        $childIds = $children->pluck('id')->all();

        $progressReports = count($childIds) > 0
            ? DB::table('progress_reports as pr')
                ->join('children as ch', 'ch.id', '=', 'pr.child_id')
                ->join('classes as c', 'c.id', '=', 'pr.class_id')
                ->join('coaches as co', 'co.id', '=', 'pr.coach_id')
                ->join('users as cu', 'cu.id', '=', 'co.user_id')
                ->select('pr.*', 'ch.full_name as child_name', 'c.name as class_name', 'cu.name as coach_name')
                ->whereIn('pr.child_id', $childIds)
                ->when($user->role !== 'admin', fn ($q) => $q->where('pr.is_published', true))
                ->orderByDesc('pr.evaluated_at')
                ->get()
            : collect();

        if ($progressReports->isNotEmpty()) {
            $prIds = $progressReports->pluck('id')->all();
            $detailsMap = DB::table('progress_details')->whereIn('progress_report_id', $prIds)->get()->groupBy('progress_report_id');
            $progressReports = $progressReports->map(function ($r) use ($detailsMap) {
                $r->details = ($detailsMap->get($r->id) ?? collect())->values()->all();

                return $r;
            });
        }

        $issues = DB::table('support_issues')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $notifications = DB::table('notifications')
            ->where('user_id', $user->id)
            ->whereIn('type', ['coach_schedule_rescheduled', 'booking_confirmed', 'membership_approved', 'account_approved'])
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        return response()->json([
            'guardian' => $guardian,
            'children' => $children,
            'progress_reports' => $progressReports,
            'issues' => $issues,
            'notifications' => $notifications,
            'approval_status' => $user->approval_status ?? 'approved',
            'approval_notes' => $user->approval_notes,
        ]);
    }

    public function children(Request $request): JsonResponse
    {
        $user = $request->user();
        $guardian = $this->resolveGuardian($user->id, $user->branch_id);

        $children = DB::table('children')
            ->where('guardian_id', $guardian->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(function ($ch) {
                $ch->age = Carbon::parse($ch->birth_date)->age;

                return $ch;
            });

        return response()->json(['children' => $children]);
    }

    public function showChild(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $guardian = $this->resolveGuardian($user->id, $user->branch_id);
        $child = DB::table('children')->where('id', $id)->first();

        if (! $child) {
            return response()->json(['message' => 'Profil anak tidak ditemukan.'], 404);
        }
        if ($user->role !== 'admin' && (int) $child->guardian_id !== (int) $guardian->id) {
            return response()->json(['message' => 'Akses ditolak: Anda hanya berhak melihat data anak Anda sendiri.'], 403);
        }

        $child->age = Carbon::parse($child->birth_date)->age;

        return response()->json(['child' => $child]);
    }

    public function storeChild(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['nullable', 'in:Laki-laki,Perempuan'],
            'medical_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $childAge = Carbon::parse($validated['birth_date'])->age;
        if ($childAge >= 18) {
            return response()->json([
                'message' => "Usia anak {$childAge} tahun. Profil anak di Akun Orang Tua / Wali khusus peserta di bawah 18 tahun — usia 18+ mendaftar sebagai Akun Member mandiri.",
                'errors' => ['birth_date' => ['Usia anak wajib di bawah 18 tahun.']],
            ], 422);
        }

        $user = $request->user();
        $guardian = $this->resolveGuardian($user->id, $user->branch_id);
        $now = now();

        $childId = DB::table('children')->insertGetId([
            'guardian_id' => $guardian->id,
            'full_name' => $validated['full_name'],
            'birth_date' => $validated['birth_date'],
            'gender' => $validated['gender'] ?? null,
            'medical_notes' => $validated['medical_notes'] ?? null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->log($user->id, 'guardian.child_created', 'child', $childId, [], $request);

        $child = DB::table('children')->where('id', $childId)->first();
        $child->age = Carbon::parse($child->birth_date)->age;

        return response()->json(['message' => 'Profil anak berhasil didaftarkan.', 'child' => $child], 201);
    }

    public function updateChild(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $guardian = $this->resolveGuardian($user->id, $user->branch_id);
        $child = DB::table('children')->where('id', $id)->first();

        if (! $child) {
            return response()->json(['message' => 'Profil anak tidak ditemukan.'], 404);
        }
        // Strict Anti-IDOR check
        if ($user->role !== 'admin' && (int) $child->guardian_id !== (int) $guardian->id) {
            return response()->json(['message' => 'Akses ditolak: Anda hanya dapat memperbarui profil anak Anda sendiri.'], 403);
        }

        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:150'],
            'birth_date' => ['sometimes', 'date', 'before:today'],
            'gender' => ['nullable', 'in:Laki-laki,Perempuan'],
            'medical_notes' => ['nullable', 'string', 'max:500'],
        ]);

        if (! empty($validated['birth_date']) && Carbon::parse($validated['birth_date'])->age >= 18) {
            return response()->json([
                'message' => 'Profil anak pada Akun Orang Tua / Wali khusus peserta berusia di bawah 18 tahun.',
                'errors' => ['birth_date' => ['Usia anak wajib di bawah 18 tahun.']],
            ], 422);
        }

        DB::table('children')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));
        $this->audit->log($user->id, 'guardian.child_updated', 'child', $id, [], $request);

        return response()->json(['message' => 'Profil anak diperbarui.', 'child' => DB::table('children')->where('id', $id)->first()]);
    }

    public function childReportPdf(Request $request, int $id): Response
    {
        $fmt = strtolower((string) $request->query('format', 'pdf')) === 'word' ? 'word' : 'pdf';

        return $this->reports->exportChildProgressReport($request->user(), $id, $fmt);
    }

    public function childReportWord(Request $request, int $id): Response
    {
        return $this->reports->exportChildProgressReport($request->user(), $id, 'word');
    }

    private function resolveGuardian(int $userId, ?int $branchId): object
    {
        $guardian = DB::table('guardians')->where('user_id', $userId)->first();
        if ($guardian) {
            return $guardian;
        }
        $now = now();
        $id = DB::table('guardians')->insertGetId([
            'user_id' => $userId,
            'branch_id' => $branchId,
            'relationship' => 'Orang Tua',
            'pdp_consent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('guardians')->where('id', $id)->first();
    }
}

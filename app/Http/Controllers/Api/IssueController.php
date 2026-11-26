<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IssueController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $issues = DB::table('support_issues as si')
            ->leftJoin('users as rv', 'rv.id', '=', 'si.resolved_by')
            ->select('si.*', 'rv.name as resolved_by_name')
            ->when($user->role !== 'admin', fn ($q) => $q->where('si.user_id', $user->id))
            ->orderByDesc('si.created_at')
            ->limit(40)
            ->get();

        return response()->json(['issues' => $issues]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isGuestAccount()) {
            return response()->json([
                'code' => 'GUEST_UPGRADE_REQUIRED',
                'message' => 'Akun Tamu tidak dapat mengirim tiket masalah. Silakan login Akun Member (18+) atau Orang Tua / Wali.',
            ], 403);
        }

        $validated = $request->validate([
            'category' => ['required', 'in:schedule_sync,account_approval,payment,attendance_qr,facility,other'],
            'priority' => ['nullable', 'in:low,medium,high'],
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:1500'],
        ]);

        $ticketCode = 'FS-ISS-'.date('Y').'-'.strtoupper(Str::random(4));
        $now = now();

        $id = DB::table('support_issues')->insertGetId([
            'ticket_code' => $ticketCode,
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
            'category' => $validated['category'],
            'priority' => $validated['priority'] ?? 'medium',
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->log($user->id, 'issue.created', 'support_issue', $id, [
            'ticket_code' => $ticketCode,
            'category' => $validated['category'],
        ], $request);

        $adminIds = DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id');
        foreach ($adminIds as $aid) {
            $this->audit->notify(
                (int) $aid,
                'issue_reported',
                "Tiket Masalah Baru {$ticketCode}",
                "{$user->name} ({$user->role}) melaporkan: {$validated['subject']}",
                ['ticket_code' => $ticketCode]
            );
        }

        return response()->json([
            'message' => "Laporan masalah {$ticketCode} berhasil dikirim ke Admin FitSpace.",
            'issue' => DB::table('support_issues')->where('id', $id)->first(),
        ], 201);
    }
}

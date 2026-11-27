<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $activeMembership = DB::table('memberships as m')
            ->join('membership_plans as p', 'p.id', '=', 'm.plan_id')
            ->select('m.*', 'p.name as plan_name', 'p.plan_type', 'p.includes_children')
            ->where('m.user_id', $user->id)
            ->where('m.status', 'active')
            ->where('m.expires_at', '>', now())
            ->orderByDesc('m.expires_at')
            ->first();

        $bookings = DB::table('bookings as b')
            ->join('class_schedules as s', 's.id', '=', 'b.schedule_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'b.child_id')
            ->leftJoin('attendance_qr_codes as q', 'q.booking_id', '=', 'b.id')
            ->select(
                'b.id', 'b.booking_code', 'b.booking_type', 'b.final_amount', 'b.status',
                'b.reschedule_count', 's.class_id', 's.starts_at', 's.ends_at',
                'c.name as class_name', 'ch.full_name as child_name',
                'q.display_code as qr_code', 'q.used_at as qr_used_at'
            )
            ->where('b.user_id', $user->id)
            ->orderByDesc('b.created_at')
            ->limit(50)
            ->get();

        $payments = DB::table('payments')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $reports = DB::table('progress_reports as pr')
            ->join('classes as c', 'c.id', '=', 'pr.class_id')
            ->join('coaches as co', 'co.id', '=', 'pr.coach_id')
            ->join('users as cu', 'cu.id', '=', 'co.user_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'pr.child_id')
            ->select('pr.*', 'c.name as class_name', 'cu.name as coach_name', 'ch.full_name as child_name')
            ->where('pr.user_id', $user->id)
            ->where('pr.is_published', true)
            ->orderByDesc('pr.evaluated_at')
            ->limit(30)
            ->get();

        $reportIds = $reports->pluck('id')->all();
        $detailsByReport = count($reportIds) > 0
            ? DB::table('progress_details')->whereIn('progress_report_id', $reportIds)->get()->groupBy('progress_report_id')
            : collect();
        $reports = $reports->map(function ($r) use ($detailsByReport) {
            $r->details = ($detailsByReport->get($r->id) ?? collect())->values()->all();

            return $r;
        });

        $issues = DB::table('support_issues')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $pendingMemberships = DB::table('memberships as m')
            ->join('membership_plans as p', 'p.id', '=', 'm.plan_id')
            ->select('m.*', 'p.name as plan_name', 'p.price')
            ->where('m.user_id', $user->id)
            ->where('m.status', 'pending_payment')
            ->orderByDesc('m.created_at')
            ->limit(10)
            ->get();

        $scheduleNotifications = DB::table('notifications')
            ->where('user_id', $user->id)
            ->whereIn('type', ['coach_schedule_rescheduled', 'booking_confirmed', 'membership_approved', 'account_approved'])
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        return response()->json([
            'active_membership' => $activeMembership,
            'pending_memberships' => $pendingMemberships,
            'bookings' => $bookings,
            'payments' => $payments,
            'progress_reports' => $reports,
            'issues' => $issues,
            'notifications' => $scheduleNotifications,
            'approval_status' => $user->approval_status ?? 'approved',
            'approval_notes' => $user->approval_notes,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MembershipController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayService $payments,
        private readonly AuditService $audit,
    ) {}

    public function purchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
            'gateway' => ['nullable', 'in:midtrans,xendit'],
            'auto_confirm_demo' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $plan = DB::table('membership_plans')->where('id', $validated['plan_id'])->where('is_active', true)->first();
        if (! $plan) {
            return response()->json(['message' => 'Paket membership tidak tersedia.'], 404);
        }

        $gateway = ($validated['gateway'] ?? 'midtrans') === 'xendit' ? 'xendit' : 'midtrans';
        $now = now();

        [$membershipId, $paymentId] = DB::transaction(function () use ($user, $plan, $gateway, $now) {
            $mId = DB::table('memberships')->insertGetId([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'branch_id' => $user->branch_id,
                'total_quota' => $plan->session_quota,
                'remaining_quota' => $plan->session_quota,
                'starts_at' => $now,
                'expires_at' => $now->copy()->addDays((int) $plan->duration_days),
                'status' => 'pending_payment',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $orderId = 'MEM-'.date('Ymd').'-'.strtoupper(Str::random(6));
            $pId = DB::table('payments')->insertGetId([
                'order_id' => $orderId,
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
                'membership_id' => $mId,
                'gateway' => $gateway,
                'payment_method' => $gateway === 'xendit' ? 'va_invoice' : 'qris',
                'amount' => $plan->price,
                'status' => 'pending',
                'expired_at' => $now->copy()->addHours(24),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('membership_transactions')->insert([
                'membership_id' => $mId,
                'payment_id' => $pId,
                'type' => 'purchase',
                'quota_delta' => (int) $plan->session_quota,
                'notes' => "Pembelian paket {$plan->name}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [$mId, $pId];
        });

        if (! empty($validated['auto_confirm_demo']) && config('fitspace.demo_mode', false)) {
            $this->payments->markPaymentPaid($paymentId, 'DEMO-MEM-PAID');
        }

        $membership = DB::table('memberships')->where('id', $membershipId)->first();
        $payment = DB::table('payments')->where('id', $paymentId)->first();

        $this->audit->log($user->id, 'membership.purchased', 'membership', $membershipId, [
            'plan_id' => $plan->id,
            'status' => $membership->status,
        ], $request);

        if ($membership->status === 'pending_payment') {
            $adminIds = DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id');
            foreach ($adminIds as $aid) {
                $this->audit->notify(
                    (int) $aid,
                    'membership_pending_admin',
                    'Pengajuan Pemilihan Membership Baru',
                    "{$user->name} mengajukan paket {$plan->name}. Tinjau & setujui di panel Admin.",
                    ['membership_id' => $membershipId]
                );
            }
        }

        return response()->json([
            'message' => $membership->status === 'active'
                ? "Paket {$plan->name} berhasil diaktifkan!"
                : "Pengajuan paket {$plan->name} berhasil dikirim! Menunggu persetujuan Admin FitSpace untuk aktivasi kuota.",
            'membership' => $membership,
            'payment' => $payment,
        ], 201);
    }
}

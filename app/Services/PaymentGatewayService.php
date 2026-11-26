<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentGatewayService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly BookingService $bookings,
    ) {}

    public function handleMidtransWebhook(array $payload): array
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signatureKey = (string) ($payload['signature_key'] ?? '');
        $serverKey = (string) config('fitspace.payment.midtrans_server_key', '');

        $expectedSignature = $serverKey !== ''
            ? hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey)
            : '';

        $signatureValid = $serverKey !== '' && $signatureKey !== '' && hash_equals($expectedSignature, $signatureKey);
        $payment = $orderId !== '' ? DB::table('payments')->where('order_id', $orderId)->first() : null;

        DB::table('payment_gateway_logs')->insert([
            'payment_id' => $payment?->id,
            'gateway' => 'midtrans',
            'event_type' => (string) ($payload['transaction_status'] ?? 'unknown'),
            'signature_valid' => $signatureValid,
            'sanitized_payload' => json_encode($this->audit->redactSensitive($payload)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $signatureValid) {
            throw new HttpException(403, 'Verifikasi signature Midtrans gagal.');
        }

        if (! $payment) {
            throw new HttpException(404, 'Transaksi pembayaran tidak ditemukan.');
        }

        $txStatus = strtolower((string) ($payload['transaction_status'] ?? 'pending'));
        $mapped = match ($txStatus) {
            'settlement', 'capture' => 'paid',
            'expire' => 'expired',
            'cancel', 'deny', 'failure' => 'failed',
            'refund' => 'refunded',
            'partial_refund' => 'partially_refunded',
            default => 'pending',
        };

        return $this->applyGatewayStatus($payment, $mapped, (string) ($payload['transaction_id'] ?? ''));
    }

    public function handleXenditWebhook(array $payload, string $callbackToken): array
    {
        $configuredToken = (string) config('fitspace.payment.xendit_webhook_token', '');
        $signatureValid = $configuredToken !== '' && $callbackToken !== '' && hash_equals($configuredToken, $callbackToken);

        $orderId = (string) ($payload['external_id'] ?? $payload['order_id'] ?? '');
        $payment = $orderId !== '' ? DB::table('payments')->where('order_id', $orderId)->first() : null;

        DB::table('payment_gateway_logs')->insert([
            'payment_id' => $payment?->id,
            'gateway' => 'xendit',
            'event_type' => (string) ($payload['status'] ?? 'unknown'),
            'signature_valid' => $signatureValid,
            'sanitized_payload' => json_encode($this->audit->redactSensitive($payload)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $signatureValid) {
            throw new HttpException(403, 'Verifikasi callback token Xendit gagal.');
        }

        if (! $payment) {
            throw new HttpException(404, 'Transaksi pembayaran tidak ditemukan.');
        }

        $rawStatus = strtoupper((string) ($payload['status'] ?? 'PENDING'));
        $mapped = match ($rawStatus) {
            'PAID', 'SETTLED' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED' => 'failed',
            'REFUNDED' => 'refunded',
            default => 'pending',
        };

        return $this->applyGatewayStatus($payment, $mapped, (string) ($payload['id'] ?? ''));
    }

    public function markPaymentPaid(int $paymentId, string $externalRef = ''): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            throw new HttpException(404, 'Pembayaran tidak ditemukan.');
        }

        return $this->applyGatewayStatus($payment, 'paid', $externalRef);
    }

    private function applyGatewayStatus(object $payment, string $newStatus, string $externalRef = ''): array
    {
        return DB::transaction(function () use ($payment, $newStatus, $externalRef) {
            $locked = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->first();
            $now = now();
            $qr = null;

            if ($newStatus === 'paid') {
                if ($locked->status !== 'paid') {
                    DB::table('payments')->where('id', $locked->id)->update([
                        'status' => 'paid',
                        'external_reference' => $externalRef ?: $locked->external_reference,
                        'paid_at' => $now,
                        'updated_at' => $now,
                    ]);

                    // Business Rule #14: Only PAID transactions create income ledger entries (idempotent)
                    $ledgerExists = DB::table('financial_transactions')
                        ->where('payment_id', $locked->id)
                        ->where('direction', 'income')
                        ->exists();

                    if (! $ledgerExists && (float) $locked->amount > 0) {
                        DB::table('financial_transactions')->insert([
                            'branch_id' => $locked->branch_id,
                            'payment_id' => $locked->id,
                            'direction' => 'income',
                            'category' => $locked->membership_id ? 'membership' : 'booking_session',
                            'amount' => $locked->amount,
                            'description' => "Pembayaran terverifikasi {$locked->order_id} ({$locked->gateway})",
                            'transacted_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                // Confirm booking & generate single-use QR (Business Rule #7 & #11)
                if ($locked->booking_id) {
                    $booking = DB::table('bookings')->where('id', $locked->booking_id)->lockForUpdate()->first();
                    if ($booking && $booking->status === 'pending_payment') {
                        DB::table('bookings')->where('id', $booking->id)->update([
                            'status' => 'confirmed',
                            'updated_at' => $now,
                        ]);
                    }
                    if ($booking) {
                        $sched = DB::table('class_schedules')->where('id', $booking->schedule_id)->first();
                        $qr = $this->bookings->issueQrCode((int) $booking->id, $sched?->ends_at ?? $now->copy()->addDay());
                    }
                }

                // Activate membership if linked
                if ($locked->membership_id) {
                    DB::table('memberships')->where('id', $locked->membership_id)->update([
                        'status' => 'active',
                        'updated_at' => $now,
                    ]);
                }

                $this->audit->notify(
                    (int) $locked->user_id,
                    'payment_paid',
                    'Pembayaran Berhasil Diverifikasi',
                    "Transaksi {$locked->order_id} telah lunas dan layanan Anda aktif.",
                    ['payment_id' => $locked->id]
                );
            } elseif (in_array($newStatus, ['failed', 'expired'], true) && $locked->status === 'pending') {
                DB::table('payments')->where('id', $locked->id)->update([
                    'status' => $newStatus,
                    'external_reference' => $externalRef ?: $locked->external_reference,
                    'updated_at' => $now,
                ]);

                // Release reserved booking capacity if payment failed/expired
                if ($locked->booking_id) {
                    $booking = DB::table('bookings')->where('id', $locked->booking_id)->lockForUpdate()->first();
                    if ($booking && $booking->status === 'pending_payment') {
                        DB::table('bookings')->where('id', $booking->id)->update([
                            'status' => 'cancelled',
                            'cancelled_at' => $now,
                            'cancel_reason' => "Pembayaran {$newStatus}",
                            'updated_at' => $now,
                        ]);
                        $sched = DB::table('class_schedules')->where('id', $booking->schedule_id)->lockForUpdate()->first();
                        if ($sched && (int) $sched->booked_count > 0) {
                            DB::table('class_schedules')->where('id', $sched->id)->decrement('booked_count');
                        }
                    }
                }

                if ($locked->membership_id) {
                    DB::table('memberships')->where('id', $locked->membership_id)->update([
                        'status' => 'cancelled',
                        'updated_at' => $now,
                    ]);
                }
            }

            $this->audit->log((int) $locked->user_id, 'payment.status_updated', 'payment', (int) $locked->id, [
                'order_id' => $locked->order_id,
                'status' => $newStatus,
                'gateway' => $locked->gateway,
            ]);

            return [
                'payment' => DB::table('payments')->where('id', $locked->id)->first(),
                'qr' => $qr,
            ];
        });
    }

    public function processRefund(User $admin, int $paymentId, ?float $amount, string $reason): object
    {
        return DB::transaction(function () use ($admin, $paymentId, $amount, $reason) {
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (! $payment) {
                throw new HttpException(404, 'Transaksi pembayaran tidak ditemukan.');
            }
            if ($payment->status !== 'paid') {
                throw ValidationException::withMessages(['payment' => ['Hanya pembayaran berstatus paid yang dapat direfund.']]);
            }

            $refundAmount = $amount !== null ? min((float) $payment->amount, max(0.01, $amount)) : (float) $payment->amount;
            $isPartial = $refundAmount < (float) $payment->amount;
            $now = now();

            $refundId = DB::table('refunds')->insertGetId([
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'processed_by' => $admin->id,
                'amount' => $refundAmount,
                'reason' => $reason,
                'status' => 'processed',
                'refunded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('payments')->where('id', $payment->id)->update([
                'status' => $isPartial ? 'partially_refunded' : 'refunded',
                'updated_at' => $now,
            ]);

            // Business Rule #14: Refund reduces net financial income
            DB::table('financial_transactions')->insert([
                'branch_id' => $payment->branch_id,
                'payment_id' => $payment->id,
                'refund_id' => $refundId,
                'direction' => 'refund',
                'category' => 'refund',
                'amount' => $refundAmount,
                'description' => "Refund {$payment->order_id}: {$reason}",
                'transacted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! $isPartial && $payment->booking_id) {
                $booking = DB::table('bookings')->where('id', $payment->booking_id)->lockForUpdate()->first();
                if ($booking && in_array($booking->status, ['confirmed', 'pending_payment'], true)) {
                    DB::table('bookings')->where('id', $booking->id)->update([
                        'status' => 'cancelled',
                        'cancelled_at' => $now,
                        'cancel_reason' => "Refund diproses: {$reason}",
                        'updated_at' => $now,
                    ]);
                    $sched = DB::table('class_schedules')->where('id', $booking->schedule_id)->lockForUpdate()->first();
                    if ($sched && (int) $sched->booked_count > 0) {
                        DB::table('class_schedules')->where('id', $sched->id)->decrement('booked_count');
                    }
                }
            }

            $this->audit->log($admin->id, 'payment.refunded', 'refund', $refundId, [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'amount' => $refundAmount,
                'reason' => $reason,
            ]);

            $this->audit->notify(
                (int) $payment->user_id,
                'payment_refunded',
                'Refund Pembayaran Diproses',
                "Refund untuk {$payment->order_id} sebesar Rp ".number_format($refundAmount, 0, ',', '.').' telah diproses.',
                ['refund_id' => $refundId]
            );

            return DB::table('refunds')->where('id', $refundId)->first();
        });
    }
}

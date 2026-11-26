<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    public function getSummary(?int $branchId = null, ?string $from = null, ?string $to = null): array
    {
        // Business Rule #14:
        // - Only PAID transactions (recorded in financial_transactions direction='income') count as income.
        // - Pending & failed payments are NEVER counted.
        // - Refunds (direction='refund') and operational expenses reduce net_income.
        $incomeQ = DB::table('financial_transactions as ft')
            ->leftJoin('payments as p', 'p.id', '=', 'ft.payment_id')
            ->where('ft.direction', 'income')
            ->where(function ($q) {
                $q->whereNull('ft.payment_id')
                    ->orWhereIn('p.status', ['paid', 'refunded', 'partially_refunded']);
            });

        $refundQ = DB::table('financial_transactions')->where('direction', 'refund');
        $expenseQ = DB::table('expenses');

        if ($branchId) {
            $incomeQ->where('ft.branch_id', $branchId);
            $refundQ->where('branch_id', $branchId);
            $expenseQ->where('branch_id', $branchId);
        }
        if ($from) {
            $incomeQ->whereDate('ft.transacted_at', '>=', $from);
            $refundQ->whereDate('transacted_at', '>=', $from);
            $expenseQ->whereDate('incurred_on', '>=', $from);
        }
        if ($to) {
            $incomeQ->whereDate('ft.transacted_at', '<=', $to);
            $refundQ->whereDate('transacted_at', '<=', $to);
            $expenseQ->whereDate('incurred_on', '<=', $to);
        }

        $totalIncome = (float) $incomeQ->sum('ft.amount');
        $totalRefunds = (float) $refundQ->sum('amount');
        $totalExpenses = (float) $expenseQ->sum('amount');
        $netIncome = $totalIncome - $totalRefunds - $totalExpenses;

        // 6-month chart series
        $series = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $mIncQ = DB::table('financial_transactions')
                ->where('direction', 'income')
                ->whereBetween('transacted_at', [$monthStart, $monthEnd]);
            $mRefQ = DB::table('financial_transactions')
                ->where('direction', 'refund')
                ->whereBetween('transacted_at', [$monthStart, $monthEnd]);
            $mExpQ = DB::table('expenses')
                ->whereBetween('incurred_on', [$monthStart->toDateString(), $monthEnd->toDateString()]);

            if ($branchId) {
                $mIncQ->where('branch_id', $branchId);
                $mRefQ->where('branch_id', $branchId);
                $mExpQ->where('branch_id', $branchId);
            }

            $series[] = [
                'label' => $monthStart->format('M'),
                'income' => max(0, (float) $mIncQ->sum('amount') - (float) $mRefQ->sum('amount')),
                'expense' => (float) $mExpQ->sum('amount'),
            ];
        }

        return [
            'total_income' => round($totalIncome, 2),
            'total_refunds' => round($totalRefunds, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_income' => round($netIncome, 2),
            'monthly_series' => $series,
        ];
    }
}

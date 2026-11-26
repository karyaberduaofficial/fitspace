<?php

namespace App\Services;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReportExportService
{
    public const VALID_TYPES = [
        'finance_daily', 'finance_weekly', 'finance_monthly',
        'bookings', 'coach_activity', 'schedules', 'attendance',
        'memberships', 'progress', 'reviews', 'promotions',
    ];

    public function __construct(
        private readonly XlsxExporter $xlsx,
        private readonly DocxExporter $docx,
        private readonly AuditService $audit,
    ) {}

    public function export(User $user, string $type, string $format, array $filters = []): Response
    {
        if (! in_array($type, self::VALID_TYPES, true)) {
            throw new HttpException(422, 'Jenis laporan tidak dikenali.');
        }

        $format = strtolower($format);
        if (! in_array($format, ['pdf', 'excel', 'word'], true)) {
            $format = 'pdf';
        }

        $this->assertRoleCanExport($user, $type);

        [$title, $subtitle, $headers, $rows, $kpis, $totals] = $this->buildDataset($user, $type, $filters);

        $docCode = 'FS-'.strtoupper(substr(str_replace('_', '', $type), 0, 6)).'-'.now()->format('Ymd');
        $generatedAt = now()->format('d M Y H:i').' WIB';
        $generatedBy = $user->name.' ('.strtoupper($user->role).')';

        DB::table('report_exports')->insert([
            'user_id' => $user->id,
            'report_type' => $type,
            'format' => $format,
            'filters' => json_encode($filters),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->log($user->id, 'report.exported', 'report_export', null, [
            'report_type' => $type,
            'format' => $format,
            'doc_code' => $docCode,
        ]);

        $meta = [
            'doc_code' => $docCode,
            'subtitle' => $subtitle,
            'generated_at' => $generatedAt,
            'generated_by' => $generatedBy,
            'kpis' => $kpis,
            'totals' => $totals,
        ];

        if ($format === 'excel') {
            $binary = $this->xlsx->build($title, $headers, $rows, $meta);

            return response($binary, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="fitspace-'.$type.'.xlsx"',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        if ($format === 'word') {
            $binary = $this->docx->buildTableReport($title, $headers, $rows, $meta);

            return response($binary, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="fitspace-'.$type.'.docx"',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $pdf = Pdf::loadView('reports.table', [
            'title' => $title,
            'subtitle' => $subtitle,
            'docCode' => $docCode,
            'logoDataUri' => $this->getFitSpaceLogoDataUri(),
            'generatedAt' => $generatedAt,
            'generatedBy' => $generatedBy,
            'headers' => $headers,
            'rows' => $rows,
            'kpis' => $kpis,
            'totals' => $totals,
        ])->setPaper('a4', 'landscape');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="fitspace-'.$type.'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function exportChildProgressReport(User $user, int $childId, string $format = 'pdf'): Response
    {
        $child = DB::table('children as ch')
            ->join('guardians as g', 'g.id', '=', 'ch.guardian_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->select('ch.*', 'g.user_id as guardian_user_id', 'u.name as guardian_name', 'g.relationship')
            ->where('ch.id', $childId)
            ->first();

        if (! $child) {
            throw new HttpException(404, 'Profil anak tidak ditemukan.');
        }

        // Strict Anti-IDOR enforcement
        if ($user->role !== 'admin' && (int) $child->guardian_user_id !== (int) $user->id) {
            throw new HttpException(403, 'Akses ditolak: Anda hanya dapat mengunduh laporan perkembangan anak di bawah perwalian Anda.');
        }

        $reports = DB::table('progress_reports as pr')
            ->join('classes as c', 'c.id', '=', 'pr.class_id')
            ->join('coaches as co', 'co.id', '=', 'pr.coach_id')
            ->join('users as cu', 'cu.id', '=', 'co.user_id')
            ->select('pr.*', 'c.name as class_name', 'cu.name as coach_name')
            ->where('pr.child_id', $childId)
            ->orderByDesc('pr.evaluated_at')
            ->get()
            ->map(function ($r) {
                $r->details = DB::table('progress_details')->where('progress_report_id', $r->id)->get()->all();

                return $r;
            });

        $attendanceSummary = DB::table('attendance')
            ->where('child_id', $childId)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as attended")
            ->first();

        $childAge = Carbon::parse($child->birth_date)->age;
        $generatedAt = now()->format('d M Y H:i').' WIB';
        $format = strtolower($format) === 'word' ? 'word' : 'pdf';

        $this->audit->log($user->id, 'child.report_downloaded', 'child', $childId, ['format' => $format]);

        if ($format === 'word') {
            $binary = $this->docx->buildChildProgressDocx($child, $childAge, $reports->all(), $attendanceSummary, $generatedAt);

            return response($binary, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="fitspace-rapor-anak-'.$childId.'.docx"',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $pdf = Pdf::loadView('reports.child-progress', [
            'child' => $child,
            'childAge' => $childAge,
            'reports' => $reports,
            'attendanceSummary' => $attendanceSummary,
            'logoDataUri' => $this->getFitSpaceLogoDataUri(),
            'generatedAt' => $generatedAt,
        ])->setPaper('a4', 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="fitspace-rapor-anak-'.$childId.'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function exportChildProgressPdf(User $user, int $childId): Response
    {
        return $this->exportChildProgressReport($user, $childId, 'pdf');
    }

    private function getFitSpaceLogoDataUri(): string
    {
        $svgPath = public_path('icons/fitspace-logo.svg');
        if (is_file($svgPath)) {
            $svg = (string) file_get_contents($svgPath);

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        }

        return '';
    }

    private function formatRp(float $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    private function formatDateTime(?string $dt, bool $withTime = true): string
    {
        if (! $dt) {
            return '-';
        }
        try {
            return Carbon::parse($dt)->format($withTime ? 'd M Y H:i' : 'd M Y');
        } catch (\Throwable) {
            return $dt;
        }
    }

    private function assertRoleCanExport(User $user, string $type): void
    {
        if ($user->isGuestAccount()) {
            throw new HttpException(403, 'Akun Tamu tidak berwenang mengunduh laporan. Silakan login Akun Member (18+) atau Orang Tua / Wali.');
        }
        if (! $user->isApproved()) {
            throw new HttpException(403, 'Akun Anda masih menunggu persetujuan sebelum dapat mengunduh laporan.');
        }
        if ($user->role === 'admin') {
            return;
        }
        $coachAllowed = ['coach_activity', 'schedules', 'attendance', 'progress', 'reviews'];
        $memberAllowed = ['bookings', 'attendance', 'memberships', 'progress', 'reviews'];

        if ($user->role === 'coach' && in_array($type, $coachAllowed, true)) {
            return;
        }
        if (in_array($user->role, ['member', 'guardian'], true) && in_array($type, $memberAllowed, true)) {
            return;
        }

        throw new HttpException(403, 'Akses ditolak: peran Anda tidak berwenang mengekspor jenis laporan ini.');
    }

    private function buildDataset(User $user, string $type, array $filters): array
    {
        $branchId = isset($filters['branch_id']) && is_numeric($filters['branch_id']) ? (int) $filters['branch_id'] : null;

        if (in_array($type, ['finance_daily', 'finance_weekly', 'finance_monthly'], true)) {
            $days = match ($type) {
                'finance_daily' => 1,
                'finance_weekly' => 7,
                default => 30,
            };
            $periodLabel = match ($type) {
                'finance_daily' => 'Harian (24 Jam Terakhir)',
                'finance_weekly' => 'Mingguan (7 Hari Terakhir)',
                default => 'Bulanan (30 Hari Terakhir)',
            };
            $since = now()->subDays($days)->startOfDay();

            $tx = DB::table('financial_transactions as ft')
                ->leftJoin('branches as b', 'b.id', '=', 'ft.branch_id')
                ->leftJoin('payments as p', 'p.id', '=', 'ft.payment_id')
                ->select('ft.transacted_at', 'b.name as branch_name', 'p.order_id', 'p.gateway', 'ft.direction', 'ft.category', 'ft.amount', 'ft.description')
                ->where('ft.transacted_at', '>=', $since)
                ->when($branchId, fn ($q) => $q->where('ft.branch_id', $branchId))
                ->orderByDesc('ft.transacted_at')
                ->get();

            $totalIncome = (float) $tx->where('direction', 'income')->sum('amount');
            $totalRefunds = (float) $tx->where('direction', 'refund')->sum('amount');
            $totalExpenses = (float) DB::table('expenses')
                ->whereDate('incurred_on', '>=', $since->toDateString())
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->sum('amount');
            $net = $totalIncome - $totalRefunds - $totalExpenses;

            $rows = $tx->map(fn ($r) => [
                $this->formatDateTime($r->transacted_at),
                (string) ($r->branch_name ?? 'Pusat'),
                (string) ($r->order_id ?? '-'),
                strtoupper((string) ($r->gateway ?? 'INTERNAL')),
                strtoupper((string) $r->direction),
                (string) $r->category,
                (float) $r->amount,
                (string) $r->description,
            ])->all();

            return [
                'Laporan Keuangan — '.$periodLabel,
                'Rekapitulasi transaksi terverifikasi (hanya transaksi lunas; refund & pengeluaran dikurangkan otomatis)',
                ['Tanggal & Waktu', 'Cabang', 'Order ID', 'Gateway', 'Arah', 'Kategori', 'Nominal (Rp)', 'Keterangan'],
                $rows,
                [
                    ['label' => 'Pemasukan Paid', 'value' => $this->formatRp($totalIncome)],
                    ['label' => 'Total Refund', 'value' => $this->formatRp($totalRefunds)],
                    ['label' => 'Pengeluaran Operasional', 'value' => $this->formatRp($totalExpenses)],
                    ['label' => 'Pendapatan Bersih', 'value' => $this->formatRp($net)],
                ],
                [0 => 'TOTAL ARUS KAS', 6 => round($totalIncome - $totalRefunds, 2), 7 => 'Bersih setelah pengeluaran: '.$this->formatRp($net)],
            ];
        }

        if ($type === 'bookings') {
            $q = DB::table('bookings as bk')
                ->join('users as u', 'u.id', '=', 'bk.user_id')
                ->leftJoin('children as ch', 'ch.id', '=', 'bk.child_id')
                ->join('class_schedules as s', 's.id', '=', 'bk.schedule_id')
                ->join('branches as b', 'b.id', '=', 's.branch_id')
                ->join('classes as c', 'c.id', '=', 's.class_id')
                ->select('bk.booking_code', 'u.name as user_name', 'ch.full_name as child_name', 'b.name as branch_name', 'c.name as class_name', 's.starts_at', 'bk.booking_type', 'bk.final_amount', 'bk.status')
                ->orderByDesc('bk.created_at');

            if (in_array($user->role, ['member', 'guardian'], true)) {
                $q->where('bk.user_id', $user->id);
            }
            if ($branchId) {
                $q->where('s.branch_id', $branchId);
            }

            $list = $q->get();
            $totalVal = (float) $list->sum('final_amount');
            $confirmed = $list->whereIn('status', ['confirmed', 'attended'])->count();
            $cancelled = $list->where('status', 'cancelled')->count();

            $rows = $list->map(fn ($r) => [
                $r->booking_code,
                $r->child_name ? "{$r->child_name} (Wali: {$r->user_name})" : $r->user_name,
                $r->branch_name,
                $r->class_name,
                $this->formatDateTime($r->starts_at),
                $r->booking_type === 'membership' ? 'Kuota Membership' : 'Per Sesi',
                (float) $r->final_amount,
                strtoupper($r->status),
            ])->all();

            return [
                'Laporan Booking Kelas',
                'Daftar pemesanan kelas peserta lintas cabang & metode pembayaran',
                ['Kode Booking', 'Peserta', 'Cabang', 'Kelas', 'Jadwal', 'Tipe', 'Nominal (Rp)', 'Status'],
                $rows,
                [
                    ['label' => 'Total Booking', 'value' => count($rows).' Sesi'],
                    ['label' => 'Terkonfirmasi / Hadir', 'value' => $confirmed.' Sesi'],
                    ['label' => 'Dibatalkan', 'value' => $cancelled.' Sesi'],
                    ['label' => 'Total Nilai Booking', 'value' => $this->formatRp($totalVal)],
                ],
                [0 => 'TOTAL NILAI BOOKING', 6 => round($totalVal, 2)],
            ];
        }

        if ($type === 'coach_activity') {
            $q = DB::table('class_schedules as s')
                ->join('coaches as co', 'co.id', '=', 's.coach_id')
                ->join('users as u', 'u.id', '=', 'co.user_id')
                ->join('classes as c', 'c.id', '=', 's.class_id')
                ->join('branches as b', 'b.id', '=', 's.branch_id')
                ->join('rooms as rm', 'rm.id', '=', 's.room_id')
                ->select('u.name as coach_name', 'b.name as branch_name', 'c.name as class_name', 'rm.name as room_name', 's.starts_at', 's.booked_count', 's.capacity', 's.status')
                ->orderByDesc('s.starts_at');

            if ($user->role === 'coach') {
                $q->where('co.user_id', $user->id);
            }

            $list = $q->get();
            $totalPeserta = (int) $list->sum('booked_count');
            $totalCap = (int) $list->sum('capacity');
            $occ = $totalCap > 0 ? round(($totalPeserta / $totalCap) * 100, 1) : 0;

            $rows = $list->map(fn ($r) => [
                $r->coach_name, $r->branch_name, $r->class_name, $r->room_name, $this->formatDateTime($r->starts_at), (int) $r->booked_count, (int) $r->capacity, strtoupper($r->status),
            ])->all();

            return [
                'Laporan Aktivitas Coach',
                'Rekap sesi mengajar, ruang studio, dan okupansi peserta per Coach',
                ['Coach', 'Cabang', 'Kelas', 'Ruangan', 'Jadwal', 'Terisi', 'Kapasitas', 'Status'],
                $rows,
                [
                    ['label' => 'Total Sesi Mengajar', 'value' => count($rows).' Sesi'],
                    ['label' => 'Total Peserta Terdaftar', 'value' => $totalPeserta.' Orang'],
                    ['label' => 'Rata-Rata Okupansi', 'value' => $occ.'%'],
                ],
                [0 => 'TOTAL OKUPANSI', 5 => $totalPeserta, 6 => $totalCap],
            ];
        }

        if ($type === 'schedules') {
            $q = DB::table('class_schedules as s')
                ->join('classes as c', 'c.id', '=', 's.class_id')
                ->join('branches as b', 'b.id', '=', 's.branch_id')
                ->join('rooms as rm', 'rm.id', '=', 's.room_id')
                ->join('coaches as co', 'co.id', '=', 's.coach_id')
                ->join('users as u', 'u.id', '=', 'co.user_id')
                ->select('b.name as branch_name', 'c.name as class_name', 'u.name as coach_name', 'rm.name as room_name', 's.starts_at', 's.booked_count', 's.capacity')
                ->orderBy('s.starts_at');

            if ($user->role === 'coach') {
                $q->where('co.user_id', $user->id);
            }

            $list = $q->get();
            $rows = $list->map(fn ($r) => [
                $r->branch_name, $r->class_name, $r->coach_name, $r->room_name, $this->formatDateTime($r->starts_at), (int) $r->booked_count, (int) $r->capacity,
            ])->all();

            return [
                'Laporan Jadwal Kelas',
                'Daftar jadwal studio tervalidasi anti-bentrok Coach & Ruangan',
                ['Cabang', 'Kelas', 'Coach', 'Ruangan', 'Waktu Mulai', 'Terisi', 'Kapasitas'],
                $rows,
                [
                    ['label' => 'Total Jadwal', 'value' => count($rows).' Jadwal'],
                    ['label' => 'Total Peserta', 'value' => (int) $list->sum('booked_count').' Peserta'],
                    ['label' => 'Total Kapasitas', 'value' => (int) $list->sum('capacity').' Slot'],
                ],
                [0 => 'TOTAL SLOT', 5 => (int) $list->sum('booked_count'), 6 => (int) $list->sum('capacity')],
            ];
        }

        if ($type === 'attendance') {
            $q = DB::table('attendance as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('children as ch', 'ch.id', '=', 'a.child_id')
                ->join('class_schedules as s', 's.id', '=', 'a.schedule_id')
                ->join('classes as c', 'c.id', '=', 's.class_id')
                ->select('a.checked_in_at', 'u.name as user_name', 'ch.full_name as child_name', 'c.name as class_name', 'a.status', 'a.notes')
                ->orderByDesc('a.checked_in_at');

            if (in_array($user->role, ['member', 'guardian'], true)) {
                $q->where('a.user_id', $user->id);
            } elseif ($user->role === 'coach') {
                $coachId = DB::table('coaches')->where('user_id', $user->id)->value('id');
                $q->where('s.coach_id', $coachId ?: 0);
            }

            $list = $q->get();
            $rows = $list->map(fn ($r) => [
                $this->formatDateTime($r->checked_in_at), $r->child_name ? "{$r->child_name} (Wali: {$r->user_name})" : $r->user_name, $r->class_name, strtoupper($r->status), (string) ($r->notes ?? '-'),
            ])->all();

            return [
                'Laporan Kehadiran Peserta',
                'Rekapitulasi check-in QR Code kehadiran peserta di studio FitSpace',
                ['Waktu Check-in', 'Peserta', 'Kelas', 'Status', 'Catatan'],
                $rows,
                [
                    ['label' => 'Total Kehadiran Tercatat', 'value' => count($rows).' Catatan'],
                    ['label' => 'Hadir Tepat Waktu', 'value' => $list->where('status', 'present')->count().' Sesi'],
                    ['label' => 'Terlambat / Izin', 'value' => $list->whereIn('status', ['late', 'excused'])->count().' Sesi'],
                ],
                [],
            ];
        }

        if ($type === 'memberships') {
            $q = DB::table('memberships as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->join('membership_plans as p', 'p.id', '=', 'm.plan_id')
                ->select('u.name as user_name', 'p.name as plan_name', 'p.price', 'm.remaining_quota', 'm.total_quota', 'm.expires_at', 'm.status')
                ->orderByDesc('m.created_at');

            if (in_array($user->role, ['member', 'guardian'], true)) {
                $q->where('m.user_id', $user->id);
            }

            $list = $q->get();
            $rows = $list->map(fn ($r) => [
                $r->user_name, $r->plan_name, (float) $r->price, (int) $r->remaining_quota, (int) $r->total_quota, $this->formatDateTime($r->expires_at, false), strtoupper($r->status),
            ])->all();

            return [
                'Laporan Membership & Kuota',
                'Status keanggotaan aktif, sisa kuota sesi, dan masa berlaku paket',
                ['Member', 'Nama Paket', 'Harga Paket (Rp)', 'Sisa Kuota', 'Total Kuota', 'Berlaku Hingga', 'Status'],
                $rows,
                [
                    ['label' => 'Total Membership', 'value' => count($rows).' Paket'],
                    ['label' => 'Membership Aktif', 'value' => $list->where('status', 'active')->count().' Aktif'],
                    ['label' => 'Total Sisa Kuota', 'value' => (int) $list->sum('remaining_quota').' Sesi'],
                ],
                [0 => 'TOTAL', 2 => round((float) $list->sum('price'), 2), 3 => (int) $list->sum('remaining_quota'), 4 => (int) $list->sum('total_quota')],
            ];
        }

        if ($type === 'progress') {
            $q = DB::table('progress_reports as pr')
                ->join('users as u', 'u.id', '=', 'pr.user_id')
                ->leftJoin('children as ch', 'ch.id', '=', 'pr.child_id')
                ->join('classes as c', 'c.id', '=', 'pr.class_id')
                ->join('coaches as co', 'co.id', '=', 'pr.coach_id')
                ->join('users as cu', 'cu.id', '=', 'co.user_id')
                ->select('pr.evaluated_at', 'u.name as user_name', 'ch.full_name as child_name', 'c.name as class_name', 'cu.name as coach_name', 'pr.material_studied', 'pr.average_score', 'pr.progress_notes', 'pr.next_recommendation')
                ->orderByDesc('pr.evaluated_at');

            if (in_array($user->role, ['member', 'guardian'], true)) {
                $q->where('pr.user_id', $user->id)->where('pr.is_published', true);
            } elseif ($user->role === 'coach') {
                $q->where('co.user_id', $user->id);
            }

            $list = $q->get();
            $avg = count($list) > 0 ? round((float) $list->avg('average_score'), 2) : 0.0;
            $rows = $list->map(fn ($r) => [
                $this->formatDateTime($r->evaluated_at, false),
                $r->child_name ? "{$r->child_name} (Wali: {$r->user_name})" : $r->user_name,
                $r->class_name,
                $r->coach_name,
                (string) ($r->material_studied ?? '-'),
                (float) $r->average_score,
                (string) ($r->progress_notes ?? ''),
                (string) ($r->next_recommendation ?? '-'),
            ])->all();

            return [
                'Laporan Perkembangan Peserta',
                'Rekapitulasi evaluasi terukur peserta bersama Coach FitSpace (skala 1–4)',
                ['Tanggal', 'Peserta', 'Kelas', 'Coach', 'Materi', 'Skor Rata-Rata', 'Catatan Perkembangan', 'Rekomendasi'],
                $rows,
                [
                    ['label' => 'Total Evaluasi', 'value' => count($rows).' Laporan'],
                    ['label' => 'Rata-Rata Skor', 'value' => number_format($avg, 2).' / 4.00'],
                ],
                [0 => 'RATA-RATA SKOR', 5 => $avg],
            ];
        }

        if ($type === 'reviews') {
            $q = DB::table('coach_reviews as rv')
                ->join('users as u', 'u.id', '=', 'rv.user_id')
                ->join('coaches as co', 'co.id', '=', 'rv.coach_id')
                ->join('users as cu', 'cu.id', '=', 'co.user_id')
                ->join('classes as c', 'c.id', '=', 'rv.class_id')
                ->select('rv.reviewed_at', 'u.name as reviewer', 'cu.name as coach_name', 'c.name as class_name', 'rv.rating', 'rv.review')
                ->orderByDesc('rv.reviewed_at');

            if (in_array($user->role, ['member', 'guardian'], true)) {
                $q->where('rv.user_id', $user->id);
            } elseif ($user->role === 'coach') {
                $q->where('co.user_id', $user->id);
            }

            $list = $q->get();
            $avgRating = count($list) > 0 ? round((float) $list->avg('rating'), 2) : 0.0;
            $rows = $list->map(fn ($r) => [
                $this->formatDateTime($r->reviewed_at), $r->reviewer, $r->coach_name, $r->class_name, (int) $r->rating, (string) ($r->review ?? ''),
            ])->all();

            return [
                'Laporan Rating & Ulasan Coach',
                'Rekap kepuasan peserta terhadap kualitas sesi & instruktur FitSpace',
                ['Tanggal', 'Peserta', 'Coach', 'Kelas', 'Rating (1–5)', 'Ulasan'],
                $rows,
                [
                    ['label' => 'Total Ulasan', 'value' => count($rows).' Ulasan'],
                    ['label' => 'Rata-Rata Rating', 'value' => number_format($avgRating, 2).' / 5.00'],
                ],
                [0 => 'RATA-RATA RATING', 4 => $avgRating],
            ];
        }

        // promotions
        $promos = DB::table('coupons as cp')
            ->join('promotions as pr', 'pr.id', '=', 'cp.promotion_id')
            ->select('cp.code', 'pr.name', 'pr.discount_type', 'pr.discount_value', 'cp.used_count', 'cp.max_uses', 'cp.is_active')
            ->get();

        $rows = $promos->map(fn ($r) => [
            $r->code, $r->name, strtoupper($r->discount_type), (float) $r->discount_value, (int) $r->used_count, (int) $r->max_uses, $r->is_active ? 'AKTIF' : 'NONAKTIF',
        ])->all();

        return [
            'Laporan Promo & Voucher',
            'Efektivitas kampanye promo dan pemakaian kode voucher FitSpace',
            ['Kode Voucher', 'Nama Promo', 'Tipe', 'Nilai Diskon', 'Terpakai', 'Kuota Maks', 'Status'],
            $rows,
            [
                ['label' => 'Total Kode Voucher', 'value' => count($rows).' Voucher'],
                ['label' => 'Total Penukaran', 'value' => (int) $promos->sum('used_count').' Kali'],
            ],
            [0 => 'TOTAL PEMAKAIAN', 4 => (int) $promos->sum('used_count'), 5 => (int) $promos->sum('max_uses')],
        ];
    }
}

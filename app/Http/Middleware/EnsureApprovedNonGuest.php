<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedNonGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Autentikasi diperlukan.'], 401);
        }

        if ($user->isGuestAccount()) {
            return response()->json([
                'code' => 'GUEST_UPGRADE_REQUIRED',
                'message' => 'Fitur ini tidak tersedia untuk Akun Tamu. Silakan login atau daftar sebagai Member (usia 18+) atau Orang Tua / Wali.',
            ], 403);
        }

        if (! $user->isApproved()) {
            $status = (string) ($user->approval_status ?? 'pending');
            $approver = $user->role === 'coach' ? 'Admin FitSpace' : 'Coach / Pelatih FitSpace';

            if ($status === 'rejected') {
                return response()->json([
                    'code' => 'APPROVAL_REJECTED',
                    'message' => "Pengajuan akun Anda ditolak oleh {$approver}. Catatan: ".($user->approval_notes ?: 'Silakan hubungi Admin FitSpace untuk verifikasi ulang.'),
                ], 403);
            }

            return response()->json([
                'code' => 'APPROVAL_PENDING',
                'message' => "Akun Anda sedang menunggu persetujuan dari {$approver}. Fitur booking, membership, absensi QR, dan laporan akan otomatis aktif setelah akun disetujui.",
            ], 403);
        }

        return $next($request);
    }
}

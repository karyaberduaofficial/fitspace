<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Akses ditolak: autentikasi diperlukan.'], 401);
        }

        if ($user->isGuestAccount() && ! in_array('guest', $roles, true)) {
            return response()->json([
                'code' => 'GUEST_UPGRADE_REQUIRED',
                'message' => 'Akun Tamu hanya dapat mengakses pratinjau terbatas. Silakan login atau daftar Akun Member (18+) atau Orang Tua / Wali untuk melanjutkan.',
            ], 403);
        }

        if (! $user->hasRole(...$roles)) {
            return response()->json([
                'message' => 'Akses ditolak: peran Anda tidak memiliki izin untuk fitur ini.',
            ], 403);
        }

        return $next($request);
    }
}

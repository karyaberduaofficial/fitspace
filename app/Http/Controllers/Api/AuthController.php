<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function register(Request $request): JsonResponse
    {
        // Public registration allows Member (18+), Orang Tua/Wali (<18 child representative), or Coach application.
        // Admin can NEVER be created via public registration!
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'role' => ['required', 'in:member,guardian,coach'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Optional child registration for guardian
            'child_name' => ['nullable', 'string', 'max:150'],
            'child_birth_date' => ['nullable', 'date', 'before:today'],
            // Optional Coach credentials for Admin approval
            'certifications' => ['nullable', 'string', 'max:255'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:50'],
            'specialization_slug' => ['nullable', 'string', 'max:60'],
            'bio' => ['nullable', 'string', 'max:500'],
        ]);

        $role = (string) $validated['role'];
        $birthDate = $validated['birth_date'] ?? null;

        // Strict Age Rule: Member MUST be >= 18 years old
        if ($role === 'member') {
            if (! $birthDate) {
                throw ValidationException::withMessages([
                    'birth_date' => ['Tanggal lahir wajib diisi untuk memverifikasi usia Member (minimal 18 tahun).'],
                ]);
            }
            $age = Carbon::parse($birthDate)->age;
            if ($age < 18) {
                throw ValidationException::withMessages([
                    'birth_date' => [
                        "Usia terdaftar {$age} tahun. Akun Member khusus untuk usia 18 tahun ke atas — untuk peserta di bawah 18 tahun, silakan gunakan Akun Orang Tua / Wali.",
                    ],
                ]);
            }
        }

        // Guardian adult & child (< 18) age sanity check
        if ($role === 'guardian') {
            if ($birthDate && Carbon::parse($birthDate)->age < 18) {
                throw ValidationException::withMessages([
                    'birth_date' => ['Usia akun Orang Tua / Wali minimal 18 tahun.'],
                ]);
            }
            if (! empty($validated['child_birth_date'])) {
                $childAge = Carbon::parse($validated['child_birth_date'])->age;
                if ($childAge >= 18) {
                    throw ValidationException::withMessages([
                        'child_birth_date' => [
                            "Usia anak {$childAge} tahun. Peserta usia 18 tahun ke atas wajib mendaftar menggunakan Akun Member mandiri.",
                        ],
                    ]);
                }
            }
        }

        $user = $this->createRegisteredUser(
            $validated['name'],
            strtolower($validated['email']),
            $validated['phone'],
            $role,
            $validated['password'],
            'local',
            null,
            null,
            false,
            $birthDate,
            'pending',
            $validated
        );

        $this->audit->log($user->id, 'auth.registered', 'user', $user->id, [
            'role' => $user->role,
            'approval_status' => $user->approval_status,
        ], $request);

        // Notify approvers cleanly
        if ($role === 'coach') {
            $adminIds = DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id');
            foreach ($adminIds as $aid) {
                $this->audit->notify(
                    (int) $aid,
                    'coach_approval_needed',
                    'Pengajuan Akun Coach Baru Menunggu Persetujuan',
                    "{$user->name} mengajukan pendaftaran akun Coach. Silakan tinjau di menu Persetujuan Coach.",
                    ['applicant_user_id' => $user->id]
                );
            }

            return response()->json([
                'message' => 'Pengajuan akun Coach berhasil dikirim. Akun Coach memerlukan persetujuan Admin sebelum dapat digunakan untuk login.',
                'requires_approval' => true,
                'approver' => 'Admin FitSpace',
                'token' => null,
                'user' => $this->serializeUser($user),
            ], 201);
        }

        // Member (18+) & Guardian start pending Admin approval; issue token so they can monitor verification status
        $adminIds = DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id');
        foreach ($adminIds as $aid) {
            $roleName = $role === 'guardian' ? 'Orang Tua / Wali' : 'Member (18+)';
            $this->audit->notify(
                (int) $aid,
                'account_approval_needed',
                "Pendaftaran {$roleName} Menunggu Persetujuan Admin",
                "{$user->name} ({$roleName}) menunggu persetujuan akun di Portal Admin FitSpace.",
                ['applicant_user_id' => $user->id]
            );
        }

        $token = $user->createToken('fitspace-app')->plainTextToken;

        return response()->json([
            'message' => 'Registrasi berhasil! Akun Anda sedang menunggu persetujuan dari Admin FitSpace sebelum pemilihan kelas, membership, dan laporan penuh diaktifkan.',
            'requires_approval' => true,
            'approver' => 'Admin FitSpace',
            'token' => $token,
            'user' => $this->serializeUser($user),
        ], 201);
    }

    /**
     * Public login for Member (18+), Orang Tua/Wali, and Approved Coach.
     * Admin accounts are STRICTLY blocked here and must authenticate via /auth/admin-login.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();
        if (! $user || ! $user->is_active || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Email atau kata sandi tidak valid.'], 401);
        }

        if ($user->role === 'admin') {
            return response()->json([
                'code' => 'ADMIN_PORTAL_REQUIRED',
                'message' => 'Akun Administrator hanya dapat login melalui Portal Admin Khusus.',
            ], 403);
        }

        if ($user->role === 'coach' && ! $user->isApproved()) {
            $status = (string) ($user->approval_status ?? 'pending');
            $msg = $status === 'rejected'
                ? 'Pengajuan akun Coach Anda ditolak oleh Admin. '.($user->approval_notes ? "Catatan: {$user->approval_notes}" : '')
                : 'Akun Coach Anda masih menunggu persetujuan dari Admin FitSpace.';

            return response()->json([
                'code' => $status === 'rejected' ? 'APPROVAL_REJECTED' : 'APPROVAL_PENDING',
                'message' => trim($msg),
            ], 403);
        }

        $token = $user->createToken('fitspace-app')->plainTextToken;
        $this->audit->log($user->id, 'auth.login', 'user', $user->id, [
            'role' => $user->role,
            'approval_status' => $user->approval_status,
        ], $request);

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * Dedicated Admin-Only Login Portal endpoint.
     * Rejects non-admin roles strictly so only authorized Administrators can sign in here.
     */
    public function adminLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();
        if (! $user || ! $user->is_active || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Kredensial Administrator tidak valid.'], 401);
        }

        if ($user->role !== 'admin') {
            $this->audit->log($user->id, 'auth.admin_login_denied', 'user', $user->id, ['role' => $user->role], $request);

            return response()->json([
                'code' => 'ADMIN_ONLY_PORTAL',
                'message' => 'Akses ditolak: Portal ini hanya dapat diloginkan oleh akun Admin FitSpace.',
            ], 403);
        }

        $token = $user->createToken('fitspace-admin')->plainTextToken;
        $this->audit->log($user->id, 'auth.admin_login', 'user', $user->id, ['role' => 'admin'], $request);

        return response()->json([
            'message' => 'Login Portal Admin FitSpace berhasil.',
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * Instant Limited Guest Account so visitors can preview FitSpace before signing up
     * as Member (18+) or Orang Tua / Wali.
     */
    public function guestLogin(Request $request): JsonResponse
    {
        $suffix = strtolower(Str::random(6));
        $guestEmail = "tamu-{$suffix}@guest.fitspace.local";

        $user = $this->createRegisteredUser(
            'Tamu FitSpace',
            $guestEmail,
            null,
            'guest',
            Str::random(32),
            'guest',
            null,
            null,
            true,
            null,
            'approved'
        );

        $token = $user->createToken('fitspace-guest')->plainTextToken;
        $this->audit->log($user->id, 'auth.guest_login', 'user', $user->id, ['is_guest' => true], $request);

        return response()->json([
            'message' => 'Masuk sebagai Akun Tamu FitSpace (akses terbatas). Untuk booking kelas, pilih membership, absensi QR, dan lihat laporan, silakan login ke Akun Member (18+) atau Orang Tua / Wali.',
            'token' => $token,
            'user' => $this->serializeUser($user),
            'access_scope' => [
                'allowed' => ['Eksplorasi katalog kelas', 'Melihat jadwal live studio', 'Melihat 6 paket membership', 'Melihat informasi cabang & coach'],
                'locked' => ['Booking kelas & penyesuaian jadwal', 'Pembelian membership', 'Tiket QR & absensi studio', 'Laporan perkembangan & ekspor PDF/Excel/Word', 'Pengelolaan profil anak'],
            ],
        ], 201);
    }

    /**
     * Read-only Guest Preview endpoint.
     */
    public function guestPreview(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
            'stats' => [
                'classes_count' => DB::table('classes')->where('is_active', true)->count(),
                'plans_count' => DB::table('membership_plans')->where('is_active', true)->count(),
                'branches_count' => DB::table('branches')->where('is_active', true)->count(),
                'upcoming_schedules' => DB::table('class_schedules')->where('status', 'scheduled')->where('starts_at', '>', now())->count(),
            ],
            'allowed_features' => [
                'Melihat seluruh katalog kelas & detail kurikulum',
                'Memantau jadwal kelas live & ketersediaan slot studio',
                'Membandingkan 6 pilihan paket membership & kuota sesi',
                'Menggunakan mode pratinjau Kacamata Pintar (Optical HUD)',
            ],
            'locked_features' => [
                'Booking kelas & penyesuaian jadwal (Reschedule H-8 jam)',
                'Pemilihan & aktivasi paket membership',
                'Tiket QR sekali pakai & pencatatan absensi kehadiran',
                'Melihat nilai evaluasi Coach & unduh laporan (PDF / Excel / Word)',
                'Mendaftarkan & mengelola profil anak (khusus Akun Orang Tua / Wali)',
            ],
        ]);
    }

    public function googleRedirect(Request $request): RedirectResponse
    {
        $clientId = (string) config('fitspace.google.client_id', '');
        $clientSecret = (string) config('fitspace.google.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            return redirect('/?google_auth=unconfigured');
        }

        $redirectUri = (string) config('fitspace.google.redirect_uri', '')
            ?: url('/api/v1/auth/google/callback');

        $state = Str::random(40);
        Cache::put('fitspace_google_state:'.$state, [
            'ip' => $request->ip(),
            'created_at' => now()->timestamp,
        ], now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || $code === '' || ! Cache::pull('fitspace_google_state:'.$state)) {
            return redirect('/?google_auth=invalid_state');
        }

        $clientId = (string) config('fitspace.google.client_id', '');
        $clientSecret = (string) config('fitspace.google.client_secret', '');
        $redirectUri = (string) config('fitspace.google.redirect_uri', '')
            ?: url('/api/v1/auth/google/callback');

        $tokenRes = Http::asForm()->timeout(12)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $tokenRes->ok() || empty($tokenRes->json('access_token'))) {
            return redirect('/?google_auth=failed');
        }

        $profileRes = Http::withToken((string) $tokenRes->json('access_token'))
            ->timeout(12)
            ->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if (! $profileRes->ok()) {
            return redirect('/?google_auth=failed');
        }

        $profile = $profileRes->json();
        $email = strtolower((string) ($profile['email'] ?? ''));
        $googleId = (string) ($profile['sub'] ?? '');
        $emailVerified = filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($email === '' || $googleId === '' || ! $emailVerified) {
            return redirect('/?google_auth=unverified_email');
        }

        $user = $this->findOrCreateGoogleUser(
            $googleId,
            $email,
            (string) ($profile['name'] ?? explode('@', $email)[0]),
            isset($profile['picture']) ? (string) $profile['picture'] : null
        );

        $exchangeCode = Str::random(48);
        Cache::put('fitspace_oauth_exchange:'.$exchangeCode, $user->id, now()->addSeconds(90));
        $this->audit->log($user->id, 'auth.google_login', 'user', $user->id, ['provider' => 'google'], $request);

        return redirect('/?oauth_code='.urlencode($exchangeCode));
    }

    public function oauthExchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:120'],
        ]);

        $userId = Cache::pull('fitspace_oauth_exchange:'.$validated['code']);
        if (! $userId) {
            return response()->json(['message' => 'Kode autentikasi Google tidak valid atau telah kedaluwarsa.'], 401);
        }

        $user = User::where('id', $userId)->where('is_active', true)->first();
        if (! $user) {
            return response()->json(['message' => 'Akun tidak ditemukan atau nonaktif.'], 404);
        }

        $token = $user->createToken('fitspace-google')->plainTextToken;

        return response()->json([
            'message' => 'Masuk dengan akun Google berhasil.',
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function googleIdToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string', 'max:4096'],
        ]);

        $clientId = (string) config('fitspace.google.client_id', '');
        if ($clientId === '') {
            return response()->json(['message' => 'Google OAuth belum dikonfigurasi pada server.'], 503);
        }

        $res = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $validated['id_token'],
        ]);

        if (! $res->ok()) {
            return response()->json(['message' => 'Token Google tidak valid.'], 401);
        }

        $info = $res->json();
        if (
            ($info['aud'] ?? '') !== $clientId
            || ! filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || empty($info['email'])
            || empty($info['sub'])
        ) {
            return response()->json(['message' => 'Verifikasi akun Google gagal.'], 401);
        }

        $user = $this->findOrCreateGoogleUser(
            (string) $info['sub'],
            strtolower((string) $info['email']),
            (string) ($info['name'] ?? explode('@', (string) $info['email'])[0]),
            isset($info['picture']) ? (string) $info['picture'] : null
        );

        $token = $user->createToken('fitspace-google')->plainTextToken;
        $this->audit->log($user->id, 'auth.google_id_token', 'user', $user->id, ['provider' => 'google'], $request);

        return response()->json([
            'message' => 'Masuk dengan akun Google berhasil.',
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function demoLogin(Request $request): JsonResponse
    {
        if (! config('fitspace.demo_mode', false)) {
            return response()->json(['message' => 'Mode demo tidak diaktifkan pada lingkungan ini.'], 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'in:admin,coach,member,guardian'],
        ]);

        $emailMap = [
            'admin' => 'admin@fitspace.local',
            'coach' => 'coach@fitspace.local',
            'member' => 'member@fitspace.local',
            'guardian' => 'wali@fitspace.local',
        ];

        $user = User::where('email', $emailMap[$validated['role']])->first();
        if (! $user) {
            return response()->json(['message' => 'Akun demo belum tersedia.'], 404);
        }

        $token = $user->createToken('fitspace-demo')->plainTextToken;
        $this->audit->log($user->id, 'auth.demo_login', 'user', $user->id, ['role' => $user->role], $request);

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isGuestAccount()) {
            return response()->json([
                'code' => 'GUEST_UPGRADE_REQUIRED',
                'message' => 'Akun Tamu tidak dapat mengubah profil. Silakan daftar Akun Member (18+) atau Orang Tua / Wali.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
        ]);

        if ($user->role === 'member' && ! empty($validated['birth_date'])) {
            if (Carbon::parse($validated['birth_date'])->age < 18) {
                throw ValidationException::withMessages([
                    'birth_date' => ['Usia akun Member minimal 18 tahun ke atas.'],
                ]);
            }
        }

        $user->fill($validated)->save();
        $this->audit->log($user->id, 'auth.profile_updated', 'user', $user->id, [], $request);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->currentAccessToken()?->delete();
        $this->audit->log($user?->id, 'auth.logout', 'user', $user?->id, [], $request);

        return response()->json(['message' => 'Berhasil keluar dari sesi.']);
    }

    public function notifications(Request $request): JsonResponse
    {
        $items = DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json(['notifications' => $items]);
    }

    public function subscribePush(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'public_key' => ['required', 'string', 'max:255'],
            'auth_token' => ['required', 'string', 'max:255'],
        ]);

        DB::table('push_subscriptions')->updateOrInsert(
            ['user_id' => $request->user()->id, 'endpoint' => $validated['endpoint']],
            [
                'public_key' => $validated['public_key'],
                'auth_token' => $validated['auth_token'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['message' => 'Langganan Web Push tersimpan.'], 201);
    }

    private function findOrCreateGoogleUser(string $googleId, string $email, string $name, ?string $avatarUrl): User
    {
        $existing = User::where('google_id', $googleId)->orWhere('email', $email)->first();
        if ($existing) {
            $existing->forceFill([
                'google_id' => $googleId,
                'auth_provider' => 'google',
                'avatar_url' => $avatarUrl ?: $existing->avatar_url,
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ])->save();

            return $existing;
        }

        return $this->createRegisteredUser(
            $name,
            $email,
            null,
            'member',
            Str::random(32),
            'google',
            $googleId,
            $avatarUrl,
            false,
            null,
            'pending'
        );
    }

    private function createRegisteredUser(
        string $name,
        string $email,
        ?string $phone,
        string $role,
        string $rawPassword,
        string $provider,
        ?string $googleId,
        ?string $avatarUrl,
        bool $isGuest,
        ?string $birthDate = null,
        string $approvalStatus = 'pending',
        array $extra = []
    ): User {
        $now = now();
        $defaultBranchId = DB::table('branches')->where('is_active', true)->value('id');

        return DB::transaction(function () use (
            $name, $email, $phone, $role, $rawPassword, $provider, $googleId, $avatarUrl,
            $isGuest, $birthDate, $approvalStatus, $extra, $defaultBranchId, $now
        ) {
            $u = User::create([
                'name' => $name,
                'email' => $email,
                'google_id' => $googleId,
                'auth_provider' => $provider,
                'avatar_url' => $avatarUrl,
                'phone' => $phone,
                'birth_date' => $birthDate,
                'password' => Hash::make($rawPassword),
                'role' => $role,
                'branch_id' => $defaultBranchId,
                'is_active' => true,
                'is_guest' => $isGuest,
                'approval_status' => $approvalStatus,
                'email_verified_at' => $provider === 'google' ? $now : null,
            ]);

            $roleId = DB::table('roles')->where('slug', $role)->value('id');
            if ($roleId) {
                DB::table('user_roles')->insert([
                    'user_id' => $u->id,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($role === 'member') {
                DB::table('members')->insert([
                    'user_id' => $u->id,
                    'branch_id' => $defaultBranchId,
                    'member_code' => 'MBR-'.date('Y').'-'.strtoupper(Str::random(5)),
                    'birth_date' => $birthDate,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ($role === 'guardian') {
                $guardianId = DB::table('guardians')->insertGetId([
                    'user_id' => $u->id,
                    'branch_id' => $defaultBranchId,
                    'relationship' => 'Orang Tua',
                    'emergency_phone' => $phone,
                    'pdp_consent_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if (! empty($extra['child_name']) && ! empty($extra['child_birth_date'])) {
                    DB::table('children')->insert([
                        'guardian_id' => $guardianId,
                        'full_name' => $extra['child_name'],
                        'birth_date' => $extra['child_birth_date'],
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            } elseif ($role === 'coach') {
                $coachId = DB::table('coaches')->insertGetId([
                    'user_id' => $u->id,
                    'bio' => $extra['bio'] ?? 'Calon Coach tersertifikasi FitSpace.',
                    'experience_years' => (int) ($extra['experience_years'] ?? 1),
                    'certifications' => $extra['certifications'] ?? 'Menunggu Verifikasi Sertifikasi',
                    'is_active' => false, // Activated ONLY upon Admin approval!
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if (! empty($extra['specialization_slug'])) {
                    $specId = DB::table('specializations')->where('slug', $extra['specialization_slug'])->value('id');
                    if ($specId) {
                        DB::table('coach_specializations')->insert([
                            'coach_id' => $coachId,
                            'specialization_id' => $specId,
                        ]);
                    }
                }
                if ($defaultBranchId) {
                    DB::table('branch_coaches')->insert([
                        'branch_id' => $defaultBranchId,
                        'coach_id' => $coachId,
                    ]);
                }
            }

            DB::table('notification_preferences')->insert([
                'user_id' => $u->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $u;
        });
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'birth_date' => $user->birth_date ? $user->birth_date->format('Y-m-d') : null,
            'age' => $user->age(),
            'role' => $user->role,
            'branch_id' => $user->branch_id,
            'auth_provider' => $user->auth_provider ?? 'local',
            'avatar_url' => $user->avatar_url,
            'is_guest' => $user->isGuestAccount(),
            'approval_status' => $user->approval_status ?? 'approved',
            'approval_notes' => $user->approval_notes,
            'approved_at' => $user->approved_at?->toIso8601String(),
            'can_transact' => ! $user->isGuestAccount() && $user->isApproved(),
        ];
    }
}

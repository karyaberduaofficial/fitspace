<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\XlsxExporter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FitSpacePlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fitspace.qr_signing_secret', 'ephemeral-qr-'.\Illuminate\Support\Str::random(24));
        config()->set('fitspace.payment.midtrans_server_key', 'ephemeral-mid-'.\Illuminate\Support\Str::random(24));
        config()->set('fitspace.payment.xendit_webhook_token', 'ephemeral-xnd-'.\Illuminate\Support\Str::random(24));
        $this->seed(DatabaseSeeder::class);
    }

    public function test_health_endpoints_and_strict_security_headers(): void
    {
        $home = $this->get('/');
        $home->assertStatus(200)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(), interest-cohort=()');
        $this->assertStringContainsString("script-src 'self'", (string) $home->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('max-age=63072000', (string) $home->headers->get('Strict-Transport-Security'));

        $healthz = $this->get('/healthz');
        $healthz->assertStatus(200)
            ->assertJson(['status' => 'ok', 'app' => 'FitSpace PWA', 'tier' => 'corporate'])
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $healthz->headers->get('Cache-Control'));

        $apiHealth = $this->getJson('/api/health');
        $apiHealth->assertStatus(200)->assertJson(['status' => 'ok']);
    }

    public function test_registration_login_rbac_and_disabled_demo_mode_in_production(): void
    {
        // Demo login MUST be blocked when FITSPACE_DEMO_MODE=false (testing/production default)
        $this->postJson('/api/v1/auth/demo-login', ['role' => 'admin'])->assertStatus(403);

        // Public registration MUST reject privilege escalation to admin
        $ephemeralPass = 'Tst#'.\Illuminate\Support\Str::random(14);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Attacker',
            'email' => 'attacker@example.test',
            'phone' => '0812000000',
            'role' => 'admin',
            'birth_date' => '1995-01-01',
            'password' => $ephemeralPass,
            'password_confirmation' => $ephemeralPass,
        ])->assertStatus(422);

        // Member registration MUST reject applicants under 18 years old
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Adik Remaja',
            'email' => 'remaja15@example.test',
            'phone' => '081299990000',
            'role' => 'member',
            'birth_date' => now()->subYears(15)->format('Y-m-d'),
            'password' => $ephemeralPass,
            'password_confirmation' => $ephemeralPass,
        ])->assertStatus(422)->assertJsonValidationErrors(['birth_date']);

        // Legitimate Member (18+) registration succeeds with pending Coach approval
        $reg = $this->postJson('/api/v1/auth/register', [
            'name' => 'Rina Kartika',
            'email' => 'rina@example.test',
            'phone' => '081299998888',
            'role' => 'member',
            'birth_date' => '1998-05-10',
            'password' => $ephemeralPass,
            'password_confirmation' => $ephemeralPass,
        ]);
        $reg->assertStatus(201)
            ->assertJsonPath('user.role', 'member')
            ->assertJsonPath('user.approval_status', 'pending');

        // Member & Coach MUST receive 403 on Admin routes
        $member = User::where('email', 'member@fitspace.local')->firstOrFail();
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);

        $coach = User::where('email', 'coach@fitspace.local')->firstOrFail();
        Sanctum::actingAs($coach);
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);
        $this->postJson('/api/v1/admin/schedules', [])->assertStatus(403);
    }

    public function test_dynamic_class_management_without_schema_changes(): void
    {
        $admin = User::where('email', 'admin@fitspace.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $catRes = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Muay Thai',
            'description' => 'Seni bela diri tinju Thailand untuk conditioning',
            'color_token' => 'boxing',
        ]);
        $catRes->assertStatus(201);
        $categoryId = (int) $catRes->json('category.id');
        $levelId = (int) DB::table('class_levels')->value('id');

        $clsRes = $this->postJson('/api/v1/admin/classes', [
            'name' => 'Muay Thai Pad Work',
            'category_id' => $categoryId,
            'level_id' => $levelId,
            'price_per_session' => 165000,
            'max_capacity' => 12,
            'duration_minutes' => 60,
            'min_age' => 16,
            'max_age' => 55,
            'indicators' => ['Teknik Tendangan', 'Clinch Control', 'Stamina Ronde'],
        ]);
        $clsRes->assertStatus(201);
        $classId = (int) $clsRes->json('class.id');

        $this->assertEquals(3, DB::table('class_evaluation_indicators')->where('class_id', $classId)->count());

        $boot = $this->getJson('/api/v1/public/bootstrap');
        $boot->assertStatus(200);
        $names = collect($boot->json('classes'))->pluck('name')->all();
        $this->assertContains('Muay Thai Pad Work', $names);
    }

    public function test_schedule_anti_conflict_coach_room_and_specialization(): void
    {
        $admin = User::where('email', 'admin@fitspace.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $pilatesClassId = (int) DB::table('classes')->where('slug', 'reformer-pilates')->value('id');
        $boxingClassId = (int) DB::table('classes')->where('slug', 'boxing-intermediate')->value('id');
        $coachNadiaId = (int) DB::table('coaches as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('u.email', 'coach@fitspace.local')->value('c.id');
        $coachDimasId = (int) DB::table('coaches as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('u.email', 'coach.dimas@fitspace.local')->value('c.id');
        $roomStudioA = (int) DB::table('rooms')->where('facility_type', 'pilates_studio')->value('id');
        $roomHall2 = (int) DB::table('rooms')->where('facility_type', 'cardio_hall')->value('id');

        $slotStart = now()->addDays(5)->setTime(10, 0)->format('Y-m-d H:i:s');

        // 1. Specialization mismatch: Coach Dimas (Boxing/Zumba) cannot teach Pilates
        $this->postJson('/api/v1/admin/schedules', [
            'class_id' => $pilatesClassId,
            'coach_id' => $coachDimasId,
            'room_id' => $roomStudioA,
            'starts_at' => $slotStart,
            'capacity' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['coach_id']);

        // 2. Exceeding room max_capacity rejected
        $this->postJson('/api/v1/admin/schedules', [
            'class_id' => $pilatesClassId,
            'coach_id' => $coachNadiaId,
            'room_id' => $roomStudioA,
            'starts_at' => $slotStart,
            'capacity' => 99,
        ])->assertStatus(422)->assertJsonValidationErrors(['capacity']);

        // 3. Valid schedule created
        $this->postJson('/api/v1/admin/schedules', [
            'class_id' => $pilatesClassId,
            'coach_id' => $coachNadiaId,
            'room_id' => $roomStudioA,
            'starts_at' => $slotStart,
            'capacity' => 10,
        ])->assertStatus(201);

        // 4. Coach conflict (same coach Nadia in different room overlapping time)
        $this->postJson('/api/v1/admin/schedules', [
            'class_id' => $pilatesClassId,
            'coach_id' => $coachNadiaId,
            'room_id' => $roomHall2,
            'starts_at' => now()->addDays(5)->setTime(10, 30)->format('Y-m-d H:i:s'),
            'capacity' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['coach_id']);

        // 5. Room conflict (different qualified coach Dimas in same Studio A overlapping time)
        $this->postJson('/api/v1/admin/schedules', [
            'class_id' => $boxingClassId,
            'coach_id' => $coachDimasId,
            'room_id' => $roomStudioA,
            'starts_at' => now()->addDays(5)->setTime(10, 15)->format('Y-m-d H:i:s'),
            'capacity' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['room_id']);
    }

    public function test_booking_capacity_membership_quota_and_cancellation(): void
    {
        $member = User::where('email', 'member@fitspace.local')->firstOrFail();
        Sanctum::actingAs($member);

        $boxingSchedule = DB::table('class_schedules as s')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->where('c.slug', 'boxing-intermediate')
            ->select('s.*')
            ->first();

        $beforeQuota = (int) DB::table('memberships')->where('user_id', $member->id)->value('remaining_quota');

        $res = $this->postJson('/api/v1/bookings', [
            'schedule_id' => $boxingSchedule->id,
            'booking_type' => 'membership',
        ]);
        $res->assertStatus(201)->assertJsonPath('booking.status', 'confirmed');
        $this->assertNotEmpty($res->json('qr.display_code'));

        $afterQuota = (int) DB::table('memberships')->where('user_id', $member->id)->value('remaining_quota');
        $this->assertSame($beforeQuota - 1, $afterQuota);

        // Fill capacity to max and verify overbooking is rejected
        DB::table('class_schedules')->where('id', $boxingSchedule->id)->update(['booked_count' => $boxingSchedule->capacity]);
        $otherMember = User::where('email', 'wali@fitspace.local')->firstOrFail();
        Sanctum::actingAs($otherMember);
        $this->postJson('/api/v1/bookings', [
            'schedule_id' => $boxingSchedule->id,
            'booking_type' => 'per_session',
        ])->assertStatus(422)->assertJsonValidationErrors(['schedule_id']);

        // Cancel original membership booking restores quota
        Sanctum::actingAs($member);
        $bookingId = (int) $res->json('booking.id');
        $this->postJson("/api/v1/bookings/{$bookingId}/cancel", ['reason' => 'Uji pembatalan'])
            ->assertStatus(200)
            ->assertJsonPath('booking.status', 'cancelled');

        $restoredQuota = (int) DB::table('memberships')->where('user_id', $member->id)->value('remaining_quota');
        $this->assertSame($beforeQuota, $restoredQuota);
    }

    public function test_reschedule_enforces_hard_h8_hours_cutoff_and_records_history(): void
    {
        $member = User::where('email', 'member@fitspace.local')->firstOrFail();
        Sanctum::actingAs($member);

        // Near schedule (~4 hours away) MUST be hard-rejected with 422
        $nearBooking = DB::table('bookings')->where('booking_code', 'FS-BK-2026-0002')->first();
        $altSchedule = DB::table('class_schedules')
            ->where('starts_at', '>', now()->addHours(40))
            ->first();

        $this->postJson("/api/v1/bookings/{$nearBooking->id}/reschedule", [
            'new_schedule_id' => $altSchedule->id,
            'reason' => 'Terlambat mengajukan reschedule',
        ])->assertStatus(422)->assertJsonValidationErrors(['booking']);

        // Far schedule (~26 hours away) MUST succeed and record reschedule_histories
        $farBooking = DB::table('bookings')->where('booking_code', 'FS-BK-2026-0001')->first();
        $pilatesAlt = DB::table('class_schedules as s')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->where('c.slug', 'reformer-pilates')
            ->where('s.id', '!=', $farBooking->schedule_id)
            ->value('s.id');

        $this->postJson("/api/v1/bookings/{$farBooking->id}/reschedule", [
            'new_schedule_id' => $pilatesAlt,
            'reason' => 'Penyesuaian jam kantor',
        ])->assertStatus(200)->assertJsonPath('booking.reschedule_count', 1);

        $this->assertDatabaseHas('reschedule_histories', [
            'booking_id' => $farBooking->id,
            'from_schedule_id' => $farBooking->schedule_id,
            'to_schedule_id' => $pilatesAlt,
        ]);
    }

    public function test_payment_webhooks_signature_verification_redaction_and_finance_rule_14(): void
    {
        $member = User::where('email', 'member@fitspace.local')->firstOrFail();
        Sanctum::actingAs($member);

        $zumbaScheduleId = (int) DB::table('class_schedules as s')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->where('c.slug', 'zumba-regular')
            ->value('s.id');

        $bkRes = $this->postJson('/api/v1/bookings', [
            'schedule_id' => $zumbaScheduleId,
            'booking_type' => 'per_session',
            'gateway' => 'midtrans',
            'coupon_code' => 'FITSPACE15',
        ])->assertStatus(201);

        $orderId = (string) $bkRes->json('payment.order_id');
        $grossAmount = number_format((float) $bkRes->json('payment.amount'), 2, '.', '');
        $bookingId = (int) $bkRes->json('booking.id');
        $paymentId = (int) $bkRes->json('payment.id');

        // Confirm pending payment is NOT counted in net income (Business Rule #14)
        $admin = User::where('email', 'admin@fitspace.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $beforeIncome = (float) $this->getJson('/api/v1/admin/finance')->json('summary.total_income');

        // 1. Invalid Midtrans signature MUST return 403 and redact signature_key in logs
        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $grossAmount,
            'transaction_status' => 'settlement',
            'signature_key' => 'forged-invalid-signature',
        ])->assertStatus(403);

        $badLog = DB::table('payment_gateway_logs')->where('gateway', 'midtrans')->latest('id')->first();
        $this->assertFalse((bool) $badLog->signature_valid);
        $this->assertStringContainsString('[REDACTED]', (string) $badLog->sanitized_payload);
        $this->assertStringNotContainsString('forged-invalid-signature', (string) $badLog->sanitized_payload);

        // 2. Valid Midtrans SHA-512 signature confirms booking, issues QR & records income
        $validSig = hash('sha512', $orderId.'200'.$grossAmount.config('fitspace.payment.midtrans_server_key'));
        $this->postJson('/api/v1/webhooks/midtrans', [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $grossAmount,
            'transaction_status' => 'settlement',
            'transaction_id' => 'MID-TX-991',
            'signature_key' => $validSig,
        ])->assertStatus(200)->assertJson(['payment_status' => 'paid', 'qr_issued' => true]);

        $this->assertSame('confirmed', DB::table('bookings')->where('id', $bookingId)->value('status'));
        $afterIncome = (float) $this->getJson('/api/v1/admin/finance')->json('summary.total_income');
        $this->assertEqualsWithDelta($beforeIncome + (float) $grossAmount, $afterIncome, 0.01);

        // 3. Refund deducts net income cleanly
        $netBeforeRefund = (float) $this->getJson('/api/v1/admin/finance')->json('summary.net_income');
        $this->postJson("/api/v1/admin/payments/{$paymentId}/refund", [
            'reason' => 'Pembatalan terverifikasi Admin',
        ])->assertStatus(200);
        $netAfterRefund = (float) $this->getJson('/api/v1/admin/finance')->json('summary.net_income');
        $this->assertEqualsWithDelta($netBeforeRefund - (float) $grossAmount, $netAfterRefund, 0.01);

        // 4. Xendit callback token verification (invalid 403, valid 200)
        $pendingOrder = DB::table('payments')->where('status', 'pending')->first();
        $this->withHeaders(['X-CALLBACK-TOKEN' => 'wrong-token'])
            ->postJson('/api/v1/webhooks/xendit', ['external_id' => $pendingOrder->order_id, 'status' => 'PAID'])
            ->assertStatus(403);

        $this->withHeaders(['X-CALLBACK-TOKEN' => config('fitspace.payment.xendit_webhook_token')])
            ->postJson('/api/v1/webhooks/xendit', ['external_id' => $pendingOrder->order_id, 'status' => 'PAID', 'id' => 'XND-1'])
            ->assertStatus(200)
            ->assertJson(['payment_status' => 'paid']);
    }

    public function test_single_use_qr_attendance_prevents_replay_and_wrong_coach(): void
    {
        // FS-QR-8821 belongs to Reformer Pilates taught by Coach Nadia
        $coachDimas = User::where('email', 'coach.dimas@fitspace.local')->firstOrFail();
        Sanctum::actingAs($coachDimas);
        $this->postJson('/api/v1/attendance/scan-qr', ['qr_token' => 'FS-QR-8821'])
            ->assertStatus(403);

        $coachNadia = User::where('email', 'coach@fitspace.local')->firstOrFail();
        Sanctum::actingAs($coachNadia);
        $this->postJson('/api/v1/attendance/scan-qr', ['qr_token' => 'FS-QR-8821', 'status' => 'present'])
            ->assertStatus(200);

        // Second scan of identical QR MUST return 409 Conflict (replay protection)
        $this->postJson('/api/v1/attendance/scan-qr', ['qr_token' => 'FS-QR-8821', 'status' => 'present'])
            ->assertStatus(409);
    }

    public function test_coach_evaluation_boundaries_and_availability_request(): void
    {
        $booking = DB::table('bookings')->where('booking_code', 'FS-BK-2026-0001')->first();

        // Unassigned coach Dimas rejected (403)
        $coachDimas = User::where('email', 'coach.dimas@fitspace.local')->firstOrFail();
        Sanctum::actingAs($coachDimas);
        $this->postJson('/api/v1/coach/evaluations', [
            'booking_id' => $booking->id,
            'material_studied' => 'Core Stability',
            'score_1' => 4,
            'score_2' => 3,
            'progress_notes' => 'Catatan tidak sah',
            'next_recommendation' => 'Lanjut',
        ])->assertStatus(403);

        // Assigned coach Nadia succeeds
        $coachNadia = User::where('email', 'coach@fitspace.local')->firstOrFail();
        Sanctum::actingAs($coachNadia);
        $this->postJson('/api/v1/coach/evaluations', [
            'booking_id' => $booking->id,
            'material_studied' => 'Breathing & Alignment',
            'score_1' => 4,
            'score_2' => 3,
            'progress_notes' => 'Postur stabil dan kontrol napas sangat baik.',
            'next_recommendation' => 'Progresi pegas Reformer level menengah.',
        ])->assertStatus(201)->assertJsonPath('average_score', 3.5);

        // Availability request stays pending and does not alter official schedules
        $branchId = (int) DB::table('branches')->value('id');
        $this->postJson('/api/v1/coach/availabilities', [
            'branch_id' => $branchId,
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '15:00',
            'request_notes' => 'Usulan jam mengajar baru',
        ])->assertStatus(201)->assertJson(['status' => 'pending']);
    }

    public function test_guardian_child_privacy_anti_idor_age_rule_and_pdf_download(): void
    {
        $guardianA = User::where('email', 'wali@fitspace.local')->firstOrFail();
        $guardianB = User::where('email', 'wali2@fitspace.local')->firstOrFail();
        $childAlya = DB::table('children')->where('full_name', 'Alya Putri')->first();

        // Guardian A can download own child's PDF report
        Sanctum::actingAs($guardianA);
        $pdfRes = $this->get("/api/v1/guardian/children/{$childAlya->id}/report-pdf");
        $pdfRes->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdfRes->getContent());

        // Guardian B MUST receive 403 Forbidden for ANY access to Guardian A's child (strict child privacy)
        Sanctum::actingAs($guardianB);
        $this->getJson("/api/v1/guardian/children/{$childAlya->id}")->assertStatus(403);
        $this->putJson("/api/v1/guardian/children/{$childAlya->id}", ['full_name' => 'Tampered'])->assertStatus(403);
        $this->get("/api/v1/guardian/children/{$childAlya->id}/report-pdf")->assertStatus(403);

        // Age range enforcement: Alya (8 thn) cannot book Reformer Pilates (16-65 thn)
        Sanctum::actingAs($guardianA);
        $pilatesSchedId = (int) DB::table('class_schedules as s')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->where('c.slug', 'reformer-pilates')
            ->value('s.id');

        $this->postJson('/api/v1/bookings', [
            'schedule_id' => $pilatesSchedId,
            'child_id' => $childAlya->id,
            'booking_type' => 'per_session',
        ])->assertStatus(422)->assertJsonValidationErrors(['child_id']);
    }

    public function test_reporting_center_pdf_excel_rbac_and_formula_injection_sanitization(): void
    {
        // Member denied admin finance reports
        $member = User::where('email', 'member@fitspace.local')->firstOrFail();
        Sanctum::actingAs($member);
        $this->get('/api/v1/reports/export?type=finance_monthly&format=excel')->assertStatus(403);

        // Admin downloads valid PDF and valid OpenXML .xlsx
        $admin = User::where('email', 'admin@fitspace.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $pdf = $this->get('/api/v1/reports/export?type=finance_monthly&format=pdf');
        $pdf->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $xlsx = $this->get('/api/v1/reports/export?type=bookings&format=excel');
        $xlsx->assertStatus(200)
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith("PK\x03\x04", $xlsx->getContent());

        $docx = $this->get('/api/v1/reports/export?type=finance_monthly&format=word');
        $docx->assertStatus(200)
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringStartsWith("PK\x03\x04", $docx->getContent());

        // Guardian Child Word (.docx) export + Anti-IDOR enforcement
        $guardianA = User::where('email', 'wali@fitspace.local')->firstOrFail();
        $guardianB = User::where('email', 'wali2@fitspace.local')->firstOrFail();
        $childAlya = DB::table('children')->where('full_name', 'Alya Putri')->first();

        Sanctum::actingAs($guardianA);
        $childDocx = $this->get("/api/v1/guardian/children/{$childAlya->id}/report-word");
        $childDocx->assertStatus(200)
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringStartsWith("PK\x03\x04", $childDocx->getContent());

        Sanctum::actingAs($guardianB);
        $this->get("/api/v1/guardian/children/{$childAlya->id}/report-word")->assertStatus(403);

        // OWASP Formula Injection defense verification
        $exporter = new XlsxExporter;
        $this->assertSame("'=CMD|' /C calc'!A0", $exporter->sanitizeCell("=CMD|' /C calc'!A0"));
        $this->assertSame("'+2+2", $exporter->sanitizeCell('+2+2'));
        $this->assertSame("'-1+1", $exporter->sanitizeCell('-1+1'));
        $this->assertSame("'@SUM(A1:A9)", $exporter->sanitizeCell('@SUM(A1:A9)'));
        $this->assertSame('FitSpace Studio Aman', $exporter->sanitizeCell('FitSpace Studio Aman'));
    }

    public function test_all_11_reports_have_rich_seeded_data_and_clean_excel_openxml_across_all_4_roles(): void
    {
        $extractZipEntry = static function (string $zipBinary, string $targetName): ?string {
            $offset = 0;
            $len = strlen($zipBinary);
            while ($offset + 30 <= $len) {
                $sig = unpack('V', substr($zipBinary, $offset, 4))[1];
                if ($sig !== 0x04034B50) {
                    break;
                }
                $hdr = unpack('vver/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($zipBinary, $offset + 4, 26));
                $name = substr($zipBinary, $offset + 30, $hdr['nlen']);
                $dataStart = $offset + 30 + $hdr['nlen'] + $hdr['elen'];
                $compressed = substr($zipBinary, $dataStart, $hdr['csize']);
                if ($name === $targetName) {
                    return $hdr['method'] === 8 ? gzinflate($compressed) : $compressed;
                }
                $offset = $dataStart + $hdr['csize'];
            }

            return null;
        };

        // 1. Admin: ALL 11 reports must export populated, clean .xlsx (no freeze-split duplication!) & .docx
        $admin = User::where('email', 'admin@fitspace.local')->firstOrFail();
        Sanctum::actingAs($admin);

        foreach (\App\Services\ReportExportService::VALID_TYPES as $reportType) {
            $xlsxRes = $this->get("/api/v1/reports/export?type={$reportType}&format=excel")->assertStatus(200);
            $sheetXml = $extractZipEntry($xlsxRes->getContent(), 'xl/worksheets/sheet1.xml');
            $this->assertNotNull($sheetXml, "sheet1.xml missing for {$reportType}");
            $this->assertStringNotContainsString('<pane ', $sheetXml, "Split-pane duplication tag must not exist in {$reportType}");
            $this->assertStringNotContainsString('Belum ada data tercatat', $sheetXml, "Report {$reportType} should have rich seeded rows");
            $this->assertStringContainsString('<dimension ref="A1:', $sheetXml);
            $this->assertStringContainsString('orientation="landscape"', $sheetXml);

            $docxRes = $this->get("/api/v1/reports/export?type={$reportType}&format=word")->assertStatus(200);
            $docXml = $extractZipEntry($docxRes->getContent(), 'word/document.xml');
            $this->assertNotNull($docXml, "word/document.xml missing for {$reportType}");
            $this->assertStringNotContainsString('Belum ada data tercatat', $docXml, "Word report {$reportType} should have rich seeded rows");
        }

        // Verify styles.xml defines Rupiah currency numFmt 164
        $finXlsx = $this->get('/api/v1/reports/export?type=finance_daily&format=excel')->assertStatus(200);
        $stylesXml = $extractZipEntry($finXlsx->getContent(), 'xl/styles.xml');
        $this->assertStringContainsString('numFmtId="164"', (string) $stylesXml);

        // 2. Coach, Member & Guardian each export populated Excel & PDF on their permitted reports
        $roleChecks = [
            'coach@fitspace.local' => ['coach_activity', 'schedules', 'attendance', 'progress', 'reviews'],
            'member@fitspace.local' => ['bookings', 'attendance', 'memberships', 'progress', 'reviews'],
            'wali@fitspace.local' => ['bookings', 'attendance', 'memberships', 'progress', 'reviews'],
        ];
        foreach ($roleChecks as $email => $types) {
            $u = User::where('email', $email)->firstOrFail();
            Sanctum::actingAs($u);
            foreach ($types as $t) {
                $r = $this->get("/api/v1/reports/export?type={$t}&format=excel")->assertStatus(200);
                $xml = (string) $extractZipEntry($r->getContent(), 'xl/worksheets/sheet1.xml');
                $this->assertStringNotContainsString('Belum ada data tercatat', $xml, "Role {$email} report {$t} must contain seeded data");
            }
        }
    }

    public function test_guest_login_and_google_oauth_security_guards(): void
    {
        // Guest login issues a valid Sanctum token with role=guest and limited preview access
        $guestRes = $this->postJson('/api/v1/auth/guest');
        $guestRes->assertStatus(201)
            ->assertJsonPath('user.is_guest', true)
            ->assertJsonPath('user.role', 'guest')
            ->assertJsonPath('user.auth_provider', 'guest')
            ->assertJsonPath('user.can_transact', false);
        $this->assertNotEmpty($guestRes->json('token'));

        // Guest CAN access public catalog & limited guest preview
        $guestToken = (string) $guestRes->json('token');
        $this->withToken($guestToken)->getJson('/api/v1/guest/preview')->assertStatus(200);

        // Guest MUST be blocked (403 GUEST_UPGRADE_REQUIRED) from full Member/Guardian/Coach/Admin features
        $this->withToken($guestToken)->getJson('/api/v1/admin/dashboard')->assertStatus(403)->assertJsonPath('code', 'GUEST_UPGRADE_REQUIRED');
        $this->withToken($guestToken)->getJson('/api/v1/coach/dashboard')->assertStatus(403)->assertJsonPath('code', 'GUEST_UPGRADE_REQUIRED');
        $this->withToken($guestToken)->getJson('/api/v1/member/dashboard')->assertStatus(403)->assertJsonPath('code', 'GUEST_UPGRADE_REQUIRED');
        $this->withToken($guestToken)->getJson('/api/v1/guardian/dashboard')->assertStatus(403)->assertJsonPath('code', 'GUEST_UPGRADE_REQUIRED');
        $this->withToken($guestToken)->postJson('/api/v1/bookings', [])->assertStatus(403)->assertJsonPath('code', 'GUEST_UPGRADE_REQUIRED');
        $this->withToken($guestToken)->postJson('/api/v1/memberships/purchase', [])->assertStatus(403)->assertJsonPath('code', 'GUEST_UPGRADE_REQUIRED');
        $this->withToken($guestToken)->get('/api/v1/reports/export?type=progress&format=pdf')->assertStatus(403);

        // Google redirect cleanly falls back to setup indicator when credentials are unset
        $this->get('/api/v1/auth/google/redirect')->assertRedirect('/?google_auth=unconfigured');

        // Forged OAuth exchange code MUST be rejected with 401
        $this->postJson('/api/v1/auth/oauth-exchange', ['code' => 'forged-code-123'])->assertStatus(401);
    }

    public function test_admin_exclusive_login_isolation_and_multi_tier_approval_workflow(): void
    {
        $secret = 'Tst#'.\Illuminate\Support\Str::random(16);
        $coachPass = 'Tst#'.\Illuminate\Support\Str::random(16);
        User::whereIn('email', ['admin@fitspace.local', 'member@fitspace.local', 'coach@fitspace.local'])
            ->update(['password' => \Illuminate\Support\Facades\Hash::make($secret)]);

        // 1. Admin CANNOT login through public user login endpoint
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@fitspace.local',
            'password' => $secret,
        ])->assertStatus(403)->assertJsonPath('code', 'ADMIN_PORTAL_REQUIRED');

        // 2. Member/Coach/Guardian CANNOT login through exclusive Admin portal
        $this->postJson('/api/v1/auth/admin-login', [
            'email' => 'member@fitspace.local',
            'password' => $secret,
        ])->assertStatus(403)->assertJsonPath('code', 'ADMIN_ONLY_PORTAL');

        $this->postJson('/api/v1/auth/admin-login', [
            'email' => 'coach@fitspace.local',
            'password' => $secret,
        ])->assertStatus(403)->assertJsonPath('code', 'ADMIN_ONLY_PORTAL');

        // 3. Admin authenticates cleanly through exclusive /auth/admin-login
        $adminLogin = $this->postJson('/api/v1/auth/admin-login', [
            'email' => 'admin@fitspace.local',
            'password' => $secret,
        ])->assertStatus(200)->assertJsonPath('user.role', 'admin');
        $adminToken = (string) $adminLogin->json('token');

        // 4. New Coach registers -> stays pending (cannot login until Admin approves)
        $coachReg = $this->postJson('/api/v1/auth/register', [
            'name' => 'Coach Baru Yoga',
            'email' => 'coach.new@example.test',
            'phone' => '081277770001',
            'role' => 'coach',
            'certifications' => 'RYT-500 Certified',
            'experience_years' => 5,
            'specialization_slug' => 'yoga',
            'password' => $coachPass,
            'password_confirmation' => $coachPass,
        ])->assertStatus(201)->assertJsonPath('requires_approval', true)->assertJsonPath('token', null);
        $newCoachId = (int) $coachReg->json('user.id');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'coach.new@example.test',
            'password' => $coachPass,
        ])->assertStatus(403)->assertJsonPath('code', 'APPROVAL_PENDING');

        // 5. Admin approves the Coach -> Coach can now log in!
        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/coaches/{$newCoachId}/decision", [
                'decision' => 'approved',
                'notes' => 'Sertifikasi RYT-500 terverifikasi.',
            ])->assertStatus(200)->assertJsonPath('user.approval_status', 'approved');

        $coachLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'coach.new@example.test',
            'password' => $coachPass,
        ])->assertStatus(200)->assertJsonPath('user.role', 'coach');
        $newCoachToken = (string) $coachLogin->json('token');

        // 6. Coach CANNOT approve accounts or bookings (centralized strictly under Admin FitSpace -> 403 ADMIN_APPROVAL_ONLY)
        $pendingSeededCoach = User::where('email', 'coach.calon@fitspace.local')->firstOrFail();
        $this->withToken($newCoachToken)
            ->postJson("/api/v1/coach/accounts/{$pendingSeededCoach->id}/decision", ['decision' => 'approved'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ADMIN_APPROVAL_ONLY');

        // 7. Pending Member (18+) cannot transact before Admin approval, then succeeds after Admin approves!
        $pendingMember = User::where('email', 'member.baru@fitspace.local')->firstOrFail();
        $scheduleId = (int) DB::table('class_schedules')->where('starts_at', '>', now()->addHours(20))->value('id');
        $pendingMemberToken = $pendingMember->createToken('test-pending')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($pendingMemberToken)->postJson('/api/v1/bookings', [
            'schedule_id' => $scheduleId,
            'booking_type' => 'per_session',
        ])->assertStatus(403)->assertJsonPath('code', 'APPROVAL_PENDING');

        // Coach cannot approve Member account (403)
        $this->app['auth']->forgetGuards();
        $this->withToken($newCoachToken)
            ->postJson("/api/v1/coach/accounts/{$pendingMember->id}/decision", ['decision' => 'approved'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ADMIN_APPROVAL_ONLY');

        // Admin approves Member account -> Member can now select classes & memberships!
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/users/{$pendingMember->id}/decision", [
                'decision' => 'approved',
                'notes' => 'Usia 18+ dan profil terverifikasi Admin.',
            ])->assertStatus(200)->assertJsonPath('user.approval_status', 'approved');

        $this->app['auth']->forgetGuards();
        $bkCreated = $this->withToken($pendingMemberToken)->postJson('/api/v1/bookings', [
            'schedule_id' => $scheduleId,
            'booking_type' => 'per_session',
        ])->assertStatus(201);
        $newBookingId = (int) $bkCreated->json('booking.id');

        // Coach cannot confirm Member booking (403)
        $this->app['auth']->forgetGuards();
        $this->withToken($newCoachToken)
            ->postJson("/api/v1/coach/bookings/{$newBookingId}/confirm")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ADMIN_APPROVAL_ONLY');

        // 7b. Admin approves Member booking -> immediately confirmed + QR issued & visible in Member dashboard!
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/bookings/{$newBookingId}/approve")
            ->assertStatus(200)
            ->assertJsonPath('booking.status', 'confirmed');

        // 7c. Member purchases membership -> Admin approves & activates it!
        $planId = (int) DB::table('membership_plans')->where('slug', 'paket-starter-5')->value('id');
        $this->app['auth']->forgetGuards();
        $memCreated = $this->withToken($pendingMemberToken)->postJson('/api/v1/memberships/purchase', [
            'plan_id' => $planId,
            'gateway' => 'midtrans',
        ])->assertStatus(201);
        $newMemId = (int) $memCreated->json('membership.id');

        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/memberships/{$newMemId}/approve")
            ->assertStatus(200)
            ->assertJsonPath('membership.status', 'active');

        $this->app['auth']->forgetGuards();
        $memberDash = $this->withToken($pendingMemberToken)->getJson('/api/v1/member/dashboard')->assertStatus(200);
        $this->assertSame('approved', $memberDash->json('approval_status'));
        $confirmedIds = collect($memberDash->json('bookings'))->where('status', 'confirmed')->pluck('id')->all();
        $this->assertContains($newBookingId, $confirmedIds);
        $this->assertSame('active', $memberDash->json('active_membership.status'));

        // 8. Guardian child age MUST be < 18
        $guardian = User::where('email', 'wali@fitspace.local')->firstOrFail();
        Sanctum::actingAs($guardian);
        $this->postJson('/api/v1/guardian/children', [
            'full_name' => 'Anak Sudah Dewasa',
            'birth_date' => now()->subYears(20)->format('Y-m-d'),
        ])->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    }

    public function test_coach_schedule_reschedule_requires_admin_approval_and_notifies_member_and_guardian(): void
    {
        $admin = User::where('email', 'admin@fitspace.local')->firstOrFail();
        $coachNadia = User::where('email', 'coach@fitspace.local')->firstOrFail();
        $coachDimas = User::where('email', 'coach.dimas@fitspace.local')->firstOrFail();
        $member = User::where('email', 'member@fitspace.local')->firstOrFail();

        $booking = DB::table('bookings')->where('booking_code', 'FS-BK-2026-0001')->first();
        $scheduleId = (int) $booking->schedule_id;
        $proposedTime = now()->addDays(3)->setTime(16, 0)->format('Y-m-d H:i:s');

        // 1. Unassigned Coach Dimas cannot request reschedule for Coach Nadia's class (403)
        Sanctum::actingAs($coachDimas);
        $this->postJson('/api/v1/coach/schedule-reschedules', [
            'schedule_id' => $scheduleId,
            'proposed_starts_at' => $proposedTime,
            'reason' => 'Tidak sah',
        ])->assertStatus(403);

        // 2. Assigned Coach Nadia requests reschedule -> stays pending until Admin approves!
        Sanctum::actingAs($coachNadia);
        $reqRes = $this->postJson('/api/v1/coach/schedule-reschedules', [
            'schedule_id' => $scheduleId,
            'proposed_starts_at' => $proposedTime,
            'reason' => 'Coach Nadia berhalangan hadir karena seminar pelatihan, mohon geser ke jam 16:00 WIB.',
        ])->assertStatus(201)->assertJsonPath('reschedule.status', 'pending');
        $rescheduleId = (int) $reqRes->json('reschedule.id');

        // Schedule NOT altered yet before Admin approval
        $this->assertNotSame($proposedTime, DB::table('class_schedules')->where('id', $scheduleId)->value('starts_at'));

        // 3. Admin approves Coach reschedule -> updates schedule, updates QR expiry, and forwards notification to affected Member!
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/schedule-reschedules/{$rescheduleId}/decision", [
            'decision' => 'approved',
            'admin_notes' => 'Disetujui Admin dan diteruskan ke seluruh peserta terdaftar.',
        ])->assertStatus(200)
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('affected_participants_count', 1);

        $this->assertSame($proposedTime, DB::table('class_schedules')->where('id', $scheduleId)->value('starts_at'));

        // 4. Member sees the Admin-approved schedule change notification in dashboard!
        Sanctum::actingAs($member);
        $dash = $this->getJson('/api/v1/member/dashboard')->assertStatus(200);
        $notifBodies = collect($dash->json('notifications'))->pluck('body')->implode(' ');
        $this->assertStringContainsString('Coach Nadia', $notifBodies);
        $this->assertStringContainsString('persetujuan Admin', $notifBodies);
    }

    public function test_admin_schedule_sync_issue_resolution_and_coach_report_publish(): void
    {
        $admin = User::where('email', 'admin@fitspace.local')->firstOrFail();
        $member = User::where('email', 'member@fitspace.local')->firstOrFail();
        $coach = User::where('email', 'coach@fitspace.local')->firstOrFail();

        // Member submits an issue ticket to Admin
        Sanctum::actingAs($member);
        $issueRes = $this->postJson('/api/v1/issues', [
            'category' => 'schedule_sync',
            'priority' => 'high',
            'subject' => 'Sinkronisasi slot Pilates akhir pekan',
            'description' => 'Mohon tambah slot Sabtu pagi agar cocok dengan jadwal Coach Nadia.',
        ])->assertStatus(201);
        $issueId = (int) $issueRes->json('issue.id');

        // Admin synchronizes pending Coach availability & resolves the issue
        Sanctum::actingAs($admin);
        $sync = $this->getJson('/api/v1/admin/schedule-sync')->assertStatus(200);
        $availId = (int) $sync->json('availabilities.0.id');

        $this->postJson("/api/v1/admin/availabilities/{$availId}/decision", [
            'decision' => 'approved',
            'notes' => 'Slot tambahan disinkronkan dengan kelas member.',
        ])->assertStatus(200)->assertJson(['status' => 'approved']);

        $this->postJson("/api/v1/admin/issues/{$issueId}/resolve", [
            'status' => 'resolved',
            'resolution_notes' => 'Jadwal tambahan Sabtu 09:00 WIB bersama Coach Nadia telah dibuka.',
        ])->assertStatus(200)->assertJsonPath('issue.status', 'resolved');

        // Coach publishes/unpublishes evaluation report visibility cleanly
        $booking = DB::table('bookings')->where('booking_code', 'FS-BK-2026-0001')->first();
        Sanctum::actingAs($coach);
        $evalRes = $this->postJson('/api/v1/coach/evaluations', [
            'booking_id' => $booking->id,
            'material_studied' => 'Core Alignment',
            'score_1' => 4,
            'score_2' => 4,
            'progress_notes' => 'Sangat konsisten.',
            'next_recommendation' => 'Level lanjutan.',
            'is_published' => false,
        ])->assertStatus(201)->assertJsonPath('is_published', false);
        $reportId = (int) $evalRes->json('progress_report_id');

        // Unpublished report is hidden from Member
        Sanctum::actingAs($member);
        $hiddenIds = collect($this->getJson('/api/v1/member/dashboard')->json('progress_reports'))->pluck('id')->all();
        $this->assertNotContains($reportId, $hiddenIds);

        // Coach publishes report -> immediately visible to Member
        Sanctum::actingAs($coach);
        $this->patchJson("/api/v1/coach/evaluations/{$reportId}/publish", ['is_published' => true])
            ->assertStatus(200)->assertJsonPath('is_published', true);

        Sanctum::actingAs($member);
        $visibleIds = collect($this->getJson('/api/v1/member/dashboard')->json('progress_reports'))->pluck('id')->all();
        $this->assertContains($reportId, $visibleIds);
    }
}

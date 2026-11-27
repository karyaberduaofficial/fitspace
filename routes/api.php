<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CoachController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\IssueController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'check']);

Route::prefix('v1')->group(function () {
    // Public catalog & schedules
    Route::get('/public/bootstrap', [PublicController::class, 'bootstrap'])->middleware('throttle:api');

    // Authentication (strictly rate limited — Member 18+, Orang Tua/Wali, Coach Application, Exclusive Admin Portal, Guest Mode, Google OAuth)
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/admin-login', [AuthController::class, 'adminLogin']);
        Route::post('/auth/guest', [AuthController::class, 'guestLogin']);
        Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
        Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);
        Route::post('/auth/google/token', [AuthController::class, 'googleIdToken']);
        Route::post('/auth/oauth-exchange', [AuthController::class, 'oauthExchange']);
        Route::post('/auth/demo-login', [AuthController::class, 'demoLogin']);
    });

    // Payment Gateway Webhooks (HMAC SHA-512 / Callback Token verified + redacted logs)
    Route::middleware('throttle:webhooks')->group(function () {
        Route::post('/webhooks/midtrans', [WebhookController::class, 'midtrans']);
        Route::post('/webhooks/xendit', [WebhookController::class, 'xendit']);
    });

    // Authenticated API routes
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/notifications', [AuthController::class, 'notifications']);
        Route::post('/notifications/push-subscribe', [AuthController::class, 'subscribePush']);

        // Read-only Guest Preview (accessible by Guest and any authenticated role)
        Route::get('/guest/preview', [AuthController::class, 'guestPreview'])
            ->middleware('role:guest,member,guardian,coach,admin');

        // Support / Issue reporting to Admin (non-guest authenticated users: member, guardian, coach, admin)
        Route::middleware('role:member,guardian,coach,admin')->group(function () {
            Route::get('/issues', [IssueController::class, 'index']);
            Route::post('/issues', [IssueController::class, 'store']);
        });

        // Approved Non-Guest Transactional Actions (Booking, Reschedule H-8h, Membership Purchase, Reviews, Report Exports)
        Route::middleware('approved')->group(function () {
            Route::post('/bookings', [BookingController::class, 'store']);
            Route::post('/bookings/{id}/reschedule', [BookingController::class, 'reschedule']);
            Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel']);
            Route::post('/memberships/purchase', [MembershipController::class, 'purchase']);
            Route::post('/reviews', [BookingController::class, 'storeReview']);
            Route::get('/reports/export', [ReportController::class, 'export'])->middleware('throttle:exports');
        });

        // Member (18+) & Guardian Workspace (guests strictly blocked; pending accounts can monitor status)
        Route::middleware('role:member,guardian')->group(function () {
            Route::get('/member/dashboard', [MemberController::class, 'dashboard']);
        });

        // Guardian / Parent Child Management (strictly < 18 children, Anti-IDOR)
        Route::middleware('role:guardian,admin')->group(function () {
            Route::get('/guardian/dashboard', [GuardianController::class, 'dashboard']);
            Route::get('/guardian/children', [GuardianController::class, 'children']);
            Route::get('/guardian/children/{id}', [GuardianController::class, 'showChild']);
            Route::post('/guardian/children', [GuardianController::class, 'storeChild']);
            Route::put('/guardian/children/{id}', [GuardianController::class, 'updateChild']);
            Route::get('/guardian/children/{id}/report-pdf', [GuardianController::class, 'childReportPdf'])
                ->middleware(['approved', 'throttle:exports']);
            Route::get('/guardian/children/{id}/report-word', [GuardianController::class, 'childReportWord'])
                ->middleware(['approved', 'throttle:exports']);
        });

        // Coach Operational Workspace (requires Admin-approved Coach!)
        // Coach receives schedules from Admin, scans QR, manages materials/scores, and submits schedule reschedule requests to Admin.
        Route::middleware(['role:coach,admin', 'approved'])->group(function () {
            Route::get('/coach/dashboard', [CoachController::class, 'dashboard']);
            Route::get('/coach/pending-accounts', [CoachController::class, 'pendingAccounts']);
            Route::post('/coach/accounts/{id}/decision', [CoachController::class, 'decideAccount']);
            Route::post('/coach/bookings/{id}/confirm', [CoachController::class, 'confirmBooking']);
            Route::post('/coach/schedule-reschedules', [CoachController::class, 'requestScheduleReschedule']);
            Route::post('/attendance/scan-qr', [CoachController::class, 'scanQr']);
            Route::post('/coach/materials', [CoachController::class, 'storeMaterial']);
            Route::post('/coach/evaluations', [CoachController::class, 'storeEvaluation']);
            Route::patch('/coach/evaluations/{id}/publish', [CoachController::class, 'togglePublishEvaluation']);
            Route::post('/coach/availabilities', [CoachController::class, 'storeAvailability']);
        });

        // Admin Exclusive Governance & Multi-Branch Control
        // Central authority: Coach/Member/Guardian Account Approvals, Class & Membership Approvals, Coach Schedule Reschedule ACC -> Member/Guardian
        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/approvals', [AdminController::class, 'approvals']);
            Route::post('/coaches/{id}/decision', [AdminController::class, 'decideCoach']);
            Route::post('/users/{id}/decision', [AdminController::class, 'decideUserAccount']);
            Route::post('/bookings/{id}/approve', [AdminController::class, 'approveBooking']);
            Route::post('/memberships/{id}/approve', [AdminController::class, 'approveMembership']);
            Route::get('/schedule-sync', [AdminController::class, 'scheduleSync']);
            Route::post('/availabilities/{id}/decision', [AdminController::class, 'decideAvailability']);
            Route::post('/schedule-reschedules/{id}/decision', [AdminController::class, 'decideCoachScheduleReschedule']);
            Route::get('/issues', [AdminController::class, 'issues']);
            Route::post('/issues/{id}/resolve', [AdminController::class, 'resolveIssue']);
            Route::post('/categories', [AdminController::class, 'storeCategory']);
            Route::post('/classes', [AdminController::class, 'storeClass']);
            Route::get('/schedule-meta', [AdminController::class, 'scheduleMeta']);
            Route::post('/schedules', [AdminController::class, 'storeSchedule']);
            Route::get('/bookings', [AdminController::class, 'bookings']);
            Route::post('/payments/{id}/refund', [AdminController::class, 'refundPayment']);
            Route::get('/finance', [AdminController::class, 'finance']);
            Route::post('/expenses', [AdminController::class, 'storeExpense']);
            Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
            Route::put('/settings', [AdminController::class, 'updateSettings']);
            Route::post('/branches', [AdminController::class, 'storeBranch']);
            Route::post('/promotions', [AdminController::class, 'storePromotion']);
        });
    });
});

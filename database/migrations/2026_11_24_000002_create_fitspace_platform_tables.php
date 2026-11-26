<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend users with role, phone, branch, status
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 30)->default('member')->index()->after('password');
            }
            if (! Schema::hasColumn('users', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('branch_id');
            }
        });

        // 7.1 Roles & Branches
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 80);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 150);
            $table->text('address');
            $table->string('city', 80);
            $table->string('phone', 30)->nullable();
            $table->string('operating_hours', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('member_code', 40)->unique();
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('emergency_contact', 120)->nullable();
            $table->text('health_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('relationship', 50)->default('Orang Tua');
            $table->string('emergency_phone', 30)->nullable();
            $table->timestamp('pdp_consent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();
            $table->string('full_name', 150);
            $table->date('birth_date');
            $table->string('gender', 20)->nullable();
            $table->text('medical_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 7.2 Coach & 7.3 Rooms / Multi-Branch
        Schema::create('coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('bio')->nullable();
            $table->unsignedSmallInteger('experience_years')->default(1);
            $table->text('certifications')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('specializations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('coach_specializations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('coaches')->cascadeOnDelete();
            $table->foreignId('specialization_id')->constrained('specializations')->cascadeOnDelete();
            $table->unique(['coach_id', 'specialization_id']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('facility_type', 80);
            $table->unsignedInteger('max_capacity')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('coach_id')->constrained('coaches')->cascadeOnDelete();
            $table->unique(['branch_id', 'coach_id']);
        });

        // 7.4 Dynamic Classes
        Schema::create('class_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->foreignId('specialization_id')->nullable()->constrained('specializations')->nullOnDelete();
            $table->string('color_token', 40)->default('pilates');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('class_levels', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('class_categories');
            $table->foreignId('level_id')->constrained('class_levels');
            $table->string('slug', 120)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('photo_url')->nullable();
            $table->unsignedTinyInteger('min_age')->default(6);
            $table->unsignedTinyInteger('max_age')->default(70);
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedInteger('max_capacity')->default(15);
            $table->decimal('price_per_session', 12, 2)->default(0);
            $table->string('required_facilities')->nullable();
            $table->boolean('evaluation_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('class_evaluation_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();
        });

        // 7.5 Schedules & 7.6 Materials
        Schema::create('coach_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('coaches')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->string('start_time', 10);
            $table->string('end_time', 10);
            $table->string('status', 30)->default('approved');
            $table->string('request_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('room_id')->constrained('rooms');
            $table->foreignId('coach_id')->constrained('coaches');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('booked_count')->default(0);
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_rule', 80)->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->timestamps();
            $table->index(['coach_id', 'starts_at', 'ends_at']);
            $table->index(['room_id', 'starts_at', 'ends_at']);
        });

        Schema::create('schedule_coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('class_schedules')->cascadeOnDelete();
            $table->foreignId('coach_id')->constrained('coaches')->cascadeOnDelete();
            $table->string('role', 40)->default('lead');
            $table->unique(['schedule_id', 'coach_id']);
        });

        Schema::create('class_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->text('learning_objective')->nullable();
            $table->string('difficulty', 40)->default('beginner');
            $table->unsignedSmallInteger('sequence_order')->default(1);
            $table->unsignedSmallInteger('duration_minutes')->default(15);
            $table->string('equipment_needed')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('lesson_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('class_schedules')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('class_materials')->cascadeOnDelete();
            $table->text('coach_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // 7.9 Memberships & 7.10 Promotions
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 140);
            $table->string('plan_type', 40);
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('session_quota');
            $table->json('allowed_categories')->nullable();
            $table->unsignedTinyInteger('usage_limit_per_day')->default(2);
            $table->boolean('includes_children')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('plan_id')->constrained('membership_plans');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedInteger('total_quota');
            $table->unsignedInteger('remaining_quota');
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->string('status', 30)->default('active');
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('discount_type', 20)->default('percent');
            $table->decimal('discount_value', 12, 2);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->unsignedInteger('max_uses')->default(100);
            $table->unsignedInteger('used_count')->default(0);
            $table->decimal('min_transaction', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 7.7 Bookings & 7.8 Payments
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 40)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('child_id')->nullable()->constrained('children')->nullOnDelete();
            $table->foreignId('schedule_id')->constrained('class_schedules');
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('booking_type', 30)->default('per_session');
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('final_amount', 12, 2)->default(0);
            $table->string('status', 30)->default('pending_payment');
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('participant_type', 20)->default('member');
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('child_id')->nullable()->constrained('children')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('reschedule_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('from_schedule_id')->constrained('class_schedules');
            $table->foreignId('to_schedule_id')->constrained('class_schedules');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('reason')->nullable();
            $table->decimal('hours_before_start', 8, 2);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 64)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->string('gateway', 30)->default('midtrans');
            $table->string('payment_method', 50)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('pending');
            $table->string('external_reference', 120)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_gateway_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('gateway', 30);
            $table->string('event_type', 60);
            $table->boolean('signature_valid')->default(false);
            $table->json('sanitized_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->string('status', 30)->default('processed');
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('membership_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('type', 30);
            $table->integer('quota_delta')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->decimal('discount_applied', 12, 2);
            $table->timestamps();
        });

        // 7.11 Attendance, 7.12 Progress, 7.13 Reviews
        Schema::create('attendance_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->string('token_hash', 128)->unique();
            $table->string('display_code', 40)->unique();
            $table->dateTime('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('class_schedules');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('child_id')->nullable()->constrained('children')->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->string('status', 20)->default('present');
            $table->timestamp('checked_in_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('progress_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('schedule_id')->constrained('class_schedules');
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('coach_id')->constrained('coaches');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('child_id')->nullable()->constrained('children')->nullOnDelete();
            $table->string('material_studied')->nullable();
            $table->decimal('average_score', 4, 2)->default(0);
            $table->text('progress_notes')->nullable();
            $table->text('improvement_areas')->nullable();
            $table->text('next_recommendation')->nullable();
            $table->date('evaluated_at');
            $table->timestamps();
        });

        Schema::create('progress_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_report_id')->constrained('progress_reports')->cascadeOnDelete();
            $table->foreignId('indicator_id')->nullable()->constrained('class_evaluation_indicators')->nullOnDelete();
            $table->string('indicator_name', 120);
            $table->unsignedTinyInteger('score');
            $table->string('score_label', 60);
            $table->timestamps();
        });

        Schema::create('coach_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('coach_id')->constrained('coaches');
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('schedule_id')->constrained('class_schedules');
            $table->unsignedTinyInteger('rating');
            $table->text('review')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });

        // 7.14 Finance, 7.15 Reporting & Notifications, 7.16 Settings & Audit
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->string('direction', 20); // income | refund
            $table->string('category', 60);
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->dateTime('transacted_at');
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->foreignId('recorded_by')->constrained('users');
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->date('incurred_on');
            $table->timestamps();
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('report_type', 60);
            $table->string('format', 16);
            $table->json('filters')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('title', 160);
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('web_push_enabled')->default(true);
            $table->boolean('booking_alerts')->default(true);
            $table->boolean('schedule_reminders')->default(true);
            $table->boolean('progress_alerts')->default(true);
            $table->boolean('promo_alerts')->default(false);
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 500);
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('group_name', 50)->default('general');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        $tables = [
            'audit_logs', 'settings', 'push_subscriptions', 'notification_preferences', 'notifications',
            'report_exports', 'expenses', 'expense_categories', 'financial_transactions', 'coach_reviews',
            'progress_details', 'progress_reports', 'attendance', 'attendance_qr_codes', 'coupon_usages',
            'membership_transactions', 'refunds', 'payment_gateway_logs', 'payments', 'reschedule_histories',
            'booking_participants', 'bookings', 'coupons', 'promotions', 'memberships', 'membership_plans',
            'lesson_sessions', 'class_materials', 'schedule_coaches', 'class_schedules', 'coach_availabilities',
            'class_evaluation_indicators', 'classes', 'class_levels', 'class_categories', 'branch_coaches',
            'rooms', 'coach_specializations', 'specializations', 'coaches', 'children', 'guardians',
            'members', 'branches', 'user_roles', 'roles',
        ];
        foreach ($tables as $t) {
            Schema::dropIfExists($t);
        }
    }
};

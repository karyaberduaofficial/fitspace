<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'approval_status')) {
                $table->string('approval_status', 20)->default('approved')->after('is_guest');
            }
            if (! Schema::hasColumn('users', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('users', 'approval_notes')) {
                $table->string('approval_notes', 300)->nullable()->after('approved_at');
            }
        });

        Schema::table('progress_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('progress_reports', 'is_published')) {
                $table->boolean('is_published')->default(true)->after('evaluated_at');
            }
        });

        Schema::table('coach_availabilities', function (Blueprint $table) {
            if (! Schema::hasColumn('coach_availabilities', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->after('request_notes')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('coach_availabilities', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            }
        });

        if (! Schema::hasTable('support_issues')) {
            Schema::create('support_issues', function (Blueprint $table) {
                $table->id();
                $table->string('ticket_code', 32)->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('category', 40)->default('other'); // schedule_sync, account_approval, payment, attendance_qr, facility, other
                $table->string('priority', 20)->default('medium'); // low, medium, high
                $table->string('subject', 160);
                $table->text('description');
                $table->string('status', 20)->default('open'); // open, in_progress, resolved
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('resolution_notes')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'priority']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_issues');

        Schema::table('progress_reports', function (Blueprint $table) {
            if (Schema::hasColumn('progress_reports', 'is_published')) {
                $table->dropColumn('is_published');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'approved_by')) {
                $table->dropConstrainedForeignId('approved_by');
            }
            foreach (['approval_notes', 'approved_at', 'approval_status', 'birth_date'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

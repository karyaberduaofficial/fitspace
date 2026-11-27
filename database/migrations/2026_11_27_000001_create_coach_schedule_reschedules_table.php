<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coach_schedule_reschedules')) {
            Schema::create('coach_schedule_reschedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->constrained('class_schedules')->cascadeOnDelete();
                $table->foreignId('coach_id')->constrained('coaches')->cascadeOnDelete();
                $table->dateTime('original_starts_at');
                $table->dateTime('original_ends_at');
                $table->dateTime('proposed_starts_at');
                $table->dateTime('proposed_ends_at');
                $table->text('reason');
                $table->string('status', 20)->default('pending'); // pending | approved | rejected
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('admin_notes')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedInteger('affected_participants_count')->default(0);
                $table->timestamps();
                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_schedule_reschedules');
    }
};

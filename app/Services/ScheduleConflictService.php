<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleConflictService
{
    public function validateAndCreate(array $data, int $actorUserId, AuditService $audit): int
    {
        $class = DB::table('classes as c')
            ->join('class_categories as cat', 'cat.id', '=', 'c.category_id')
            ->select('c.*', 'cat.specialization_id', 'cat.name as category_name')
            ->where('c.id', $data['class_id'])
            ->first();

        if (! $class) {
            throw ValidationException::withMessages(['class_id' => ['Kelas tidak ditemukan.']]);
        }

        $room = DB::table('rooms')->where('id', $data['room_id'])->where('is_active', true)->first();
        if (! $room) {
            throw ValidationException::withMessages(['room_id' => ['Ruangan tidak ditemukan atau tidak aktif.']]);
        }

        $coach = DB::table('coaches')->where('id', $data['coach_id'])->where('is_active', true)->first();
        if (! $coach) {
            throw ValidationException::withMessages(['coach_id' => ['Coach tidak ditemukan atau tidak aktif.']]);
        }

        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = isset($data['ends_at']) && $data['ends_at']
            ? Carbon::parse($data['ends_at'])
            : $startsAt->copy()->addMinutes((int) $class->duration_minutes);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => ['Waktu selesai harus setelah waktu mulai.']]);
        }

        $capacity = (int) ($data['capacity'] ?? $class->max_capacity);
        if ($capacity > (int) $room->max_capacity) {
            throw ValidationException::withMessages([
                'capacity' => ["Kapasitas jadwal ({$capacity}) melebihi kapasitas maksimal ruangan ({$room->max_capacity})."],
            ]);
        }

        // Business Rule #3: Coach specialization must match class category specialization
        if ($class->specialization_id) {
            $hasSpec = DB::table('coach_specializations')
                ->where('coach_id', $coach->id)
                ->where('specialization_id', $class->specialization_id)
                ->exists();

            if (! $hasSpec) {
                throw ValidationException::withMessages([
                    'coach_id' => ["Coach tidak memiliki spesialisasi untuk kategori kelas {$class->category_name}."],
                ]);
            }
        }

        $startStr = $startsAt->format('Y-m-d H:i:s');
        $endStr = $endsAt->format('Y-m-d H:i:s');

        // Business Rule #4: Prevent overlapping Coach schedule
        $coachConflict = DB::table('class_schedules')
            ->where('coach_id', $coach->id)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $endStr)
            ->where('ends_at', '>', $startStr)
            ->exists();

        if ($coachConflict) {
            throw ValidationException::withMessages([
                'coach_id' => ['Jadwal bentrok: Coach sudah memiliki jadwal mengajar pada rentang waktu tersebut.'],
            ]);
        }

        // Business Rule #5: Prevent overlapping Room schedule
        $roomConflict = DB::table('class_schedules')
            ->where('room_id', $room->id)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $endStr)
            ->where('ends_at', '>', $startStr)
            ->exists();

        if ($roomConflict) {
            throw ValidationException::withMessages([
                'room_id' => ['Jadwal bentrok: Ruangan sudah digunakan kelas lain pada rentang waktu tersebut.'],
            ]);
        }

        $now = now();
        $scheduleId = DB::table('class_schedules')->insertGetId([
            'class_id' => $class->id,
            'branch_id' => $room->branch_id,
            'room_id' => $room->id,
            'coach_id' => $coach->id,
            'starts_at' => $startStr,
            'ends_at' => $endStr,
            'capacity' => $capacity,
            'booked_count' => 0,
            'is_recurring' => (bool) ($data['is_recurring'] ?? false),
            'recurrence_rule' => $data['recurrence_rule'] ?? null,
            'status' => 'scheduled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('schedule_coaches')->insert([
            'schedule_id' => $scheduleId,
            'coach_id' => $coach->id,
            'role' => 'lead',
        ]);

        $audit->log($actorUserId, 'schedule.created', 'class_schedule', $scheduleId, [
            'class_id' => $class->id,
            'coach_id' => $coach->id,
            'room_id' => $room->id,
            'starts_at' => $startStr,
        ]);

        return $scheduleId;
    }
}

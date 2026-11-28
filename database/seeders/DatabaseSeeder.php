<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 1. Roles (4 Authenticated Roles + Guest Preview Role)
        $roleMap = [];
        foreach ([
            ['admin', 'Administrator', 'Portal Admin khusus: persetujuan akun/kelas/membership, ACC reschedule jadwal Coach, penanganan masalah & multi-branch'],
            ['coach', 'Coach / Pelatih', 'Menerima jadwal resmi Admin, memindai QR absensi, mengisi materi & nilai (1–4), serta mengajukan reschedule jadwal ke Admin'],
            ['member', 'Member (18+)', 'Peserta usia 18 tahun ke atas: daftar, membership, booking kelas, reschedule, QR absensi & laporan'],
            ['guardian', 'Orang Tua / Wali', 'Perwakilan anak (<18 thn): kelola profil anak, membership, kelas sesuai usia, jadwal, QR absensi & rapor anak'],
            ['guest', 'Akun Tamu', 'Akses pratinjau terbatas katalog kelas, jadwal live & paket membership sebelum login Member/Orang Tua'],
        ] as [$slug, $name, $desc]) {
            $id = DB::table('roles')->insertGetId([
                'slug' => $slug, 'name' => $name, 'description' => $desc, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $roleMap[$slug] = $id;
        }

        // 2. Multi-Branch
        $jakselId = DB::table('branches')->insertGetId([
            'code' => 'FS-JKS', 'name' => 'FitSpace Jakarta Selatan',
            'address' => 'Jl. Kemang Raya No. 88, Kemang, Jakarta Selatan 12730',
            'city' => 'Jakarta Selatan', 'phone' => '(021) 5088-4200',
            'operating_hours' => 'Senin – Minggu, 06:00 – 21:00 WIB',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $bdgId = DB::table('branches')->insertGetId([
            'code' => 'FS-BDG', 'name' => 'FitSpace Bandung',
            'address' => 'Jl. Ir. H. Juanda No. 142, Dago, Kota Bandung 40132',
            'city' => 'Bandung', 'phone' => '(022) 4208-9100',
            'operating_hours' => 'Senin – Minggu, 06:00 – 21:00 WIB',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // 3. Rooms
        $roomStudioA = DB::table('rooms')->insertGetId(['branch_id' => $jakselId, 'name' => 'Studio A (Reformer)', 'facility_type' => 'pilates_studio', 'max_capacity' => 14, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $roomHall2 = DB::table('rooms')->insertGetId(['branch_id' => $jakselId, 'name' => 'Hall 2 (Dance & Cardio)', 'facility_type' => 'cardio_hall', 'max_capacity' => 25, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $roomRing1 = DB::table('rooms')->insertGetId(['branch_id' => $jakselId, 'name' => 'Ring 1 (Combat Zone)', 'facility_type' => 'boxing_ring', 'max_capacity' => 12, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $roomPoolB = DB::table('rooms')->insertGetId(['branch_id' => $jakselId, 'name' => 'Pool B (Kids & Aqua)', 'facility_type' => 'swimming_pool', 'max_capacity' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $roomDojo1 = DB::table('rooms')->insertGetId(['branch_id' => $bdgId, 'name' => 'Dojo 1 (Martial Arts)', 'facility_type' => 'dojo', 'max_capacity' => 16, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $roomYogaBdg = DB::table('rooms')->insertGetId(['branch_id' => $bdgId, 'name' => 'Studio B (Mind & Flow)', 'facility_type' => 'yoga_studio', 'max_capacity' => 18, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        // 4. Users for 4 RBAC roles (password read strictly from local environment or generated randomly)
        $demoSecret = (string) env('FITSPACE_SEED_PASSWORD', '');
        if ($demoSecret === '') {
            $demoSecret = Str::random(24);
        }
        $makeUser = function (
            string $name,
            string $email,
            string $phone,
            string $role,
            ?int $branchId,
            ?string $birthDate = null,
            string $approvalStatus = 'approved'
        ) use ($demoSecret, $roleMap, $now): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'birth_date' => $birthDate,
                'password' => Hash::make($demoSecret),
                'role' => $role,
                'branch_id' => $branchId,
                'is_active' => true,
                'is_guest' => false,
                'approval_status' => $approvalStatus,
                'approved_at' => $approvalStatus === 'approved' ? $now : null,
            ]);
            DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleMap[$role], 'created_at' => $now, 'updated_at' => $now]);
            DB::table('notification_preferences')->insert(['user_id' => $user->id, 'created_at' => $now, 'updated_at' => $now]);
            return $user;
        };

        $adminUser = $makeUser('Admin Pusat FitSpace', 'admin@fitspace.local', '02150884200', 'admin', $jakselId, '1990-02-14', 'approved');
        $coachNadiaUser = $makeUser('Coach Nadia Putri', 'coach@fitspace.local', '081210000001', 'coach', $jakselId, '1993-07-11', 'approved');
        $coachRakaUser = $makeUser('Coach Raka Wijaya', 'coach.raka@fitspace.local', '081210000002', 'coach', $jakselId, '1992-05-20', 'approved');
        $coachDimasUser = $makeUser('Coach Dimas Pratama', 'coach.dimas@fitspace.local', '081210000003', 'coach', $jakselId, '1994-11-08', 'approved');
        $pendingCoachUser = $makeUser('Coach Arman Santoso', 'coach.calon@fitspace.local', '081210000009', 'coach', $bdgId, '1995-03-19', 'pending');
        $memberUser = $makeUser('Dina Rahma', 'member@fitspace.local', '081220000001', 'member', $jakselId, '1996-04-18', 'approved');
        $pendingMemberUser = $makeUser('Raka Mahendra', 'member.baru@fitspace.local', '081220000009', 'member', $jakselId, '2001-09-12', 'pending');
        $guardianUser = $makeUser('Budi Santoso', 'wali@fitspace.local', '081230000001', 'guardian', $jakselId, '1988-01-25', 'approved');
        $otherGuardianUser = $makeUser('Sari Wulandari', 'wali2@fitspace.local', '081230000002', 'guardian', $bdgId, '1989-10-03', 'approved');
        $pendingGuardianUser = $makeUser('Lestari Dewi', 'wali.baru@fitspace.local', '081230000009', 'guardian', $jakselId, '1991-06-14', 'pending');

        // Member, Guardian & Children Profiles
        $memberId = DB::table('members')->insertGetId([
            'user_id' => $memberUser->id, 'branch_id' => $jakselId, 'member_code' => 'MBR-2026-001',
            'birth_date' => '1996-04-18', 'gender' => 'Perempuan', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $guardianId = DB::table('guardians')->insertGetId([
            'user_id' => $guardianUser->id, 'branch_id' => $jakselId, 'relationship' => 'Ayah',
            'emergency_phone' => '081230000099', 'pdp_consent_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $otherGuardianId = DB::table('guardians')->insertGetId([
            'user_id' => $otherGuardianUser->id, 'branch_id' => $bdgId, 'relationship' => 'Ibu',
            'emergency_phone' => '081230000088', 'pdp_consent_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $childAlyaId = DB::table('children')->insertGetId([
            'guardian_id' => $guardianId, 'full_name' => 'Alya Putri', 'birth_date' => '2018-06-12',
            'gender' => 'Perempuan', 'medical_notes' => 'Tidak ada alergi', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $childRafaId = DB::table('children')->insertGetId([
            'guardian_id' => $guardianId, 'full_name' => 'Rafa Pratama', 'birth_date' => '2015-03-04',
            'gender' => 'Laki-laki', 'medical_notes' => 'Sehat aktif', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $childNadiaId = DB::table('children')->insertGetId([
            'guardian_id' => $otherGuardianId, 'full_name' => 'Nadia Kirana', 'birth_date' => '2017-09-21',
            'gender' => 'Perempuan', 'medical_notes' => 'Privat', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // 5. Specializations & Coaches
        $specIds = [];
        foreach (['pilates' => 'Pilates', 'zumba' => 'Zumba', 'boxing' => 'Boxing', 'swimming' => 'Swimming', 'martial-arts' => 'Martial Arts', 'yoga' => 'Yoga'] as $slug => $name) {
            $specIds[$slug] = DB::table('specializations')->insertGetId(['slug' => $slug, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }

        $coachNadiaId = DB::table('coaches')->insertGetId(['user_id' => $coachNadiaUser->id, 'bio' => 'Instruktur Pilates & Vinyasa bersertifikasi internasional.', 'experience_years' => 7, 'certifications' => 'STOTT Pilates, RYT-200 Yoga', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $coachRakaId = DB::table('coaches')->insertGetId(['user_id' => $coachRakaUser->id, 'bio' => 'Spesialis Swimming Kids & Taekwondo Kids.', 'experience_years' => 6, 'certifications' => 'FINA Youth Coach, Dan 3 Taekwondo', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $coachDimasId = DB::table('coaches')->insertGetId(['user_id' => $coachDimasUser->id, 'bio' => 'Pelatih Boxing & Cardio Zumba energi tinggi.', 'experience_years' => 5, 'certifications' => 'Certified Boxing Coach, Zumba B1', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $pendingCoachId = DB::table('coaches')->insertGetId(['user_id' => $pendingCoachUser->id, 'bio' => 'Calon pelatih Yoga & Mobility tersertifikasi.', 'experience_years' => 4, 'certifications' => 'RYT-200 Yoga Alliance', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now]);

        foreach ([[$coachNadiaId, 'pilates'], [$coachNadiaId, 'yoga'], [$coachRakaId, 'swimming'], [$coachRakaId, 'martial-arts'], [$coachDimasId, 'boxing'], [$coachDimasId, 'zumba'], [$pendingCoachId, 'yoga']] as [$cid, $sp]) {
            DB::table('coach_specializations')->insert(['coach_id' => $cid, 'specialization_id' => $specIds[$sp]]);
        }
        foreach ([[$jakselId, $coachNadiaId], [$jakselId, $coachRakaId], [$jakselId, $coachDimasId], [$bdgId, $coachNadiaId], [$bdgId, $coachRakaId], [$bdgId, $pendingCoachId]] as [$bid, $cid]) {
            DB::table('branch_coaches')->insert(['branch_id' => $bid, 'coach_id' => $cid]);
        }

        // Pending Member & Pending Guardian Profiles + Pending Coach Schedule Sync & Support Issues
        DB::table('members')->insert([
            'user_id' => $pendingMemberUser->id, 'branch_id' => $jakselId, 'member_code' => 'MBR-2026-009',
            'birth_date' => '2001-09-12', 'gender' => 'Laki-laki', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $pendingGuardianId = DB::table('guardians')->insertGetId([
            'user_id' => $pendingGuardianUser->id, 'branch_id' => $jakselId, 'relationship' => 'Ibu',
            'emergency_phone' => '081230000077', 'pdp_consent_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('children')->insert([
            'guardian_id' => $pendingGuardianId, 'full_name' => 'Kezia Putri', 'birth_date' => '2019-08-10',
            'gender' => 'Perempuan', 'medical_notes' => 'Pemula renang', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('coach_availabilities')->insert([
            'coach_id' => $coachNadiaId, 'branch_id' => $jakselId, 'day_of_week' => 4,
            'start_time' => '08:00', 'end_time' => '13:00', 'status' => 'pending',
            'request_notes' => 'Tambahan slot Reformer Pilates pagi untuk mengakomodasi antrean member.',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('support_issues')->insert([
            [
                'ticket_code' => 'FS-ISS-2026-0001', 'user_id' => $memberUser->id, 'branch_id' => $jakselId,
                'category' => 'schedule_sync', 'priority' => 'high',
                'subject' => 'Permintaan sinkronisasi jadwal Pilates sore dengan Coach Nadia',
                'description' => 'Mohon penambahan 1 sesi Reformer Pilates pada Kamis pukul 17:30 WIB karena kelas 14:00 sering penuh.',
                'status' => 'open', 'created_at' => $now->copy()->subHours(5), 'updated_at' => $now->copy()->subHours(5),
            ],
            [
                'ticket_code' => 'FS-ISS-2026-0002', 'user_id' => $guardianUser->id, 'branch_id' => $jakselId,
                'category' => 'attendance_qr', 'priority' => 'medium',
                'subject' => 'Bantuan pencocokan absensi QR Swimming Kids',
                'description' => 'Saat check-in pekan lalu koneksi studio sempat lambat; mohon pastikan kehadiran Alya sudah tercatat di rapor.',
                'status' => 'in_progress', 'created_at' => $now->copy()->subHours(18), 'updated_at' => $now->copy()->subHours(6),
            ],
        ]);

        // 6. Categories, Levels, Dynamic Classes, Indicators & Materials
        $lvlBeg = DB::table('class_levels')->insertGetId(['slug' => 'beginner', 'name' => 'Beginner', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $lvlInt = DB::table('class_levels')->insertGetId(['slug' => 'intermediate', 'name' => 'Intermediate', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now]);
        $lvlAll = DB::table('class_levels')->insertGetId(['slug' => 'all-level', 'name' => 'All Level', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now]);

        $catIds = [];
        foreach ([
            ['pilates', 'Pilates', 'Penguatan otot inti, postur & fleksibilitas', 'pilates'],
            ['zumba', 'Zumba', 'Kardio tari berenergi tinggi untuk stamina', 'zumba'],
            ['boxing', 'Boxing', 'Teknik pukulan, footwork & conditioning', 'boxing'],
            ['swimming', 'Swimming', 'Teknik pernapasan, mengapung & renang anak/dewasa', 'swimming'],
            ['martial-arts', 'Martial Arts', 'Taekwondo disiplin, kuda-kuda & bela diri', 'martial'],
            ['yoga', 'Yoga', 'Hatha & Vinyasa flow untuk mobilitas & relaksasi', 'yoga'],
        ] as [$slug, $name, $desc, $tok]) {
            $catIds[$slug] = DB::table('class_categories')->insertGetId([
                'slug' => $slug, 'name' => $name, 'description' => $desc,
                'specialization_id' => $specIds[$slug], 'color_token' => $tok, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $clsPilates = DB::table('classes')->insertGetId([
            'category_id' => $catIds['pilates'], 'level_id' => $lvlInt, 'slug' => 'reformer-pilates',
            'name' => 'Reformer Pilates', 'description' => 'Penguatan core stability, keselarasan postur, dan pernapasan diafragma menggunakan mesin Reformer.',
            'photo_url' => '/images/classes/reformer-pilates.jpg',
            'min_age' => 16, 'max_age' => 65, 'duration_minutes' => 60, 'max_capacity' => 14, 'price_per_session' => 185000,
            'required_facilities' => 'Reformer Machine, Matras', 'evaluation_enabled' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $clsZumba = DB::table('classes')->insertGetId([
            'category_id' => $catIds['zumba'], 'level_id' => $lvlAll, 'slug' => 'zumba-regular',
            'name' => 'Zumba Regular Cardio', 'description' => 'Latihan kardio ritmis yang membakar kalori sambil meningkatkan koordinasi dan kebugaran.',
            'photo_url' => '/images/classes/zumba-cardio.jpg',
            'min_age' => 13, 'max_age' => 60, 'duration_minutes' => 50, 'max_capacity' => 25, 'price_per_session' => 95000,
            'required_facilities' => 'Sound System, Hall', 'evaluation_enabled' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $clsBoxing = DB::table('classes')->insertGetId([
            'category_id' => $catIds['boxing'], 'level_id' => $lvlInt, 'slug' => 'boxing-intermediate',
            'name' => 'Boxing Conditioning', 'description' => 'Latihan teknik jab-cross-hook, footwork lincah, dan ketahanan fisik berbasis ring.',
            'photo_url' => '/images/classes/boxing-conditioning.jpg',
            'min_age' => 15, 'max_age' => 55, 'duration_minutes' => 60, 'max_capacity' => 12, 'price_per_session' => 150000,
            'required_facilities' => 'Sarung Tinju, Heavy Bag', 'evaluation_enabled' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $clsSwimKids = DB::table('classes')->insertGetId([
            'category_id' => $catIds['swimming'], 'level_id' => $lvlBeg, 'slug' => 'swimming-kids',
            'name' => 'Swimming Kids Foundation', 'description' => 'Kelas renang ramah anak usia 6–12 tahun dengan pemantauan teknik napas, mengapung, dan gaya dasar.',
            'photo_url' => '/images/classes/swimming-kids.jpg',
            'min_age' => 6, 'max_age' => 12, 'duration_minutes' => 45, 'max_capacity' => 10, 'price_per_session' => 135000,
            'required_facilities' => 'Kolam Anak, Kickboard', 'evaluation_enabled' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $clsTaekwondo = DB::table('classes')->insertGetId([
            'category_id' => $catIds['martial-arts'], 'level_id' => $lvlBeg, 'slug' => 'taekwondo-kids',
            'name' => 'Taekwondo Kids Discipline', 'description' => 'Pembentukan karakter, kuda-kuda dasar, tendangan, dan koordinasi motorik untuk anak usia 6–13 tahun.',
            'photo_url' => '/images/classes/taekwondo-kids.jpg',
            'min_age' => 6, 'max_age' => 13, 'duration_minutes' => 60, 'max_capacity' => 16, 'price_per_session' => 120000,
            'required_facilities' => 'Matras Dojo, Target Pad', 'evaluation_enabled' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $clsYoga = DB::table('classes')->insertGetId([
            'category_id' => $catIds['yoga'], 'level_id' => $lvlAll, 'slug' => 'vinyasa-yoga',
            'name' => 'Vinyasa Flow & Mobility', 'description' => 'Aliran pose dinamis yang memadukan pernapasan, keseimbangan, dan fleksibilitas sendi.',
            'photo_url' => '/images/classes/vinyasa-yoga.jpg',
            'min_age' => 15, 'max_age' => 70, 'duration_minutes' => 60, 'max_capacity' => 18, 'price_per_session' => 110000,
            'required_facilities' => 'Matras Yoga, Balok', 'evaluation_enabled' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Dynamic Evaluation Indicators (Section 4.10)
        $indMap = [
            $clsPilates => ['Postur Tubuh', 'Keseimbangan', 'Fleksibilitas'],
            $clsZumba => ['Koordinasi', 'Ketepatan Gerakan', 'Stamina'],
            $clsBoxing => ['Teknik Pukulan', 'Footwork', 'Koordinasi'],
            $clsSwimKids => ['Teknik Pernapasan', 'Mengapung', 'Gerakan Renang'],
            $clsTaekwondo => ['Kuda-kuda', 'Tendangan', 'Pukulan', 'Pertahanan'],
            $clsYoga => ['Pernapasan', 'Keseimbangan', 'Fleksibilitas'],
        ];
        foreach ($indMap as $cid => $inds) {
            foreach ($inds as $i => $indName) {
                DB::table('class_evaluation_indicators')->insert([
                    'class_id' => $cid, 'name' => $indName, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // Learning Materials (Section 4.8)
        foreach ([
            [$clsPilates, 'Breathing Technique', 'Aktivasi diafragma & panggul netral', 15, 'Matras'],
            [$clsPilates, 'Core Stability Progression', 'Kontrol batang tubuh pada Reformer', 30, 'Reformer Machine'],
            [$clsZumba, 'Basic Dance Movement', 'Pemanasan ritmis & koreografi dasar', 25, 'Sound System'],
            [$clsBoxing, 'Basic Punch & Guard', 'Jab, cross, dan posisi pertahanan', 25, 'Sarung Tinju'],
            [$clsSwimKids, 'Floating Technique', 'Mengapung telentang & kontrol napas', 20, 'Kickboard'],
            [$clsTaekwondo, 'Basic Stance (Ap Kubi)', 'Kuda-kuda dasar dan disiplin sikap', 20, 'Matras Dojo'],
        ] as $idx => [$cid, $title, $desc, $dur, $eq]) {
            DB::table('class_materials')->insert([
                'class_id' => $cid, 'created_by' => $coachNadiaUser->id, 'title' => $title,
                'description' => $desc, 'learning_objective' => $desc, 'difficulty' => 'beginner',
                'sequence_order' => $idx + 1, 'duration_minutes' => $dur, 'equipment_needed' => $eq,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // 7. Schedules (upcoming >24h for reschedule + near <5h for H-8 guard + alternative slot)
        $schPilatesMain = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsPilates, 'branch_id' => $jakselId, 'room_id' => $roomStudioA, 'coach_id' => $coachNadiaId,
            'starts_at' => $now->copy()->addHours(26), 'ends_at' => $now->copy()->addHours(27),
            'capacity' => 14, 'booked_count' => 1, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $schPilatesAlt = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsPilates, 'branch_id' => $jakselId, 'room_id' => $roomStudioA, 'coach_id' => $coachNadiaId,
            'starts_at' => $now->copy()->addHours(50), 'ends_at' => $now->copy()->addHours(51),
            'capacity' => 14, 'booked_count' => 0, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $schSwimKids = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsSwimKids, 'branch_id' => $jakselId, 'room_id' => $roomPoolB, 'coach_id' => $coachRakaId,
            'starts_at' => $now->copy()->addHours(30), 'ends_at' => $now->copy()->addHours(31),
            'capacity' => 10, 'booked_count' => 1, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $schBoxing = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsBoxing, 'branch_id' => $jakselId, 'room_id' => $roomRing1, 'coach_id' => $coachDimasId,
            'starts_at' => $now->copy()->addHours(34), 'ends_at' => $now->copy()->addHours(35),
            'capacity' => 12, 'booked_count' => 0, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $schZumba = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsZumba, 'branch_id' => $jakselId, 'room_id' => $roomHall2, 'coach_id' => $coachDimasId,
            'starts_at' => $now->copy()->addHours(54), 'ends_at' => $now->copy()->addHours(55),
            'capacity' => 25, 'booked_count' => 0, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $schYogaNear = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsYoga, 'branch_id' => $bdgId, 'room_id' => $roomYogaBdg, 'coach_id' => $coachNadiaId,
            'starts_at' => $now->copy()->addHours(4), 'ends_at' => $now->copy()->addHours(5),
            'capacity' => 18, 'booked_count' => 1, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $schTaekwondo = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsTaekwondo, 'branch_id' => $bdgId, 'room_id' => $roomDojo1, 'coach_id' => $coachRakaId,
            'starts_at' => $now->copy()->addHours(72), 'ends_at' => $now->copy()->addHours(73),
            'capacity' => 16, 'booked_count' => 1, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Historical Completed Studio Schedules (inserted AFTER upcoming slots so test ->first() queries target upcoming slots)
        $schPastPilates1 = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsPilates, 'branch_id' => $jakselId, 'room_id' => $roomStudioA, 'coach_id' => $coachNadiaId,
            'starts_at' => $now->copy()->subDays(1)->setTime(9, 0), 'ends_at' => $now->copy()->subDays(1)->setTime(10, 0),
            'capacity' => 14, 'booked_count' => 11, 'status' => 'completed', 'created_at' => $now->copy()->subDays(5), 'updated_at' => $now->copy()->subDays(1),
        ]);
        $schPastBoxing1 = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsBoxing, 'branch_id' => $jakselId, 'room_id' => $roomRing1, 'coach_id' => $coachDimasId,
            'starts_at' => $now->copy()->subDays(2)->setTime(17, 0), 'ends_at' => $now->copy()->subDays(2)->setTime(18, 0),
            'capacity' => 12, 'booked_count' => 9, 'status' => 'completed', 'created_at' => $now->copy()->subDays(6), 'updated_at' => $now->copy()->subDays(2),
        ]);
        $schPastSwim1 = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsSwimKids, 'branch_id' => $jakselId, 'room_id' => $roomPoolB, 'coach_id' => $coachRakaId,
            'starts_at' => $now->copy()->subDays(3)->setTime(15, 30), 'ends_at' => $now->copy()->subDays(3)->setTime(16, 15),
            'capacity' => 10, 'booked_count' => 8, 'status' => 'completed', 'created_at' => $now->copy()->subDays(7), 'updated_at' => $now->copy()->subDays(3),
        ]);
        $schPastTaekwondo1 = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsTaekwondo, 'branch_id' => $bdgId, 'room_id' => $roomDojo1, 'coach_id' => $coachRakaId,
            'starts_at' => $now->copy()->subDays(4)->setTime(16, 0), 'ends_at' => $now->copy()->subDays(4)->setTime(17, 0),
            'capacity' => 16, 'booked_count' => 12, 'status' => 'completed', 'created_at' => $now->copy()->subDays(8), 'updated_at' => $now->copy()->subDays(4),
        ]);
        $schPastZumba1 = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsZumba, 'branch_id' => $jakselId, 'room_id' => $roomHall2, 'coach_id' => $coachDimasId,
            'starts_at' => $now->copy()->subDays(6)->setTime(18, 30), 'ends_at' => $now->copy()->subDays(6)->setTime(19, 20),
            'capacity' => 25, 'booked_count' => 19, 'status' => 'completed', 'created_at' => $now->copy()->subDays(10), 'updated_at' => $now->copy()->subDays(6),
        ]);
        $schPastYoga1 = DB::table('class_schedules')->insertGetId([
            'class_id' => $clsYoga, 'branch_id' => $bdgId, 'room_id' => $roomYogaBdg, 'coach_id' => $coachNadiaId,
            'starts_at' => $now->copy()->subDays(10)->setTime(8, 0), 'ends_at' => $now->copy()->subDays(10)->setTime(9, 0),
            'capacity' => 18, 'booked_count' => 14, 'status' => 'completed', 'created_at' => $now->copy()->subDays(14), 'updated_at' => $now->copy()->subDays(10),
        ]);

        // 8. Membership Plans (6 Pilihan Menarik), Active Memberships & Promo Coupons
        $planStarter = DB::table('membership_plans')->insertGetId([
            'slug' => 'paket-starter-5', 'name' => 'Starter 5 Sesi', 'plan_type' => 'starter',
            'price' => 625000, 'duration_days' => 30, 'session_quota' => 5, 'includes_children' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $plan10 = DB::table('membership_plans')->insertGetId([
            'slug' => 'paket-10-sesi', 'name' => 'Paket 10 Sesi Fleksibel', 'plan_type' => 'session_pack',
            'price' => 1150000, 'duration_days' => 60, 'session_quota' => 10, 'includes_children' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $planPro = DB::table('membership_plans')->insertGetId([
            'slug' => 'membership-bulanan-pro', 'name' => 'Membership Bulanan Pro', 'plan_type' => 'monthly',
            'price' => 890000, 'duration_days' => 30, 'session_quota' => 20, 'includes_children' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('membership_plans')->insert([
            'slug' => 'unlimited-all-access', 'name' => 'Unlimited All-Access 30 Hari', 'plan_type' => 'unlimited',
            'price' => 1390000, 'duration_days' => 30, 'session_quota' => 45, 'includes_children' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('membership_plans')->insert([
            'slug' => 'paket-quarterly-90', 'name' => 'Quarterly Performance 90 Hari', 'plan_type' => 'quarterly',
            'price' => 2390000, 'duration_days' => 90, 'session_quota' => 60, 'includes_children' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('membership_plans')->insert([
            'slug' => 'vip-annual-pass', 'name' => 'VIP Annual Wellness Pass', 'plan_type' => 'annual',
            'price' => 7900000, 'duration_days' => 365, 'session_quota' => 240, 'includes_children' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $memId = DB::table('memberships')->insertGetId([
            'user_id' => $memberUser->id, 'plan_id' => $planPro, 'branch_id' => $jakselId,
            'total_quota' => 20, 'remaining_quota' => 13, 'starts_at' => $now->copy()->subDays(7),
            'expires_at' => $now->copy()->addDays(23), 'status' => 'active', 'created_at' => $now->copy()->subDays(7), 'updated_at' => $now,
        ]);
        $memGuardianBudiId = DB::table('memberships')->insertGetId([
            'user_id' => $guardianUser->id, 'plan_id' => $plan10, 'branch_id' => $jakselId,
            'total_quota' => 10, 'remaining_quota' => 7, 'starts_at' => $now->copy()->subDays(5),
            'expires_at' => $now->copy()->addDays(55), 'status' => 'active', 'created_at' => $now->copy()->subDays(5), 'updated_at' => $now,
        ]);
        $memGuardianSariId = DB::table('memberships')->insertGetId([
            'user_id' => $otherGuardianUser->id, 'plan_id' => $planStarter, 'branch_id' => $bdgId,
            'total_quota' => 5, 'remaining_quota' => 3, 'starts_at' => $now->copy()->subDays(3),
            'expires_at' => $now->copy()->addDays(27), 'status' => 'active', 'created_at' => $now->copy()->subDays(3), 'updated_at' => $now,
        ]);

        $promoId = DB::table('promotions')->insertGetId([
            'name' => 'Diskon Member FitSpace 15%', 'description' => 'Promo resmi pembukaan cabang',
            'discount_type' => 'percent', 'discount_value' => 15, 'starts_at' => $now->copy()->subDays(15),
            'ends_at' => $now->copy()->addDays(60), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $coupon15Id = DB::table('coupons')->insertGetId([
            'promotion_id' => $promoId, 'code' => 'FITSPACE15', 'max_uses' => 200, 'used_count' => 14,
            'min_transaction' => 50000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $promoFamilyId = DB::table('promotions')->insertGetId([
            'name' => 'Promo Kelas Anak & Keluarga 20%', 'description' => 'Potongan khusus pendaftaran kelas Swimming & Taekwondo Kids',
            'discount_type' => 'percent', 'discount_value' => 20, 'starts_at' => $now->copy()->subDays(10),
            'ends_at' => $now->copy()->addDays(45), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $couponFamilyId = DB::table('coupons')->insertGetId([
            'promotion_id' => $promoFamilyId, 'code' => 'KELUARGA20', 'max_uses' => 100, 'used_count' => 9,
            'min_transaction' => 100000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $promoNewId = DB::table('promotions')->insertGetId([
            'name' => 'Potongan Rp 50.000 Membership Baru', 'description' => 'Voucher potongan langsung aktivasi membership perdana',
            'discount_type' => 'fixed', 'discount_value' => 50000, 'starts_at' => $now->copy()->subDays(20),
            'ends_at' => $now->copy()->addDays(90), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('coupons')->insert([
            'promotion_id' => $promoNewId, 'code' => 'FITNEW50K', 'max_uses' => 150, 'used_count' => 18,
            'min_transaction' => 500000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // 9. Confirmed + Completed Bookings, Single-Use QR, Attendance, Payments, Financial Ledger, Reviews & Progress Reports
        $qrSecret = (string) (config('fitspace.qr_signing_secret') ?: config('app.key') ?: Str::random(32));
        $bMember = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0001', 'user_id' => $memberUser->id, 'schedule_id' => $schPilatesMain,
            'membership_id' => $memId, 'booking_type' => 'membership', 'gross_amount' => 185000, 'discount_amount' => 0,
            'final_amount' => 0, 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $qrCode1 = 'FS-QR-8821';
        DB::table('attendance_qr_codes')->insert([
            'booking_id' => $bMember, 'token_hash' => hash_hmac('sha256', $qrCode1, $qrSecret),
            'display_code' => $qrCode1, 'expires_at' => $now->copy()->addHours(28), 'created_at' => $now, 'updated_at' => $now,
        ]);

        $bMemberNear = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0002', 'user_id' => $memberUser->id, 'schedule_id' => $schYogaNear,
            'booking_type' => 'per_session', 'gross_amount' => 110000, 'discount_amount' => 0,
            'final_amount' => 110000, 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $qrCode2 = 'FS-QR-8822';
        DB::table('attendance_qr_codes')->insert([
            'booking_id' => $bMemberNear, 'token_hash' => hash_hmac('sha256', $qrCode2, $qrSecret),
            'display_code' => $qrCode2, 'expires_at' => $now->copy()->addHours(6), 'created_at' => $now, 'updated_at' => $now,
        ]);

        $bChild = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0003', 'user_id' => $guardianUser->id, 'child_id' => $childAlyaId,
            'schedule_id' => $schSwimKids, 'booking_type' => 'per_session', 'gross_amount' => 135000, 'discount_amount' => 0,
            'final_amount' => 135000, 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $qrCode3 = 'FS-QR-8823';
        DB::table('attendance_qr_codes')->insert([
            'booking_id' => $bChild, 'token_hash' => hash_hmac('sha256', $qrCode3, $qrSecret),
            'display_code' => $qrCode3, 'expires_at' => $now->copy()->addHours(32), 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Historical Attended Bookings across Member & Both Guardians + Upcoming Rafa Booking
        $bHistPilates = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0004', 'user_id' => $memberUser->id, 'schedule_id' => $schPastPilates1,
            'membership_id' => $memId, 'booking_type' => 'membership', 'gross_amount' => 185000, 'discount_amount' => 0,
            'final_amount' => 0, 'status' => 'attended', 'created_at' => $now->copy()->subDays(2), 'updated_at' => $now->copy()->subDays(1),
        ]);
        $bHistBoxing = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0005', 'user_id' => $memberUser->id, 'schedule_id' => $schPastBoxing1,
            'booking_type' => 'per_session', 'gross_amount' => 150000, 'discount_amount' => 0,
            'final_amount' => 150000, 'status' => 'attended', 'created_at' => $now->copy()->subDays(3), 'updated_at' => $now->copy()->subDays(2),
        ]);
        $bHistZumba = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0006', 'user_id' => $memberUser->id, 'schedule_id' => $schPastZumba1,
            'coupon_id' => $coupon15Id, 'booking_type' => 'per_session', 'gross_amount' => 95000, 'discount_amount' => 14250,
            'final_amount' => 80750, 'status' => 'attended', 'created_at' => $now->copy()->subDays(7), 'updated_at' => $now->copy()->subDays(6),
        ]);
        $bHistAlyaSwim = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0007', 'user_id' => $guardianUser->id, 'child_id' => $childAlyaId,
            'schedule_id' => $schPastSwim1, 'membership_id' => $memGuardianBudiId, 'booking_type' => 'membership',
            'gross_amount' => 135000, 'discount_amount' => 0, 'final_amount' => 0, 'status' => 'attended',
            'created_at' => $now->copy()->subDays(4), 'updated_at' => $now->copy()->subDays(3),
        ]);
        $bHistRafaTaek = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0008', 'user_id' => $guardianUser->id, 'child_id' => $childRafaId,
            'schedule_id' => $schPastTaekwondo1, 'booking_type' => 'per_session', 'gross_amount' => 120000, 'discount_amount' => 0,
            'final_amount' => 120000, 'status' => 'attended', 'created_at' => $now->copy()->subDays(5), 'updated_at' => $now->copy()->subDays(4),
        ]);
        $bHistNadiaSwim = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0009', 'user_id' => $otherGuardianUser->id, 'child_id' => $childNadiaId,
            'schedule_id' => $schPastSwim1, 'coupon_id' => $couponFamilyId, 'booking_type' => 'per_session',
            'gross_amount' => 135000, 'discount_amount' => 27000, 'final_amount' => 108000, 'status' => 'attended',
            'created_at' => $now->copy()->subDays(4), 'updated_at' => $now->copy()->subDays(3),
        ]);
        $bUpcomingRafa = DB::table('bookings')->insertGetId([
            'booking_code' => 'FS-BK-2026-0010', 'user_id' => $guardianUser->id, 'child_id' => $childRafaId,
            'schedule_id' => $schTaekwondo, 'membership_id' => $memGuardianBudiId, 'booking_type' => 'membership',
            'gross_amount' => 120000, 'discount_amount' => 0, 'final_amount' => 0, 'status' => 'confirmed',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $qrCode10 = 'FS-QR-8830';
        DB::table('attendance_qr_codes')->insert([
            'booking_id' => $bUpcomingRafa, 'token_hash' => hash_hmac('sha256', $qrCode10, $qrSecret),
            'display_code' => $qrCode10, 'expires_at' => $now->copy()->addHours(74), 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Coupon Usages
        DB::table('coupon_usages')->insert([
            ['coupon_id' => $coupon15Id, 'user_id' => $memberUser->id, 'booking_id' => $bHistZumba, 'discount_applied' => 14250, 'created_at' => $now->copy()->subDays(6), 'updated_at' => $now->copy()->subDays(6)],
            ['coupon_id' => $couponFamilyId, 'user_id' => $otherGuardianUser->id, 'booking_id' => $bHistNadiaSwim, 'discount_applied' => 27000, 'created_at' => $now->copy()->subDays(3), 'updated_at' => $now->copy()->subDays(3)],
        ]);

        // Attendance Records across ALL 3 Coaches, Member & Both Guardians
        DB::table('attendance')->insert([
            [
                'booking_id' => $bHistPilates, 'schedule_id' => $schPastPilates1, 'user_id' => $memberUser->id, 'child_id' => null,
                'recorded_by' => $coachNadiaUser->id, 'status' => 'present', 'checked_in_at' => $now->copy()->subDays(1)->setTime(8, 52),
                'notes' => 'Hadir tepat waktu via QR Studio A; aktivasi core sangat fokus.', 'created_at' => $now->copy()->subDays(1), 'updated_at' => $now->copy()->subDays(1),
            ],
            [
                'booking_id' => $bHistBoxing, 'schedule_id' => $schPastBoxing1, 'user_id' => $memberUser->id, 'child_id' => null,
                'recorded_by' => $coachDimasUser->id, 'status' => 'present', 'checked_in_at' => $now->copy()->subDays(2)->setTime(16, 55),
                'notes' => 'Menyelesaikan 6 ronde pad work & conditioning penuh.', 'created_at' => $now->copy()->subDays(2), 'updated_at' => $now->copy()->subDays(2),
            ],
            [
                'booking_id' => $bHistZumba, 'schedule_id' => $schPastZumba1, 'user_id' => $memberUser->id, 'child_id' => null,
                'recorded_by' => $coachDimasUser->id, 'status' => 'late', 'checked_in_at' => $now->copy()->subDays(6)->setTime(18, 36),
                'notes' => 'Check-in terlambat 6 menit, mengikuti seluruh sesi inti hingga pendinginan.', 'created_at' => $now->copy()->subDays(6), 'updated_at' => $now->copy()->subDays(6),
            ],
            [
                'booking_id' => $bHistAlyaSwim, 'schedule_id' => $schPastSwim1, 'user_id' => $guardianUser->id, 'child_id' => $childAlyaId,
                'recorded_by' => $coachRakaUser->id, 'status' => 'present', 'checked_in_at' => $now->copy()->subDays(3)->setTime(15, 22),
                'notes' => 'Didampingi Ayah (Budi Santoso); percaya diri di kolam kedalaman 1m.', 'created_at' => $now->copy()->subDays(3), 'updated_at' => $now->copy()->subDays(3),
            ],
            [
                'booking_id' => $bHistRafaTaek, 'schedule_id' => $schPastTaekwondo1, 'user_id' => $guardianUser->id, 'child_id' => $childRafaId,
                'recorded_by' => $coachRakaUser->id, 'status' => 'present', 'checked_in_at' => $now->copy()->subDays(4)->setTime(15, 50),
                'notes' => 'Mengenakan dobok lengkap; sangat disiplin pada sesi kuda-kuda & tendangan.', 'created_at' => $now->copy()->subDays(4), 'updated_at' => $now->copy()->subDays(4),
            ],
            [
                'booking_id' => $bHistNadiaSwim, 'schedule_id' => $schPastSwim1, 'user_id' => $otherGuardianUser->id, 'child_id' => $childNadiaId,
                'recorded_by' => $coachRakaUser->id, 'status' => 'present', 'checked_in_at' => $now->copy()->subDays(3)->setTime(15, 25),
                'notes' => 'Berhasil mengapung telentang 20 detik dengan stabil.', 'created_at' => $now->copy()->subDays(3), 'updated_at' => $now->copy()->subDays(3),
            ],
        ]);

        // Payments & Finance (Daily, Weekly & Monthly PAID counted; INV-2026-0942 stays PENDING for Xendit test)
        $payMem = DB::table('payments')->insertGetId([
            'order_id' => 'INV-2026-0941', 'user_id' => $memberUser->id, 'branch_id' => $jakselId,
            'membership_id' => $memId, 'gateway' => 'midtrans', 'payment_method' => 'qris', 'amount' => 890000,
            'status' => 'paid', 'paid_at' => $now->copy()->subDays(5), 'created_at' => $now->copy()->subDays(5), 'updated_at' => $now->copy()->subDays(5),
        ]);
        $payChild = DB::table('payments')->insertGetId([
            'order_id' => 'INV-2026-0940', 'user_id' => $guardianUser->id, 'branch_id' => $jakselId,
            'booking_id' => $bChild, 'gateway' => 'xendit', 'payment_method' => 'va_bca', 'amount' => 135000,
            'status' => 'paid', 'paid_at' => $now->copy()->subDays(2), 'created_at' => $now->copy()->subDays(2), 'updated_at' => $now->copy()->subDays(2),
        ]);
        DB::table('payments')->insert([
            'order_id' => 'INV-2026-0942', 'user_id' => $memberUser->id, 'branch_id' => $jakselId,
            'booking_id' => $bMemberNear, 'gateway' => 'midtrans', 'payment_method' => 'qris', 'amount' => 110000,
            'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);
        // Today (Daily) + Weekly + Monthly Verified Paid Payments
        $payTodayMemBudi = DB::table('payments')->insertGetId([
            'order_id' => 'INV-2026-0951', 'user_id' => $guardianUser->id, 'branch_id' => $jakselId,
            'membership_id' => $memGuardianBudiId, 'gateway' => 'midtrans', 'payment_method' => 'qris', 'amount' => 1150000,
            'status' => 'paid', 'paid_at' => $now->copy()->subHours(4), 'created_at' => $now->copy()->subHours(4), 'updated_at' => $now->copy()->subHours(4),
        ]);
        $payTodayBoxing = DB::table('payments')->insertGetId([
            'order_id' => 'INV-2026-0952', 'user_id' => $memberUser->id, 'branch_id' => $jakselId,
            'booking_id' => $bHistBoxing, 'gateway' => 'xendit', 'payment_method' => 'va_bca', 'amount' => 150000,
            'status' => 'paid', 'paid_at' => $now->copy()->subHours(2), 'created_at' => $now->copy()->subHours(2), 'updated_at' => $now->copy()->subHours(2),
        ]);
        $payTodayMemSari = DB::table('payments')->insertGetId([
            'order_id' => 'INV-2026-0953', 'user_id' => $otherGuardianUser->id, 'branch_id' => $bdgId,
            'membership_id' => $memGuardianSariId, 'gateway' => 'midtrans', 'payment_method' => 'gopay', 'amount' => 625000,
            'status' => 'paid', 'paid_at' => $now->copy()->subHours(1), 'created_at' => $now->copy()->subHours(1), 'updated_at' => $now->copy()->subHours(1),
        ]);
        $payWeekRafa = DB::table('payments')->insertGetId([
            'order_id' => 'INV-2026-0948', 'user_id' => $guardianUser->id, 'branch_id' => $bdgId,
            'booking_id' => $bHistRafaTaek, 'gateway' => 'midtrans', 'payment_method' => 'qris', 'amount' => 120000,
            'status' => 'paid', 'paid_at' => $now->copy()->subDays(4), 'created_at' => $now->copy()->subDays(4), 'updated_at' => $now->copy()->subDays(4),
        ]);
        $payWeekNadia = DB::table('payments')->insertGetId([
            'order_id' => 'INV-2026-0947', 'user_id' => $otherGuardianUser->id, 'branch_id' => $jakselId,
            'booking_id' => $bHistNadiaSwim, 'gateway' => 'xendit', 'payment_method' => 'va_mandiri', 'amount' => 108000,
            'status' => 'paid', 'paid_at' => $now->copy()->subDays(3), 'created_at' => $now->copy()->subDays(3), 'updated_at' => $now->copy()->subDays(3),
        ]);
        $payWeekZumba = DB::table('payments')->insertGetId([
            'order_id' => 'INV-2026-0946', 'user_id' => $memberUser->id, 'branch_id' => $jakselId,
            'booking_id' => $bHistZumba, 'gateway' => 'midtrans', 'payment_method' => 'qris', 'amount' => 80750,
            'status' => 'paid', 'paid_at' => $now->copy()->subDays(6), 'created_at' => $now->copy()->subDays(6), 'updated_at' => $now->copy()->subDays(6),
        ]);
        $payMonthYoga = DB::table('payments')->insertGetId([
            'order_id' => 'INV-2026-0935', 'user_id' => $memberUser->id, 'branch_id' => $bdgId,
            'gateway' => 'xendit', 'payment_method' => 'va_bni', 'amount' => 110000,
            'status' => 'paid', 'paid_at' => $now->copy()->subDays(14), 'created_at' => $now->copy()->subDays(14), 'updated_at' => $now->copy()->subDays(14),
        ]);

        DB::table('financial_transactions')->insert([
            ['branch_id' => $bdgId, 'payment_id' => $payTodayMemSari, 'direction' => 'income', 'category' => 'membership', 'amount' => 625000, 'description' => 'Aktivasi Starter 5 Sesi (Sari Wulandari)', 'transacted_at' => $now->copy()->subHours(1), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $jakselId, 'payment_id' => $payTodayBoxing, 'direction' => 'income', 'category' => 'booking_session', 'amount' => 150000, 'description' => 'Booking Per Sesi Boxing Conditioning (Dina Rahma)', 'transacted_at' => $now->copy()->subHours(2), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $jakselId, 'payment_id' => $payTodayMemBudi, 'direction' => 'income', 'category' => 'membership', 'amount' => 1150000, 'description' => 'Pembelian Paket 10 Sesi Fleksibel (Budi Santoso)', 'transacted_at' => $now->copy()->subHours(4), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $jakselId, 'payment_id' => $payChild, 'direction' => 'income', 'category' => 'booking_session', 'amount' => 135000, 'description' => 'Booking Swimming Kids Foundation (Alya Putri)', 'transacted_at' => $now->copy()->subDays(2), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $jakselId, 'payment_id' => $payWeekNadia, 'direction' => 'income', 'category' => 'booking_session', 'amount' => 108000, 'description' => 'Booking Swimming Kids (Nadia Kirana — Voucher KELUARGA20)', 'transacted_at' => $now->copy()->subDays(3), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $bdgId, 'payment_id' => $payWeekRafa, 'direction' => 'income', 'category' => 'booking_session', 'amount' => 120000, 'description' => 'Booking Taekwondo Kids Discipline (Rafa Pratama)', 'transacted_at' => $now->copy()->subDays(4), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $jakselId, 'payment_id' => $payMem, 'direction' => 'income', 'category' => 'membership', 'amount' => 890000, 'description' => 'Pembelian Membership Bulanan Pro (Dina Rahma)', 'transacted_at' => $now->copy()->subDays(5), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $jakselId, 'payment_id' => $payWeekZumba, 'direction' => 'income', 'category' => 'booking_session', 'amount' => 80750, 'description' => 'Booking Zumba Regular Cardio (Dina Rahma — Voucher FITSPACE15)', 'transacted_at' => $now->copy()->subDays(6), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $bdgId, 'payment_id' => $payMonthYoga, 'direction' => 'income', 'category' => 'booking_session', 'amount' => 110000, 'description' => 'Booking Vinyasa Flow & Mobility (Cabang Bandung)', 'transacted_at' => $now->copy()->subDays(14), 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Expense Categories & Realistic Operational Expenses across Branches
        $expHonor = DB::table('expense_categories')->insertGetId(['slug' => 'honor-coach', 'name' => 'Honor Coach', 'created_at' => $now, 'updated_at' => $now]);
        $expGaji = DB::table('expense_categories')->insertGetId(['slug' => 'gaji-karyawan', 'name' => 'Gaji Karyawan', 'created_at' => $now, 'updated_at' => $now]);
        $expFasilitas = DB::table('expense_categories')->insertGetId(['slug' => 'perawatan-fasilitas', 'name' => 'Perawatan Fasilitas', 'created_at' => $now, 'updated_at' => $now]);
        $expUtilitas = DB::table('expense_categories')->insertGetId(['slug' => 'listrik-air', 'name' => 'Listrik & Air', 'created_at' => $now, 'updated_at' => $now]);

        DB::table('expenses')->insert([
            ['branch_id' => $jakselId, 'expense_category_id' => $expHonor, 'recorded_by' => $adminUser->id, 'amount' => 250000, 'description' => 'Honor Sesi Coach Mingguan Studio Jakarta Selatan', 'incurred_on' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $jakselId, 'expense_category_id' => $expFasilitas, 'recorded_by' => $adminUser->id, 'amount' => 180000, 'description' => 'Kalibrasi pegas mesin Reformer & sanitasi matras', 'incurred_on' => $now->copy()->subDays(3)->toDateString(), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $bdgId, 'expense_category_id' => $expUtilitas, 'recorded_by' => $adminUser->id, 'amount' => 220000, 'description' => 'Utilitas listrik, AC studio & perawatan kolam anak', 'incurred_on' => $now->copy()->subDays(5)->toDateString(), 'created_at' => $now, 'updated_at' => $now],
            ['branch_id' => $jakselId, 'expense_category_id' => $expGaji, 'recorded_by' => $adminUser->id, 'amount' => 450000, 'description' => 'Insentif Front Desk & Operasional Bulanan', 'incurred_on' => $now->copy()->subDays(12)->toDateString(), 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Progress Reports & Detailed Indicator Scores across ALL 3 Coaches, Member & Guardians
        $childReportId = DB::table('progress_reports')->insertGetId([
            'booking_id' => $bChild, 'schedule_id' => $schSwimKids, 'class_id' => $clsSwimKids,
            'coach_id' => $coachRakaId, 'user_id' => $guardianUser->id, 'child_id' => $childAlyaId,
            'material_studied' => 'Floating Technique & Breathing Control', 'average_score' => 3.67,
            'progress_notes' => 'Alya sangat percaya diri mengatur napas di dalam air dan stabil saat mengapung.',
            'improvement_areas' => 'Koordinasi kayuhan lengan gaya bebas jarak 15 meter.',
            'next_recommendation' => 'Latihan meluncur dengan papan kickboard 2 sesi berikutnya.',
            'evaluated_at' => $now->toDateString(), 'is_published' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('progress_details')->insert([
            ['progress_report_id' => $childReportId, 'indicator_name' => 'Teknik Pernapasan', 'score' => 4, 'score_label' => 'Sangat Baik', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $childReportId, 'indicator_name' => 'Mengapung', 'score' => 4, 'score_label' => 'Sangat Baik', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $childReportId, 'indicator_name' => 'Gerakan Renang', 'score' => 3, 'score_label' => 'Berkembang Sesuai Target', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $repDinaPilates = DB::table('progress_reports')->insertGetId([
            'booking_id' => $bHistPilates, 'schedule_id' => $schPastPilates1, 'class_id' => $clsPilates,
            'coach_id' => $coachNadiaId, 'user_id' => $memberUser->id, 'child_id' => null,
            'material_studied' => 'Core Stability Progression & Neutral Spine', 'average_score' => 3.67,
            'progress_notes' => 'Kontrol otot inti Dina meningkat signifikan; postur tulang belakang tetap netral selama gerakan footwork.',
            'improvement_areas' => 'Fleksibilitas hamstring pada gerakan leg circles.',
            'next_recommendation' => 'Naikkan resistensi pegas Reformer ke level 2 pada sesi berikutnya.',
            'evaluated_at' => $now->copy()->subDays(1)->toDateString(), 'is_published' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('progress_details')->insert([
            ['progress_report_id' => $repDinaPilates, 'indicator_name' => 'Postur Tubuh', 'score' => 4, 'score_label' => 'Sangat Baik', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $repDinaPilates, 'indicator_name' => 'Keseimbangan', 'score' => 4, 'score_label' => 'Sangat Baik', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $repDinaPilates, 'indicator_name' => 'Fleksibilitas', 'score' => 3, 'score_label' => 'Berkembang Sesuai Target', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $repDinaBoxing = DB::table('progress_reports')->insertGetId([
            'booking_id' => $bHistBoxing, 'schedule_id' => $schPastBoxing1, 'class_id' => $clsBoxing,
            'coach_id' => $coachDimasId, 'user_id' => $memberUser->id, 'child_id' => null,
            'material_studied' => 'Jab-Cross-Hook Combo & Defensive Footwork', 'average_score' => 3.33,
            'progress_notes' => 'Akurasi jab-cross pada target pad sangat tajam dengan ritme napas stabil selama 6 ronde.',
            'improvement_areas' => 'Kecepatan pivot kaki saat keluar dari sudut ring.',
            'next_recommendation' => 'Latihan shadow boxing 3x3 menit fokus footwork lateral.',
            'evaluated_at' => $now->copy()->subDays(2)->toDateString(), 'is_published' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('progress_details')->insert([
            ['progress_report_id' => $repDinaBoxing, 'indicator_name' => 'Teknik Pukulan', 'score' => 4, 'score_label' => 'Sangat Baik', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $repDinaBoxing, 'indicator_name' => 'Footwork', 'score' => 3, 'score_label' => 'Berkembang Sesuai Target', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $repDinaBoxing, 'indicator_name' => 'Koordinasi', 'score' => 3, 'score_label' => 'Berkembang Sesuai Target', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $repRafaTaek = DB::table('progress_reports')->insertGetId([
            'booking_id' => $bHistRafaTaek, 'schedule_id' => $schPastTaekwondo1, 'class_id' => $clsTaekwondo,
            'coach_id' => $coachRakaId, 'user_id' => $guardianUser->id, 'child_id' => $childRafaId,
            'material_studied' => 'Basic Stance (Ap Kubi) & Ap Chagi Kick', 'average_score' => 3.75,
            'progress_notes' => 'Rafa menunjukkan kuda-kuda yang kokoh, tendangan lurus tepat sasaran, dan sikap disiplin tinggi.',
            'improvement_areas' => 'Kecepatan tangkisan bawah (Arae Makgi) saat berpindah arah.',
            'next_recommendation' => 'Siap mengikuti uji kenaikan tingkat sabuk kuning periode bulan depan.',
            'evaluated_at' => $now->copy()->subDays(4)->toDateString(), 'is_published' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('progress_details')->insert([
            ['progress_report_id' => $repRafaTaek, 'indicator_name' => 'Kuda-kuda', 'score' => 4, 'score_label' => 'Sangat Baik', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $repRafaTaek, 'indicator_name' => 'Tendangan', 'score' => 4, 'score_label' => 'Sangat Baik', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $repRafaTaek, 'indicator_name' => 'Pukulan', 'score' => 4, 'score_label' => 'Sangat Baik', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $repRafaTaek, 'indicator_name' => 'Pertahanan', 'score' => 3, 'score_label' => 'Berkembang Sesuai Target', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $repNadiaSwim = DB::table('progress_reports')->insertGetId([
            'booking_id' => $bHistNadiaSwim, 'schedule_id' => $schPastSwim1, 'class_id' => $clsSwimKids,
            'coach_id' => $coachRakaId, 'user_id' => $otherGuardianUser->id, 'child_id' => $childNadiaId,
            'material_studied' => 'Back Float & Flutter Kick Foundation', 'average_score' => 3.33,
            'progress_notes' => 'Nadia sudah nyaman meluncur dan mampu mengapung telentang mandiri selama 20 detik.',
            'improvement_areas' => 'Konsistensi irama tendangan kaki flutter kick.',
            'next_recommendation' => 'Latihan koordinasi pernapasan samping pada sesi berikutnya.',
            'evaluated_at' => $now->copy()->subDays(3)->toDateString(), 'is_published' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('progress_details')->insert([
            ['progress_report_id' => $repNadiaSwim, 'indicator_name' => 'Teknik Pernapasan', 'score' => 3, 'score_label' => 'Berkembang Sesuai Target', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $repNadiaSwim, 'indicator_name' => 'Mengapung', 'score' => 4, 'score_label' => 'Sangat Baik', 'created_at' => $now, 'updated_at' => $now],
            ['progress_report_id' => $repNadiaSwim, 'indicator_name' => 'Gerakan Renang', 'score' => 3, 'score_label' => 'Berkembang Sesuai Target', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Authentic Coach Reviews across ALL 3 Coaches, Member & Both Guardians
        DB::table('coach_reviews')->insert([
            [
                'booking_id' => $bHistPilates, 'user_id' => $memberUser->id, 'coach_id' => $coachNadiaId,
                'class_id' => $clsPilates, 'schedule_id' => $schPastPilates1, 'rating' => 5,
                'review' => 'Instruksi pernapasan dan koreksi postur Coach Nadia sangat detail, sabar, dan membuat punggung jauh lebih rileks.',
                'reviewed_at' => $now->copy()->subHours(18), 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'booking_id' => $bHistBoxing, 'user_id' => $memberUser->id, 'coach_id' => $coachDimasId,
                'class_id' => $clsBoxing, 'schedule_id' => $schPastBoxing1, 'rating' => 5,
                'review' => 'Sesi pad work sangat terstruktur, energi studio luar biasa, dan footwork saya meningkat pesat dalam 3 pertemuan.',
                'reviewed_at' => $now->copy()->subDays(1), 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'booking_id' => $bHistZumba, 'user_id' => $memberUser->id, 'coach_id' => $coachDimasId,
                'class_id' => $clsZumba, 'schedule_id' => $schPastZumba1, 'rating' => 4,
                'review' => 'Koreografi seru dan musik energik, sangat membakar kalori; ruang Hall 2 nyaman, bersih, dan sejuk.',
                'reviewed_at' => $now->copy()->subDays(5), 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'booking_id' => $bHistAlyaSwim, 'user_id' => $guardianUser->id, 'coach_id' => $coachRakaId,
                'class_id' => $clsSwimKids, 'schedule_id' => $schPastSwim1, 'rating' => 5,
                'review' => 'Alya yang awalnya takut air sekarang sudah berani mengapung mandiri. Coach Raka sangat ramah anak dan komunikatif.',
                'reviewed_at' => $now->copy()->subDays(2), 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'booking_id' => $bHistRafaTaek, 'user_id' => $guardianUser->id, 'coach_id' => $coachRakaId,
                'class_id' => $clsTaekwondo, 'schedule_id' => $schPastTaekwondo1, 'rating' => 5,
                'review' => 'Rafa makin disiplin dan bersemangat latihan setiap pekan. Laporan perkembangan PDF & Word sangat membantu orang tua.',
                'reviewed_at' => $now->copy()->subDays(3), 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'booking_id' => $bHistNadiaSwim, 'user_id' => $otherGuardianUser->id, 'coach_id' => $coachRakaId,
                'class_id' => $clsSwimKids, 'schedule_id' => $schPastSwim1, 'rating' => 4,
                'review' => 'Pendampingan kolam anak sangat aman, rasio pelatih dan anak ideal, serta fasilitas Pool B terawat rapi.',
                'reviewed_at' => $now->copy()->subDays(2), 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        // System Settings
        foreach ([
            ['reschedule_cutoff_hours', '8', 'policy'],
            ['max_reschedule_count', '1', 'policy'],
            ['cancel_refund_policy', 'quota_or_refund', 'policy'],
            ['brand_name', 'FitSpace', 'general'],
        ] as [$k, $v, $g]) {
            DB::table('settings')->insert(['key' => $k, 'value' => $v, 'group_name' => $g, 'updated_by' => $adminUser->id, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Sample Pending Coach Schedule Reschedule Request (waiting Admin ACC -> auto-forward to Member/Orang Tua)
        DB::table('coach_schedule_reschedules')->insert([
            'schedule_id' => $schPilatesMain,
            'coach_id' => $coachNadiaId,
            'original_starts_at' => $now->copy()->addHours(26),
            'original_ends_at' => $now->copy()->addHours(27),
            'proposed_starts_at' => $now->copy()->addHours(29),
            'proposed_ends_at' => $now->copy()->addHours(30),
            'reason' => 'Berhalangan hadir pada jam semula karena agenda workshop sertifikasi STOTT Pilates; mohon digeser 3 jam kemudian.',
            'status' => 'pending',
            'created_at' => $now->copy()->subHours(2),
            'updated_at' => $now->copy()->subHours(2),
        ]);

        // Sample Admin-Approved Schedule Reschedule Notifications for Member & Guardian
        DB::table('notifications')->insert([
            [
                'user_id' => $memberUser->id,
                'type' => 'coach_schedule_rescheduled',
                'title' => 'Perubahan Jadwal Boxing Conditioning Disetujui Admin',
                'body' => 'Jadwal Boxing Conditioning bersama Coach Dimas Pratama telah disesuaikan atas persetujuan Admin. Tiket QR Anda tetap berlaku otomatis.',
                'data' => json_encode(['schedule_id' => $schBoxing]),
                'created_at' => $now->copy()->subHours(6),
                'updated_at' => $now->copy()->subHours(6),
            ],
            [
                'user_id' => $guardianUser->id,
                'type' => 'coach_schedule_rescheduled',
                'title' => 'Perubahan Jadwal Swimming Kids Disetujui Admin',
                'body' => 'Jadwal Swimming Kids Foundation (Alya Putri) bersama Coach Raka Wijaya telah disinkronkan oleh Admin. Tiket QR anak tetap aktif otomatis.',
                'data' => json_encode(['schedule_id' => $schSwimKids]),
                'created_at' => $now->copy()->subHours(5),
                'updated_at' => $now->copy()->subHours(5),
            ],
        ]);

        // Initial Audit Log
        DB::table('audit_logs')->insert([
            'user_id' => $adminUser->id, 'action' => 'system.seeded', 'entity_type' => 'system',
            'entity_id' => 1, 'metadata' => json_encode(['branches' => 2, 'classes' => 6]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}

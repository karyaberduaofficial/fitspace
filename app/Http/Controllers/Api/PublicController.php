<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
    public static function clearBootstrapCache(): void
    {
        try {
            Cache::increment('fitspace_bootstrap_ver');
        } catch (\Throwable) {
            Cache::put('fitspace_bootstrap_ver', (int) Cache::get('fitspace_bootstrap_ver', 1) + 1, 3600);
        }
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');
        $branchFilter = is_numeric($branchId) ? (int) $branchId : null;
        $ver = (int) Cache::get('fitspace_bootstrap_ver', 1);
        $cacheKey = "fitspace_bootstrap_v{$ver}_b".($branchFilter ?? 'all');

        $payload = Cache::remember($cacheKey, 15, function () use ($branchFilter) {
            $branches = DB::table('branches')->where('is_active', true)->orderBy('id')->get();
            $categories = DB::table('class_categories')->where('is_active', true)->orderBy('id')->get();
            $levels = DB::table('class_levels')->orderBy('sort_order')->get();

            $classes = DB::table('classes as c')
                ->join('class_categories as cat', 'cat.id', '=', 'c.category_id')
                ->join('class_levels as lvl', 'lvl.id', '=', 'c.level_id')
                ->select(
                    'c.*',
                    'cat.name as category_name',
                    'cat.slug as category_slug',
                    'cat.color_token',
                    'lvl.name as level_name'
                )
                ->where('c.is_active', true)
                ->orderBy('c.id')
                ->get();

            $allIndicators = DB::table('class_evaluation_indicators')
                ->orderBy('sort_order')
                ->get()
                ->groupBy('class_id');

            $allMaterials = DB::table('class_materials')
                ->orderBy('sequence_order')
                ->get()
                ->groupBy('class_id');

            $classes = $classes->map(function ($c) use ($allIndicators, $allMaterials) {
                $c->indicators = ($allIndicators->get($c->id) ?? collect())->values()->all();
                $c->materials = ($allMaterials->get($c->id) ?? collect())->values()->all();

                return $c;
            });

            $schedules = DB::table('class_schedules as s')
                ->join('classes as c', 'c.id', '=', 's.class_id')
                ->join('branches as b', 'b.id', '=', 's.branch_id')
                ->join('rooms as r', 'r.id', '=', 's.room_id')
                ->join('coaches as co', 'co.id', '=', 's.coach_id')
                ->join('users as u', 'u.id', '=', 'co.user_id')
                ->select(
                    's.id', 's.class_id', 's.branch_id', 's.room_id', 's.coach_id',
                    's.starts_at', 's.ends_at', 's.capacity', 's.booked_count', 's.status',
                    'c.name as class_name', 'c.price_per_session', 'c.min_age', 'c.max_age',
                    'b.name as branch_name', 'r.name as room_name', 'u.name as coach_name'
                )
                ->where('s.status', 'scheduled')
                ->where('s.starts_at', '>', now())
                ->when($branchFilter, fn ($q) => $q->where('s.branch_id', $branchFilter))
                ->orderBy('s.starts_at')
                ->get();

            $plans = DB::table('membership_plans')
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            return [
                'demo_mode' => (bool) config('fitspace.demo_mode', false),
                'google_oauth_enabled' => ! empty(config('fitspace.google.client_id')) && ! empty(config('fitspace.google.client_secret')),
                'branches' => $branches,
                'categories' => $categories,
                'levels' => $levels,
                'classes' => $classes,
                'schedules' => $schedules,
                'membership_plans' => $plans,
            ];
        });

        return response()->json($payload);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $dbOk = true;
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        return response()->json([
            'status' => $dbOk ? 'ok' : 'degraded',
            'app' => 'FitSpace PWA',
            'tier' => 'corporate',
            'timestamp' => now()->toIso8601String(),
        ], $dbOk ? 200 : 503);
    }

    public function spa(): Response
    {
        $indexPath = public_path('index.html');
        $html = is_file($indexPath) ? (string) file_get_contents($indexPath) : '<h1>FitSpace PWA</h1>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}

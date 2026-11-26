<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportExportService $reports,
    ) {}

    public function export(Request $request): Response
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:60'],
            'format' => ['required', 'in:pdf,excel,word'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return $this->reports->export(
            $request->user(),
            $validated['type'],
            $validated['format'],
            ['branch_id' => $validated['branch_id'] ?? null]
        );
    }
}

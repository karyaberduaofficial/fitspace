<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayService $payments,
    ) {}

    public function midtrans(Request $request): JsonResponse
    {
        $result = $this->payments->handleMidtransWebhook($request->all());

        return response()->json([
            'message' => 'Webhook Midtrans diverifikasi dan diproses.',
            'payment_status' => $result['payment']->status,
            'qr_issued' => $result['qr'] !== null,
        ]);
    }

    public function xendit(Request $request): JsonResponse
    {
        $callbackToken = (string) $request->header('X-CALLBACK-TOKEN', '');
        $result = $this->payments->handleXenditWebhook($request->all(), $callbackToken);

        return response()->json([
            'message' => 'Webhook Xendit diverifikasi dan diproses.',
            'payment_status' => $result['payment']->status,
            'qr_issued' => $result['qr'] !== null,
        ]);
    }
}

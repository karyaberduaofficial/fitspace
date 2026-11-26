<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditService
{
    public function log(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $metadata = [],
        ?Request $request = null
    ): void {
        $req = $request ?? (app()->bound('request') ? request() : null);

        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $req?->ip(),
            'user_agent' => $req ? substr((string) $req->userAgent(), 0, 250) : null,
            'metadata' => json_encode($this->redactSensitive($metadata)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function notify(int $userId, string $type, string $title, string $body, array $data = []): void
    {
        DB::table('notifications')->insert([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => json_encode($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function redactSensitive(array $payload): array
    {
        $sensitiveKeys = [
            'password', 'password_confirmation', 'server_key', 'secret', 'secret_key',
            'signature_key', 'token', 'callback_token', 'authorization', 'api_key',
            'card_number', 'cvv', 'pin', 'private_key',
        ];

        $clean = [];
        foreach ($payload as $k => $v) {
            $lower = strtolower((string) $k);
            $isSensitive = false;
            foreach ($sensitiveKeys as $bad) {
                if (str_contains($lower, $bad)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $clean[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $clean[$k] = $this->redactSensitive($v);
            } else {
                $clean[$k] = $v;
            }
        }

        return $clean;
    }
}

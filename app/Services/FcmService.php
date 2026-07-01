<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private array $serviceAccount;
    private string $projectId;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct()
    {
        $raw = config('services.firebase.credentials');
        if (empty($raw)) {
            throw new \RuntimeException('FIREBASE_CREDENTIALS env var is not set.');
        }

        // Accept both raw JSON and base64-encoded JSON
        $decoded = base64_decode($raw, true);
        $json = ($decoded && str_contains($decoded, 'private_key')) ? $decoded : $raw;

        $this->serviceAccount = json_decode($json, true)
            ?? throw new \RuntimeException('FIREBASE_CREDENTIALS is not valid JSON.');

        $this->projectId = $this->serviceAccount['project_id']
            ?? throw new \RuntimeException('FIREBASE_CREDENTIALS missing project_id.');
    }

    /**
     * Send a push notification to a single FCM token.
     */
    public function sendToToken(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        try {
            $token = $this->getAccessToken();

            $response = Http::withToken($token)
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", [
                    'message' => [
                        'token' => $fcmToken,
                        'notification' => [
                            'title' => $title,
                            'body'  => $body,
                        ],
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'channel_id'              => 'avaroa_jobs',
                                'priority'                => 'max',
                                'default_vibrate_timings' => true,
                                'default_sound'           => true,
                            ],
                        ],
                        'data' => array_map('strval', $data),
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('FCM send failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'token'  => substr($fcmToken, 0, 20) . '...',
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('FCM exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Send to multiple FCM tokens (best-effort, ignores individual failures).
     */
    public function sendToMany(array $fcmTokens, string $title, string $body, array $data = []): int
    {
        $sent = 0;
        foreach ($fcmTokens as $token) {
            if (!empty($token) && $this->sendToToken($token, $title, $body, $data)) {
                $sent++;
            }
        }
        return $sent;
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        if ($this->accessToken && time() < $this->tokenExpiresAt - 60) {
            return $this->accessToken;
        }

        $now = time();
        $jwt = $this->buildJwt($now);

        $response = Http::asForm()
            ->timeout(10)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('FCM OAuth2 failed: ' . $response->body());
        }

        $data = $response->json();
        $this->accessToken  = $data['access_token'];
        $this->tokenExpiresAt = $now + ($data['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    private function buildJwt(int $now): string
    {
        $header  = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->b64url(json_encode([
            'iss'   => $this->serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signingInput = "{$header}.{$payload}";
        $key = openssl_pkey_get_private($this->serviceAccount['private_key']);

        openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

        return "{$signingInput}." . $this->b64url($signature);
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

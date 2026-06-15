<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FcmV1Service
{
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        if (! config('services.firebase.enabled')) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'firebase_disabled',
                'topic' => $topic,
            ];
        }

        try {
            $projectId = $this->projectId();
            $response = Http::timeout((int) config('services.firebase.timeout', 15))
                ->withToken($this->accessToken())
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'topic' => $topic,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $this->stringifyData([
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                            ...$data,
                        ]),
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('FCM topic send failed.', [
                    'topic' => $topic,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return [
                'sent' => $response->successful(),
                'topic' => $topic,
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::warning('FCM topic send exception.', [
                'topic' => $topic,
                'message' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'topic' => $topic,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        $now = time();

        $jwt = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ])).'.'.$this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'] ?? null,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $privateKey = $credentials['private_key'] ?? null;

        if (! $privateKey || ! openssl_sign($jwt, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign Firebase service-account JWT.');
        }

        $assertion = $jwt.'.'.$this->base64UrlEncode($signature);

        $response = Http::asForm()
            ->timeout((int) config('services.firebase.timeout', 15))
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('Unable to fetch Firebase access token: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    private function projectId(): string
    {
        $projectId = config('services.firebase.project_id') ?: ($this->credentials()['project_id'] ?? null);

        if (! $projectId) {
            throw new RuntimeException('FIREBASE_PROJECT_ID is required.');
        }

        return (string) $projectId;
    }

    private function credentials(): array
    {
        $json = config('services.firebase.credentials_json');
        $base64 = config('services.firebase.credentials_base64');
        $path = config('services.firebase.credentials');

        if ($json) {
            return $this->decodeCredentials($json);
        }

        if ($base64) {
            $decoded = base64_decode((string) $base64, true);

            if ($decoded === false) {
                throw new RuntimeException('FIREBASE_CREDENTIALS_BASE64 could not be decoded.');
            }

            return $this->decodeCredentials($decoded);
        }

        $path = $path ?: storage_path('app/firebase/firebase_credentials.json');
        $path = $this->normalizePath((string) $path);

        if (! is_file($path)) {
            throw new RuntimeException('Firebase credentials file not found: '.$path);
        }

        return $this->decodeCredentials((string) file_get_contents($path));
    }

    private function decodeCredentials(string $json): array
    {
        $credentials = json_decode($json, true);

        if (! is_array($credentials)) {
            throw new RuntimeException('Firebase credentials JSON is invalid.');
        }

        return $credentials;
    }

    private function normalizePath(string $path): string
    {
        $isWindowsAbsolute = (bool) preg_match('/^[A-Za-z]:[\\\\\/]|^\\\\\\\\/', $path);

        if (str_starts_with($path, '/') || $isWindowsAbsolute) {
            return $path;
        }

        return base_path($path);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function stringifyData(array $data): array
    {
        return collect($data)
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE))
            ->all();
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MicrosoftGraphMailService
{
    private function config(string $key, $default = null)
    {
        return config("services.ms_graph.$key", $default);
    }

    private function tokenUrl(): string
    {
        $tenant = $this->config('tenant_id');
        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
    }

    private function graphBase(): string
    {
        return "https://graph.microsoft.com/v1.0";
    }

    /**
     * Client Credentials token (app-only).
     * Uses scope https://graph.microsoft.com/.default :contentReference[oaicite:2]{index=2}
     */
    public function getAccessToken(): string
    {
        return Cache::remember('ms_graph_access_token', 50 * 60, function () {
            $res = Http::asForm()->post($this->tokenUrl(), [
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ]);

            if (!$res->successful()) {
                throw new \RuntimeException("Graph token failed: " . $res->body());
            }

            $json = $res->json();
            if (empty($json['access_token'])) {
                throw new \RuntimeException("Graph token missing access_token: " . $res->body());
            }

            // cache TTL is handled by remember() above
            return $json['access_token'];
        });
    }

    /**
     * Send mail via Graph:
     * POST /users/{from}/sendMail :contentReference[oaicite:3]{index=3}
     */
    public function sendMail(string $to, string $subject, string $html, ?string $from = null): void
    {
        $from = $from ?: $this->config('from');
        if (!$from) {
            throw new \RuntimeException("MS_GRAPH_FROM is not configured.");
        }

        $token = $this->getAccessToken();

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $html,
                ],
                'toRecipients' => [
                    ['emailAddress' => ['address' => $to]],
                ],
            ],
            'saveToSentItems' => true,
        ];

        $url = $this->graphBase() . "/users/" . rawurlencode($from) . "/sendMail";

        $res = Http::withToken($token)
            ->acceptJson()
            ->post($url, $payload);

        // Graph sendMail returns 202 Accepted on success
        if ($res->status() !== 202) {
            throw new \RuntimeException("Graph sendMail failed ({$res->status()}): " . $res->body());
        }
    }
}

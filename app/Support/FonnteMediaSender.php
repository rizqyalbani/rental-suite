<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Sends media (image/PDF URL or upload) through Fonnte's HTTP API.
 */
final class FonnteMediaSender
{
    private static function fakeEnabled(): bool
    {
        $flag = config('services.fonnte.fake', false);
        if (is_string($flag)) {
            $flag = strtolower(trim($flag));
            $flag = in_array($flag, ['1', 'true', 'yes', 'on'], true);
        }
        $env = getenv('FONNTE_FAKE');
        if (is_string($env)) {
            $env = strtolower(trim($env));
            $env = in_array($env, ['1', 'true', 'yes', 'on'], true);
        } else {
            $env = false;
        }

        return (bool) $flag || (bool) $env;
    }

    /**
     * Normalize phone for Fonnte target (digits; Indonesia 0-prefix → 62).
     */
    public static function normalizeTargetPhone(?string $phone): string
    {
        $target = preg_replace('/[^0-9+]/', '', (string) ($phone ?? ''));
        if (str_starts_with($target, '0')) {
            $target = '62'.substr($target, 1);
        }
        if (str_starts_with($target, '+')) {
            $target = substr($target, 1);
        }

        return $target;
    }

    /**
     * @return array{
     *     success: bool,
     *     error: ?string,
     *     details: ?string,
     *     media_mode: ?string,
     *     provider_response: mixed,
     *     connection_errors: array<int, string>
     * }
     */
    public static function sendMedia(
        Request $request,
        string $target,
        string $message,
        string $absoluteMediaUrl,
        string $filename,
        ?string $uploadFallbackBinary = null,
    ): array {
        if (app()->environment('testing') && self::fakeEnabled()) {
            return [
                'success' => true,
                'error' => null,
                'details' => null,
                'media_mode' => 'fake',
                'provider_response' => [
                    'status' => true,
                    'message' => 'Fonnte send faked (local/testing).',
                    'target' => $target,
                    'filename' => $filename,
                ],
                'connection_errors' => [],
            ];
        }
        if (app()->environment('local') && self::fakeEnabled()) {
            return [
                'success' => true,
                'error' => null,
                'details' => null,
                'media_mode' => 'fake',
                'provider_response' => [
                    'status' => true,
                    'message' => 'Fonnte send faked (local/testing).',
                    'target' => $target,
                    'filename' => $filename,
                ],
                'connection_errors' => [],
            ];
        }

        $token = FonnteSettings::token();
        if (! $token) {
            return [
                'success' => false,
                'error' => 'FONNTE_TOKEN is not configured.',
                'details' => null,
                'media_mode' => null,
                'provider_response' => null,
                'connection_errors' => [],
            ];
        }

        $endpoint = FonnteSettings::sendEndpoint();
        $resolveIp = trim((string) config('services.fonnte.resolve_ip', ''));
        $mediaMode = strtolower(trim((string) config('services.fonnte.media_mode', 'url')));

        $absoluteMediaUrl = WhatsappLinkSettings::absolutizeUrl($request, $absoluteMediaUrl);

        $basePayload = [
            'target' => $target,
            'message' => $message,
            'filename' => $filename,
        ];

        $attemptModes = [null];
        if ($resolveIp !== '') {
            $attemptModes[] = $resolveIp;
        }

        $buildClient = function (?string $forcedIp = null) use ($token, $endpoint) {
            $client = Http::retry(3, 700, function (\Exception $exception) {
                return $exception instanceof ConnectionException;
            }, throw: false)
                ->connectTimeout(8)
                ->timeout(20);

            if ($forcedIp !== null && $forcedIp !== '') {
                $host = parse_url($endpoint, PHP_URL_HOST);
                $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: 443);
                if (is_string($host) && $host !== '') {
                    $client = $client->withOptions([
                        'curl' => [
                            CURLOPT_CONNECT_TO => ["{$host}:{$port}:{$forcedIp}:{$port}"],
                            CURLOPT_RESOLVE => ["{$host}:{$port}:{$forcedIp}"],
                        ],
                    ]);
                }
            }

            return $client->withHeaders(['Authorization' => $token]);
        };

        $resp = null;
        $connectionErrors = [];
        $mediaModes = $mediaMode === 'upload' ? ['upload', 'url'] : ['url', 'upload'];

        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($attemptModes as $forcedIp) {
                foreach ($mediaModes as $modeTry) {
                    try {
                        $requestClient = $buildClient(is_string($forcedIp) ? $forcedIp : null);
                        if ($modeTry === 'upload') {
                            if ($uploadFallbackBinary === null || $uploadFallbackBinary === '') {
                                continue;
                            }
                            $candidate = $requestClient
                                ->attach('file', $uploadFallbackBinary, $filename)
                                ->post($endpoint, $basePayload);
                        } else {
                            $candidate = $requestClient->asForm()->post($endpoint, $basePayload + [
                                'url' => $absoluteMediaUrl,
                            ]);
                        }

                        $resp = $candidate;
                        if ($candidate->successful()) {
                            $mediaMode = $modeTry;

                            break 3;
                        }
                    } catch (ConnectionException $e) {
                        $connectionErrors[] = $e->getMessage();
                    }
                }
            }

            usleep(400000);
        }

        if ($resp === null) {
            return [
                'success' => false,
                'error' => 'Failed to connect to Fonnte. Please check internet/DNS and retry.',
                'details' => implode(' | ', array_filter($connectionErrors)) ?: 'No connection details available.',
                'media_mode' => null,
                'provider_response' => null,
                'connection_errors' => $connectionErrors,
            ];
        }

        if (! $resp->successful()) {
            return [
                'success' => false,
                'error' => 'Failed to send WhatsApp.',
                'details' => $resp->body(),
                'media_mode' => null,
                'provider_response' => $resp->body(),
                'connection_errors' => [],
            ];
        }

        $respJson = $resp->json();
        if (is_array($respJson) && array_key_exists('status', $respJson) && $respJson['status'] === false) {
            $reason = (string) ($respJson['reason'] ?? $respJson['message'] ?? $resp->body());
            $planError = self::detectMediaPlanLimitationMessage($reason);

            return [
                'success' => false,
                'error' => $planError ?? 'Fonnte rejected media send.',
                'details' => $reason,
                'media_mode' => null,
                'provider_response' => $respJson,
                'connection_errors' => [],
            ];
        }

        return [
            'success' => true,
            'error' => null,
            'details' => null,
            'media_mode' => $mediaMode,
            'provider_response' => $respJson ?? $resp->body(),
            'connection_errors' => [],
        ];
    }

    /**
     * Text-only WhatsApp (no attachment). Use when you want WhatsApp to unfurl a link in the message (preview thumbnail).
     *
     * @return array{
     *     success: bool,
     *     error: ?string,
     *     details: ?string,
     *     media_mode: ?string,
     *     provider_response: mixed,
     *     connection_errors: array<int, string>
     * }
     */
    public static function sendTextMessage(string $target, string $message): array
    {
        if (app()->environment(['local', 'testing']) && self::fakeEnabled()) {
            return [
                'success' => true,
                'error' => null,
                'details' => null,
                'media_mode' => 'fake',
                'provider_response' => [
                    'status' => true,
                    'message' => 'Fonnte text send faked (local/testing).',
                    'target' => $target,
                ],
                'connection_errors' => [],
            ];
        }

        $token = FonnteSettings::token();
        if (! $token) {
            return [
                'success' => false,
                'error' => 'FONNTE_TOKEN is not configured.',
                'details' => null,
                'media_mode' => 'text',
                'provider_response' => null,
                'connection_errors' => [],
            ];
        }

        $endpoint = FonnteSettings::sendEndpoint();
        $resolveIp = trim((string) config('services.fonnte.resolve_ip', ''));

        $attemptModes = [null];
        if ($resolveIp !== '') {
            $attemptModes[] = $resolveIp;
        }

        $buildClient = function (?string $forcedIp = null) use ($token, $endpoint) {
            $client = Http::retry(3, 700, function (\Exception $exception) {
                return $exception instanceof ConnectionException;
            }, throw: false)
                ->connectTimeout(8)
                ->timeout(20);

            if ($forcedIp !== null && $forcedIp !== '') {
                $host = parse_url($endpoint, PHP_URL_HOST);
                $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: 443);
                if (is_string($host) && $host !== '') {
                    $client = $client->withOptions([
                        'curl' => [
                            CURLOPT_CONNECT_TO => ["{$host}:{$port}:{$forcedIp}:{$port}"],
                            CURLOPT_RESOLVE => ["{$host}:{$port}:{$forcedIp}"],
                        ],
                    ]);
                }
            }

            return $client->withHeaders(['Authorization' => $token]);
        };

        $payload = [
            'target' => $target,
            'message' => $message,
        ];

        $resp = null;
        $connectionErrors = [];

        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($attemptModes as $forcedIp) {
                try {
                    $requestClient = $buildClient(is_string($forcedIp) ? $forcedIp : null);
                    $candidate = $requestClient->asForm()->post($endpoint, $payload);
                    $resp = $candidate;
                    if ($candidate->successful()) {
                        break 2;
                    }
                } catch (ConnectionException $e) {
                    $connectionErrors[] = $e->getMessage();
                }
            }

            usleep(400000);
        }

        if ($resp === null) {
            return [
                'success' => false,
                'error' => 'Failed to connect to Fonnte. Please check internet/DNS and retry.',
                'details' => implode(' | ', array_filter($connectionErrors)) ?: 'No connection details available.',
                'media_mode' => 'text',
                'provider_response' => null,
                'connection_errors' => $connectionErrors,
            ];
        }

        if (! $resp->successful()) {
            return [
                'success' => false,
                'error' => 'Failed to send WhatsApp.',
                'details' => $resp->body(),
                'media_mode' => 'text',
                'provider_response' => $resp->body(),
                'connection_errors' => [],
            ];
        }

        $respJson = $resp->json();
        if (is_array($respJson) && array_key_exists('status', $respJson) && $respJson['status'] === false) {
            $reason = (string) ($respJson['reason'] ?? $respJson['message'] ?? $resp->body());

            return [
                'success' => false,
                'error' => 'Fonnte rejected message send.',
                'details' => $reason,
                'media_mode' => 'text',
                'provider_response' => $respJson,
                'connection_errors' => [],
            ];
        }

        return [
            'success' => true,
            'error' => null,
            'details' => null,
            'media_mode' => 'text',
            'provider_response' => $respJson ?? $resp->body(),
            'connection_errors' => [],
        ];
    }

    private static function detectMediaPlanLimitationMessage(mixed $providerMessage): ?string
    {
        $message = strtolower(trim((string) $providerMessage));
        if ($message === '') {
            return null;
        }

        $attachmentHints = ['attachment', 'media', 'file', 'url'];
        $planHints = ['paket', 'plan', 'free', 'super', 'advanced', 'ultra', 'premium'];

        $mentionsAttachment = false;
        foreach ($attachmentHints as $hint) {
            if (str_contains($message, $hint)) {
                $mentionsAttachment = true;
                break;
            }
        }

        $mentionsPlan = false;
        foreach ($planHints as $hint) {
            if (str_contains($message, $hint)) {
                $mentionsPlan = true;
                break;
            }
        }

        if ($mentionsAttachment && $mentionsPlan) {
            return 'Fonnte media/attachment is not available for your current plan. Please upgrade to a plan that supports attachment (Super/Advanced/Ultra), then retry.';
        }

        return null;
    }
}

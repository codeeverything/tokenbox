<?php

namespace Tokenbox;

/** Tiny dependency-free HTTP client built on PHP streams. */
final class Http
{
    /** @return array{status: int, headers: array<string, string>, body: string, error: ?string} */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", [...$headers, 'Accept: application/json']),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'user_agent' => 'tokenbox',
        ]]);

        $result = @file_get_contents($url, false, $context);
        $raw = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);

        if ($result === false && $raw === []) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => self::lastError()];
        }

        // With redirects there are several header blocks; keep the last one.
        $status = 0;
        $parsed = [];
        foreach ($raw as $line) {
            if (preg_match('{^HTTP/\S+ (\d{3})}', $line, $m)) {
                $status = (int) $m[1];
                $parsed = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $parsed, 'body' => (string) $result, 'error' => null];
    }

    private static function lastError(): string
    {
        $message = error_get_last()['message'] ?? 'unreachable';

        return preg_match('{: (.+)$}', $message, $m) ? $m[1] : $message;
    }
}

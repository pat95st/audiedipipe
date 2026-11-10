<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Http;

use RuntimeException;

final class CurlHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, array $options = []): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP extension "curl" is required.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize curl.');
        }
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $verb = strtoupper($method);
        if ($verb === '') {
            $verb = 'GET';
        }
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $verb,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? 3600),
            CURLOPT_SSL_VERIFYPEER => ($options['verify'] ?? true) !== false,
            CURLOPT_SSL_VERIFYHOST => ($options['verify'] ?? true) !== false ? 2 : 0,
            CURLOPT_USERAGENT => 'audiEdiPipe/1.0',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if (isset($options['auth']) && is_array($options['auth'])) {
            $curlOptions[CURLOPT_USERPWD] = (string) ($options['auth'][0] ?? '') . ':' . (string) ($options['auth'][1] ?? '');
            $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        $uploadHandle = null;
        $downloadHandle = null;
        if (isset($options['upload_file']) && is_string($options['upload_file'])) {
            $uploadHandle = fopen($options['upload_file'], 'rb');
            if ($uploadHandle === false) {
                throw new RuntimeException('Cannot read ' . $options['upload_file']);
            }
            $curlOptions[CURLOPT_UPLOAD] = true;
            $curlOptions[CURLOPT_INFILE] = $uploadHandle;
            $curlOptions[CURLOPT_INFILESIZE] = (int) filesize($options['upload_file']);
        } elseif ($body !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }
        if (isset($options['download_to']) && is_string($options['download_to'])) {
            $downloadHandle = fopen($options['download_to'], 'wb');
            if ($downloadHandle === false) {
                throw new RuntimeException('Cannot write ' . $options['download_to']);
            }
            unset($curlOptions[CURLOPT_RETURNTRANSFER]);
            $curlOptions[CURLOPT_FILE] = $downloadHandle;
        }
        $curlOptions[CURLOPT_HTTPHEADER] = $headerLines;
        curl_setopt_array($ch, $curlOptions);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (is_resource($uploadHandle)) {
            fclose($uploadHandle);
        }
        if (is_resource($downloadHandle)) {
            fclose($downloadHandle);
        }
        if ($result === false) {
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        return new HttpResponse($status, is_string($result) ? $result : '', $responseHeaders);
    }
}

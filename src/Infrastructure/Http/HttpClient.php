<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Http;

interface HttpClient
{
    /**
     * @param array<string, string>      $headers
     * @param string|array<string, mixed>|null $body  string = raw body, array = multipart/form fields (CURLFile allowed)
     * @param array<string, mixed>       $options  upload_file (path for PUT streaming), download_to (path), auth [user, pass], verify (bool), timeout
     */
    public function request(string $method, string $url, array $headers = [], string|array|null $body = null, array $options = []): HttpResponse;
}

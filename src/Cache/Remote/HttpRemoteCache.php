<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

/**
 * A remote cache behind plain HTTP verbs (SPEC.md §9): `GET` to read, `PUT` to write,
 * `HEAD` to test, `DELETE` to remove. That is exactly what S3/MinIO expose through
 * presigned URLs and what an nginx with `dav_methods PUT DELETE` serves, so no vendor
 * SDK is involved — and no curl extension either: everything goes through PHP's own
 * HTTP stream wrapper, with a 5 second timeout on every request.
 *
 * `remote_token`, when set, is sent as `Authorization: Bearer <token>`.
 *
 * Listing is deliberately unsupported: there is no portable way to enumerate keys over
 * these four verbs (S3 needs its own ListObjects API, WebDAV needs PROPFIND), so
 * {@see self::keys()} always returns `[]` and reports `listing not supported` — which
 * means `phpunit-replay prune --remote` needs the `file` or `git` backend to garbage
 * collect. Objects are content-addressed and append-only, so nothing else in the package
 * ever needs to list.
 */
final class HttpRemoteCache implements RemoteCache
{
    /** @var float seconds — connection and stream timeout for every request */
    private const TIMEOUT = 5.0;

    private ?string $lastError = null;

    /** @param string $base absolute http(s) URL, with a trailing slash */
    public function __construct(
        private readonly string $base,
        private readonly ?string $token = null,
    ) {
    }

    /** Null when `$remote` is not an `http://` or `https://` URL. */
    public static function fromRemote(string $remote, ?string $token = null): ?self
    {
        $remote = trim($remote);

        if (stripos($remote, 'http://') !== 0 && stripos($remote, 'https://') !== 0) {
            return null;
        }

        return new self(rtrim($remote, '/') . '/', ($token === null || $token === '') ? null : $token);
    }

    public function base(): string
    {
        return $this->base;
    }

    public function begin(): void
    {
        $this->lastError = null;
    }

    public function end(): void
    {
    }

    public function get(string $key): ?string
    {
        $response = $this->request('GET', $key);

        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        return $response['body'];
    }

    public function put(string $key, string $body): bool
    {
        $response = $this->request('PUT', $key, $body);

        if ($response === null) {
            return false;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->lastError = sprintf('PUT %s returned %d', $key, $response['status']);

            return false;
        }

        return true;
    }

    public function has(string $key): bool
    {
        $response = $this->request('HEAD', $key);

        return $response !== null && $response['status'] >= 200 && $response['status'] < 300;
    }

    public function keys(string $prefix): array
    {
        $this->lastError = 'listing not supported';

        return [];
    }

    public function delete(string $key): bool
    {
        $response = $this->request('DELETE', $key);

        if ($response === null) {
            return false;
        }

        // 404 is success for a delete: the key is gone either way.
        if ($response['status'] === 404 || ($response['status'] >= 200 && $response['status'] < 300)) {
            return true;
        }

        $this->lastError = sprintf('DELETE %s returned %d', $key, $response['status']);

        return false;
    }

    public function name(): string
    {
        return 'http';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * One request through the HTTP stream wrapper. `ignore_errors` keeps a 4xx/5xx from
     * being reported as a transport failure, so the status line can be inspected instead;
     * the response headers are read back from the stream's own metadata (`wrapper_data`)
     * rather than from the magic `$http_response_header` local, which is only defined
     * when the request actually produced a response.
     *
     * @return array{status: int, body: string}|null null when the request never completed
     */
    private function request(string $method, string $key, ?string $body = null): ?array
    {
        $url = $this->urlFor($key);

        if ($url === null) {
            return null;
        }

        $headers = ['Accept: application/json'];

        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        $options = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
            'protocol_version' => 1.1,
        ];

        if ($body !== null) {
            $options['content'] = $body;
        }

        $context = stream_context_create(['http' => $options]);
        $handle = @fopen($url, 'r', false, $context);

        if ($handle === false) {
            $this->lastError = sprintf('%s %s failed (no response)', $method, $key);

            return null;
        }

        $metadata = stream_get_meta_data($handle);
        $raw = stream_get_contents($handle);
        fclose($handle);

        $wrapperData = $metadata['wrapper_data'] ?? null;
        $status = self::statusOf(is_array($wrapperData) ? array_values($wrapperData) : []);

        if ($status === null) {
            $this->lastError = sprintf('%s %s returned no status line', $method, $key);

            return null;
        }

        return ['status' => $status, 'body' => $raw === false ? '' : $raw];
    }

    private function urlFor(string $key): ?string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');

        if ($key === '' || str_contains($key, '..')) {
            $this->lastError = 'unsafe key ' . $key;

            return null;
        }

        $segments = [];

        foreach (explode('/', $key) as $segment) {
            $segments[] = rawurlencode($segment);
        }

        return $this->base . implode('/', $segments);
    }

    /** @param list<mixed> $headers as PHP fills `$http_response_header`: the status line first */
    private static function statusOf(array $headers): ?int
    {
        $status = null;

        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                // A redirect chain leaves several status lines: the last one is the answer.
                $status = (int) $match[1];
            }
        }

        return $status;
    }
}

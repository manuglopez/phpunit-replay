<?php

declare(strict_types=1);

/**
 * Router script for `php -S 127.0.0.1:<port> http-cache-server.php`: the smallest possible
 * server that speaks the four verbs `Cache\Remote\HttpRemoteCache` needs (SPEC.md §9) —
 * GET to read, PUT to write, HEAD to test, DELETE to remove — over a directory tree, the
 * way an nginx with `dav_methods PUT DELETE` or an S3 presigned endpoint would.
 *
 * Configuration comes from the environment, since a router script takes no arguments:
 *
 *   REPLAY_HTTP_CACHE_DIR    (required) the directory keys are stored under
 *   REPLAY_HTTP_CACHE_TOKEN  (optional) when set, every request must carry
 *                            `Authorization: Bearer <token>` or gets a 401
 *   REPLAY_HTTP_CACHE_PREFIX (optional) URL path prefix to strip, default "cache"
 *
 * Used only by tests/Unit/Cache/Remote/HttpRemoteCacheTest.php.
 */
$respond = static function (int $status, string $body = '', string $contentType = 'text/plain'): void {
    http_response_code($status);
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($body));

    if ($body !== '') {
        echo $body;
    }
};

$root = getenv('REPLAY_HTTP_CACHE_DIR');

if (! is_string($root) || $root === '' || ! is_dir($root)) {
    $respond(500, 'REPLAY_HTTP_CACHE_DIR is not a directory');

    return true;
}

$token = getenv('REPLAY_HTTP_CACHE_TOKEN');

if (is_string($token) && $token !== '') {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (! is_string($authorization) || $authorization !== 'Bearer ' . $token) {
        $respond(401, 'unauthorized');

        return true;
    }
}

$prefixEnv = getenv('REPLAY_HTTP_CACHE_PREFIX');
$prefix = (is_string($prefixEnv) && $prefixEnv !== '') ? $prefixEnv : 'cache';

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '/';
$path = rawurldecode(ltrim($path, '/'));

if ($path !== $prefix && ! str_starts_with($path, $prefix . '/')) {
    $respond(404, 'not found');

    return true;
}

$key = ltrim(substr($path, strlen($prefix)), '/');

if ($key === '' || str_contains($key, '..')) {
    $respond(400, 'bad key');

    return true;
}

$file = rtrim($root, '/') . '/' . $key;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
    case 'HEAD':
        if (! is_file($file)) {
            $respond(404, 'not found');

            break;
        }

        $body = file_get_contents($file);
        $respond(200, $body === false ? '' : $body, 'application/json');

        break;

    case 'PUT':
        $parent = dirname($file);

        if (! is_dir($parent) && ! @mkdir($parent, 0o775, true) && ! is_dir($parent)) {
            $respond(500, 'cannot create ' . $parent);

            break;
        }

        $body = file_get_contents('php://input');

        if (file_put_contents($file, $body === false ? '' : $body) === false) {
            $respond(500, 'cannot write ' . $key);

            break;
        }

        $respond(201, 'created');

        break;

    case 'DELETE':
        if (! is_file($file)) {
            $respond(404, 'not found');

            break;
        }

        $respond(@unlink($file) ? 204 : 500);

        break;

    default:
        $respond(405, 'method not allowed');
}

return true;

<?php

declare(strict_types=1);

/*
 * Entry point for the Vercel PHP runtime (vercel-php / vercel-community/php).
 *
 * The runtime boots PHP's built-in web server with THIS file as the router
 * script, so every incoming request is executed here first. Two jobs:
 *
 *   1. Serve real files that exist inside public/ - the Vite output under
 *      public/build/assets, plus favicon.ico and robots.txt. Those files are
 *      bundled inside the serverless function, so there is no separate static
 *      asset layer on Vercel to serve them.
 *   2. Hand everything else to Laravel's front controller, public/index.php.
 *
 * The bootstrap sequence of public/index.php is deliberately left untouched:
 * it defines LARAVEL_START, loads vendor/autoload.php, then hands over to
 * bootstrap/app.php.
 */

$publicPath = __DIR__.'/../public';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';

$mimeTypes = [
    'avif' => 'image/avif',
    'css' => 'text/css',
    'eot' => 'application/vnd.ms-fontobject',
    'gif' => 'image/gif',
    'ico' => 'image/vnd.microsoft.icon',
    'jpeg' => 'image/jpeg',
    'jpg' => 'image/jpeg',
    'js' => 'text/javascript',
    'json' => 'application/json',
    'map' => 'application/json',
    'mjs' => 'text/javascript',
    'otf' => 'font/otf',
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'ttf' => 'font/ttf',
    'txt' => 'text/plain',
    'wasm' => 'application/wasm',
    'webp' => 'image/webp',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'xml' => 'application/xml',
    'zip' => 'application/zip',
];

$resolvedPublic = realpath($publicPath);
$candidate = realpath($publicPath.'/'.ltrim($requestPath, '/'));
$extension = is_string($candidate) ? strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) : '';

$isStaticFile = is_string($resolvedPublic)
    && is_string($candidate)
    && $candidate !== $resolvedPublic.DIRECTORY_SEPARATOR.'index.php'
    && str_starts_with($candidate, $resolvedPublic.DIRECTORY_SEPARATOR)
    && $extension !== 'php'
    && isset($mimeTypes[$extension])
    && is_file($candidate)
    && is_readable($candidate);

if ($isStaticFile) {
    $immutable = str_starts_with($requestPath, '/build/');

    header('Content-Type: '.$mimeTypes[$extension]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: '.($immutable
        ? 'public, max-age=31536000, immutable'
        : 'public, max-age=3600'));
    header('Content-Length: '.filesize($candidate));

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($candidate);
    }

    return;
}

require $publicPath.'/index.php';
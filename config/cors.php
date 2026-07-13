<?php

/*
|------------------------------------------------------------------------
| Frontend
|------------------------------------------------------------------------
| Cambia este valor solo si cambias el dominio del frontend.
| Importante: para CORS debe ir sin "/" al final.
| Ejemplo:
| https://inclusive-job-ai-front.vercel.app
*/
const INCLUSIJOB_FRONTEND_ORIGIN = 'https://inclusive-job-ai-front.vercel.app';
const INCLUSIJOB_FRONTEND_URL = INCLUSIJOB_FRONTEND_ORIGIN . '/';

/*
|------------------------------------------------------------------------
| Backend
|------------------------------------------------------------------------
| Cambia INCLUSIJOB_BACKEND_BASE_PATH segun donde subas el backend.
|
| Si tu API queda en:
| https://midominio.com/back-inclusiveJob
| usa: /back-inclusiveJob
|
| Si tu API queda directo en:
| https://api.midominio.com
| usa: ''
|
| Tambien puedes definirlo como variable de entorno:
| INCLUSIJOB_BACKEND_BASE_PATH
*/
define('INCLUSIJOB_BACKEND_BASE_PATH', getenv('INCLUSIJOB_BACKEND_BASE_PATH') ?: '/back-inclusiveJob');

/*
|------------------------------------------------------------------------
| Base de datos
|------------------------------------------------------------------------
| En hosting normalmente debes cambiar estos datos por los que te da
| el proveedor: host, usuario, password y nombre de la base.
|
| Tambien puedes definirlos como variables de entorno:
| INCLUSIJOB_DB_HOST
| INCLUSIJOB_DB_USER
| INCLUSIJOB_DB_PASSWORD
| INCLUSIJOB_DB_NAME
*/
define('INCLUSIJOB_DB_HOST', getenv('INCLUSIJOB_DB_HOST') ?: 'localhost');
define('INCLUSIJOB_DB_USER', getenv('INCLUSIJOB_DB_USER') ?: 'root');
define('INCLUSIJOB_DB_PASSWORD', getenv('INCLUSIJOB_DB_PASSWORD') ?: '');
define('INCLUSIJOB_DB_NAME', getenv('INCLUSIJOB_DB_NAME') ?: 'inclusijob');

/*
|------------------------------------------------------------------------
| Sesion / cookies
|------------------------------------------------------------------------
| Para frontend y backend en dominios diferentes, las cookies necesitan:
| Secure=true y SameSite=None.
|
| Importante: Secure=true requiere que el backend use HTTPS.
|
| Si pruebas en HTTP local y el navegador no guarda la cookie, puedes
| definir temporalmente INCLUSIJOB_SESSION_SECURE=false en tu entorno.
| En produccion dejalo en true.
*/
define('INCLUSIJOB_SESSION_SECURE', filter_var(getenv('INCLUSIJOB_SESSION_SECURE') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('INCLUSIJOB_SESSION_SAMESITE', getenv('INCLUSIJOB_SESSION_SAMESITE') ?: 'None');

function inclusijob_frontend_url(string $path = ''): string
{
    return INCLUSIJOB_FRONTEND_URL . ltrim($path, '/');
}

function inclusijob_backend_origin(): string
{
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $isHttps = $forwardedProto === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST']
        ?? $_SERVER['HTTP_HOST']
        ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    return $scheme . '://' . $host;
}

function inclusijob_backend_url(string $path = ''): string
{
    return inclusijob_backend_origin()
        . rtrim(INCLUSIJOB_BACKEND_BASE_PATH, '/')
        . '/'
        . ltrim($path, '/');
}

function inclusijob_session_cookie_params(): void
{
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'secure' => INCLUSIJOB_SESSION_SECURE,
        'samesite' => INCLUSIJOB_SESSION_SAMESITE,
    ]);
}

function inclusijob_cors_headers(
    string $methods = 'GET, POST, OPTIONS',
    string $allowedHeaders = 'Content-Type, Authorization',
    string $contentType = 'application/json; charset=utf-8',
    int $optionsStatus = 204
): void {
    if ($contentType !== '') {
        header('Content-Type: ' . $contentType);
    }

    header('Access-Control-Allow-Origin: ' . INCLUSIJOB_FRONTEND_ORIGIN);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: ' . $allowedHeaders);

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code($optionsStatus);
        exit;
    }
}

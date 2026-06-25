<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Headers de seguridad
|--------------------------------------------------------------------------
*/
header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/
$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:3000"
];

if (
    isset($_SERVER['HTTP_ORIGIN']) &&
    in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins, true)
) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");

/*
|--------------------------------------------------------------------------
| Preflight
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Lista blanca HTTP
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Metodo no permitido"
    ]);

    exit;
}

require_once __DIR__ . "/../../Conexion.php";
require_once __DIR__ . "/pos.php";

try {

    /*
    |--------------------------------------------------------------------------
    | RATE LIMITING
    |--------------------------------------------------------------------------
    | 10 intentos cada 5 minutos por IP
    */
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $rateFile = sys_get_temp_dir() . "/reg_" . md5($ip);

    $attempts = [];

    if (file_exists($rateFile)) {
        $attempts = json_decode(file_get_contents($rateFile), true) ?? [];
    }

    $now = time();

    // limpiar intentos mayores a 5 min
    $attempts = array_filter(
        $attempts,
        fn($t) => ($now - $t) < 300
    );

    if (count($attempts) >= 10) {
        http_response_code(429);

        echo json_encode([
            "success" => false,
            "message" => "Demasiados intentos, intenta mas tarde"
        ]);

        exit;
    }

    $attempts[] = $now;

    file_put_contents($rateFile, json_encode($attempts));

    /*
    |--------------------------------------------------------------------------
    | Validar Content-Type
    |--------------------------------------------------------------------------
    */
    $contentType = $_SERVER["CONTENT_TYPE"] ?? "";

    if (stripos($contentType, "application/json") === false) {

        http_response_code(415);

        echo json_encode([
            "success" => false,
            "message" => "Contenido invalido"
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Leer JSON
    |--------------------------------------------------------------------------
    */
    $rawInput = file_get_contents("php://input");

    if ($rawInput === false || trim($rawInput) === '') {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Solicitud vacia"
        ]);

        exit;
    }

    $data = json_decode($rawInput, true);

    if (
        json_last_error() !== JSON_ERROR_NONE ||
        !is_array($data)
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "JSON invalido"
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Campos requeridos
    |--------------------------------------------------------------------------
    */
    $nombres  = trim((string)($data["nombres"] ?? ""));
    $correo   = trim((string)($data["correo"] ?? ""));
    $password = (string)($data["password"] ?? "");
    $telefono = trim((string)($data["telefono"] ?? ""));

    if (
        $nombres === '' ||
        $correo === '' ||
        $password === '' ||
        $telefono === ''
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Faltan datos"
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Validacion de longitud
    |--------------------------------------------------------------------------
    */
    if (strlen($nombres) < 3 || strlen($nombres) > 60) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Datos invalidos"]);
        exit;
    }

    if (strlen($correo) > 100) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Datos invalidos"]);
        exit;
    }

    if (strlen($password) < 8 || strlen($password) > 128) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Datos invalidos"]);
        exit;
    }

    if (strlen($telefono) < 7 || strlen($telefono) > 15) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Datos invalidos"]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Validacion de formato
    |--------------------------------------------------------------------------
    */
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Datos invalidos"]);
        exit;
    }

    if (!preg_match("/^[\\p{L}\\s.-]{3,60}$/u", $nombres)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Datos invalidos"]);
        exit;
    }

    if (!preg_match('/^[0-9]{7,15}$/', $telefono)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Datos invalidos"]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Politica de contrasena
    |--------------------------------------------------------------------------
    */
if (
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[a-z]/', $password) ||
    !preg_match('/[0-9]/', $password) ||
    !preg_match('/[\W_]/', $password)
) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Datos invalidos"
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Conexion BD
    |--------------------------------------------------------------------------
    */
    $con = conectarbd();

    if (!$con) {
        throw new Exception("No fue posible conectar a la base de datos");
    }

    /*
    |--------------------------------------------------------------------------
    | Registro (genera token pendiente, NO crea el usuario todavia)
    |--------------------------------------------------------------------------
    */
    $result = registrarPostulante(
        $con,
        $nombres,
        $correo,
        $password,
        $telefono
    );

    http_response_code($result["success"] ? 201 : 400);

    echo json_encode($result);

    $con->close();

} catch (Throwable $e) {

    error_log("[REGISTRO_POSTULANTE] " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Error interno del servidor"
    ]);
}
?>

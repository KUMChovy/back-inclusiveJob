<?php
/* =========================
   SESIÓN SEGURA
========================= */
session_set_cookie_params([
    "httponly" => true,
    "secure"   => false, // true en HTTPS (producción)
    "samesite" => "Strict"
]);
session_start();

/* =========================
   CORS + HEADERS
========================= */
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/* =========================
   HELPERS
========================= */
function reply(bool $ok, string $msg, array $extra = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode(array_merge(["success" => $ok, "message" => $msg], $extra));
    exit;
}

function decodeGoogleJWT(string $jwt): ?array {
    $parts = explode(".", $jwt);
    if (count($parts) !== 3) return null;
    $payload = base64_decode(str_replace(["-", "_"], ["+", "/"], $parts[1]));
    if (!$payload) return null;
    return json_decode($payload, true) ?: null;
}

/* =========================
   MÉTODO PERMITIDO
========================= */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    reply(false, "Método no permitido", [], 405);
}

/* =========================
   INPUT JSON
========================= */
$body       = json_decode(file_get_contents("php://input"), true);
$credential = trim($body["credential"] ?? "");

if ($credential === "") {
    reply(false, "Token de Google requerido", [], 400);
}

/* =========================
   VERIFICAR JWT GOOGLE
========================= */
const GOOGLE_CLIENT_ID = "128233628072-7ds5mnsr3od6unpukot51dco8o4lstog.apps.googleusercontent.com";

$payload = decodeGoogleJWT($credential);

if (
    !$payload ||
    ($payload["aud"] ?? "") !== GOOGLE_CLIENT_ID ||
    ($payload["exp"] ?? 0) < time()
) {
    reply(false, "Token de Google inválido o expirado", [], 401);
}

$email = strtolower(trim($payload["email"] ?? ""));

if ($email === "") {
    reply(false, "Google no devolvió un correo válido", [], 422);
}

/* =========================
   CONEXIÓN
========================= */
require_once __DIR__ . "/../../Conexion.php";
$conn = conectarbd();

if (!$conn) {
    reply(false, "No se pudo conectar a la base de datos", [], 500);
}

/* =========================
   BUSCAR USUARIO POR CORREO
   (mismo JOIN que login.php nativo)
========================= */
$sql = "SELECT
            u.id_usuario,
            u.nombres,
            u.apellidos,
            u.correo,
            u.contraseña,
            u.estado,
            u.correo_validado,
            u.id_rol,
            r.nombre_rol
        FROM usuarios u
        INNER JOIN rol r ON u.id_rol = r.id_rol
        WHERE u.correo = ?
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    reply(false, "Error interno al preparar consulta", [], 500);
}

mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

/* =========================
   USUARIO NO REGISTRADO
========================= */
if (!$user) {
    reply(false, "No existe una cuenta con este correo de Google. Regístrate primero.", [
        "not_registered" => true
    ], 404);
}

/* =========================
   CUENTA DESACTIVADA
========================= */
if ((int)$user["estado"] === 0) {
    reply(false, "Tu cuenta está desactivada. Contacta a soporte.", [], 403);
}

/* =========================
   SESIÓN SEGURA
   (idéntica al login.php nativo)
========================= */
session_regenerate_id(true);

$_SESSION["user"] = [
    "id"      => $user["id_usuario"],
    "rol"     => $user["nombre_rol"],
    "nombre"  => $user["nombres"],
    "apellido"=> $user["apellidos"]
];

$_SESSION["login_time"]      = time();
$_SESSION["last_activity"]   = time();
$_SESSION["session_timeout"] = 1800;

// CSRF token (igual que login.php)
if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/* =========================
   RESPUESTA
   (misma estructura que login.php nativo)
========================= */
reply(true, "Sesión iniciada con Google", [
    "rol"              => $user["nombre_rol"],
    "requiere_password"=> str_starts_with($user["contraseña"] ?? "", "$2y$")
                         ? false   // ya tiene contraseña real
                         : true,   // aún tiene mock, debe crear una
    "csrf"             => $_SESSION["csrf_token"]
]);
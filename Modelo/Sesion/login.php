<?php

/*
|--------------------------------------------------------------------------
| API DE SESION - login.php
|--------------------------------------------------------------------------
| Este endpoint inicia la sesion PHP del usuario.
|
| Flujo principal:
| 1. Configura la cookie de sesion con httponly, secure y samesite.
| 2. Responde el preflight OPTIONS usado por CORS.
| 3. Acepta solo POST con JSON: { correo, password }.
| 4. Busca el usuario por correo y obtiene su rol desde la tabla rol.
| 5. Verifica la password con password_verify.
| 6. Si detecta una password legacy en texto plano, la migra a hash bcrypt.
| 7. Bloquea cuentas desactivadas o correos no verificados.
| 8. Regenera el ID de sesion para evitar fijacion de sesion.
| 9. Guarda los datos minimos en $_SESSION["user"].
| 10. Devuelve JSON para que el front consulte sesion.php y normalice el usuario.
|
| Respuestas importantes:
| - success=false + message: el front muestra ese mensaje al usuario.
| - requiere_verificacion=true: el usuario existe, pero aun debe verificar correo.
*/

/* =========================
   SESIÓN SEGURA
========================= */
session_set_cookie_params([
    "httponly" => true,
    "secure" => false, // true en HTTPS (producción)
    "samesite" => "Strict"
]);

session_start();

/* =========================
   HEADERS SEGURIDAD
========================= */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

/* =========================
   PRE-FLIGHT CORS
========================= */
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/* =========================
   MÉTODO PERMITIDO
========================= */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Permiso denegado"]);
    exit;
}

/* =========================
   CSRF (NO BLOQUEANTE MVP)
========================= */
if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/* =========================
   CONEXIÓN
========================= */
require_once __DIR__ . "/../../Conexion.php";
$conn = conectarbd();

/* =========================
   INPUT JSON
========================= */
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["success" => false, "message" => "Solicitud inválida"]);
    exit;
}

$correo = trim($data["correo"] ?? "");
$password = $data["password"] ?? "";

$correo = trim($data["correo"] ?? "");
$password = $data["password"] ?? "";
/* =========================
   VALIDACIÓN INPUT
========================= */
if (empty($correo) || empty($password)) {
    echo json_encode(["success" => false, "message" => "Credenciales inválidas"]);
    exit;
}

if (false && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Credenciales inválidas"]);
    exit;
}

if (strlen($correo) > 100 || strlen($password) > 128) {
    echo json_encode(["success" => false, "message" => "Credenciales inválidas"]);
    exit;
}

/* =========================
   CONSULTA
========================= */
$sql = "SELECT 
            u.id_usuario,
            u.nombres,
            u.apellidos,
            u.correo,
            u.contraseña,
            u.correo_validado,
            u.estado,
            u.id_rol,
            r.nombre_rol
        FROM usuarios u
        INNER JOIN rol r ON u.id_rol = r.id_rol
        WHERE u.correo = ?
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error interno"]);
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $correo);
mysqli_stmt_execute($stmt);

$res = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($res);

/* =========================
   VALIDACIÓN LOGIN (ANTI ENUMERACIÓN)
========================= */
$passwordHash = (string)($user["contraseña"] ?? "");
$passwordOk = $user && password_verify($password, $passwordHash);
$legacyPasswordOk = $user && !$passwordOk && hash_equals($passwordHash, (string)$password);

if (!$user || (!$passwordOk && !$legacyPasswordOk)) {
    echo json_encode([
        "success" => false,
        "message" => "Credenciales inválidas"
    ]);
    exit;
}

if ($legacyPasswordOk) {
    $nuevoHash = password_hash($password, PASSWORD_BCRYPT);
    $stmtHash = mysqli_prepare($conn, "UPDATE usuarios SET contraseña = ? WHERE id_usuario = ?");
    if ($stmtHash) {
        mysqli_stmt_bind_param($stmtHash, "si", $nuevoHash, $user["id_usuario"]);
        mysqli_stmt_execute($stmtHash);
    }
}

/* =========================
   CUENTA DESACTIVADA
========================= */
if ((int)$user["estado"] === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Tu cuenta está desactivada. Contacta a soporte."
    ]);
    exit;
}

/* =========================
   CORREO NO VERIFICADO
========================= */
// Capa extra de seguridad: con el flujo actual de registro esto nunca
// debería pasar (el usuario solo se crea ya verificado), pero queda
// como protección ante futuros métodos de registro (ej. Google) que
// pudieran crear usuarios sin verificar.
if ((int)$user["correo_validado"] === 0) {
    echo json_encode([
        "success" => false,
        "message" => "Debes verificar tu correo antes de iniciar sesión.",
        "requiere_verificacion" => true,
        "correo" => $user["correo"]
    ]);
    exit;
}

/* =========================
   SESIÓN SEGURA
========================= */
session_regenerate_id(true);

$_SESSION["user"] = [
    "id" => $user["id_usuario"],
    "rol" => $user["nombre_rol"],
    "nombre" => $user["nombres"],
    "apellido" => $user["apellidos"]
];

/* =========================
   CONTROL DE SESIÓN (PARA sesion.php)
========================= */
$_SESSION["login_time"] = time();       // momento de login
$_SESSION["last_activity"] = time();     // última actividad
$_SESSION["session_timeout"] = 1800;     // 30 minutos inactividad

/* =========================
   RESPUESTA LIMPIA
========================= */
echo json_encode([
    "success" => true,
    "rol" => $user["nombre_rol"],
    "csrf" => $_SESSION["csrf_token"] // opcional para frontend
]);

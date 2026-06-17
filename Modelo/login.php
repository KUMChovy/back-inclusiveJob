<?php
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
require_once __DIR__ . "/../Conexion.php";
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

/* =========================
   VALIDACIÓN INPUT
========================= */
if (empty($correo) || empty($password)) {
    echo json_encode(["success" => false, "message" => "Credenciales inválidas"]);
    exit;
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Credenciales inválidas"]);
    exit;
}

if (strlen($correo) > 100 || strlen($password) > 128) {
    echo json_encode(["success" => false, "message" => "Credenciales inválidas"]);
    exit;
}

/* =========================
   CONSULTA SEGURA
========================= */
$sql = "SELECT 
            u.id_usuario,
            u.nombres,
            u.apellidos,
            u.correo,
            u.contraseña,
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
if (!$user || !password_verify($password, $user["contraseña"])) {
    // mismo mensaje para evitar ataque de enumeración
    echo json_encode([
        "success" => false,
        "message" => "Credenciales inválidas"
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
   RESPUESTA LIMPIA
========================= */
echo json_encode([
    "success" => true,
    "rol" => $user["nombre_rol"],
    "csrf" => $_SESSION["csrf_token"] // opcional para frontend
]);
<?php


require_once __DIR__ . '/../../config/cors.php';
inclusijob_session_cookie_params();

session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Pre-flight CORS
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ── 1. Verificar que hay sesión activa ───────────────────────
if (!isset($_SESSION["user"]["id"])) {
    echo json_encode(["auth" => false]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

// ── 2. Conectar a la BD ──────────────────────────────────────
require_once '../../Conexion.php';
$con = conectarbd();

// ── 3. Buscar el postulante y verificar si completó el form ──
//  Un postulante "vacío" (creado al registrarse) tiene
//  descripcion_discapacidad = NULL.
//  Si ya llenó el formulario, ese campo tendrá contenido.
$stmt = mysqli_prepare($con,
    "SELECT descripcion_discapacidad
       FROM postulantes
      WHERE id_usuario = ?
      LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $id_usuario);
mysqli_stmt_execute($stmt);
$res        = mysqli_stmt_get_result($stmt);
$postulante = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);
mysqli_close($con);

// Si no existe registro → nunca llenó el formulario
if (!$postulante) {
    echo json_encode([
        "auth"       => true,
        "completado" => false
    ]);
    exit;
}

// Si existe pero descripcion_discapacidad es NULL → no completó
$completado = !is_null($postulante["descripcion_discapacidad"]);

echo json_encode([
    "auth"       => true,
    "completado" => $completado
]);
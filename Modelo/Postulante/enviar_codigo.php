<?php

require_once __DIR__ . '/../../config/cors.php';
// ============================================================
//  enviar_codigo.php
//  Método: POST
//  Body JSON: { "correo": "usuario@correo.com" }
//  Respuesta JSON: { "success": true|false, "mensaje": "..." }
// ============================================================

header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
header("Access-Control-Allow-Credentials: true");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'mensaje' => 'Método no permitido.']);
    exit;
}

require_once '../../Conexion.php';   // sube de Postulante → Modelo → back-inclusiveJob

// ── 1. Leer y validar el body ────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true);
$correo = trim($body['correo'] ?? '');

if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'Correo electrónico inválido.']);
    exit;
}

$con = conectarbd();   // <-- tu función

// ── 2. Verificar que el correo existe ────────────────────────
$stmt = mysqli_prepare($con, "SELECT id_usuario, correo_validado FROM usuarios WHERE correo = ?");
mysqli_stmt_bind_param($stmt, 's', $correo);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($stmt);
    mysqli_close($con);
    http_response_code(404);
    echo json_encode(['success' => false, 'mensaje' => 'No existe una cuenta con ese correo.']);
    exit;
}

$usuario = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ((int)$usuario['correo_validado'] === 1) {
    mysqli_close($con);
    echo json_encode(['success' => false, 'mensaje' => 'Este correo ya ha sido verificado.']);
    exit;
}

$id_usuario = (int)$usuario['id_usuario'];

// ── 3. Borrar tokens anteriores del mismo usuario ────────────
$stmtDel = mysqli_prepare($con, "DELETE FROM tokens WHERE id_usuario = ? AND tipo = 'verificacion_correo'");
mysqli_stmt_bind_param($stmtDel, 'i', $id_usuario);
mysqli_stmt_execute($stmtDel);
mysqli_stmt_close($stmtDel);

// ── 4. Generar y guardar el nuevo código ─────────────────────
$codigo        = sprintf('%06d', random_int(0, 999999));
$tiempo_expira = date('Y-m-d H:i:s', strtotime('+15 minutes'));
$tipo          = 'verificacion_correo';

$stmtIns = mysqli_prepare($con, "INSERT INTO tokens (token, tipo, tiempo_expira, id_usuario) VALUES (?, ?, ?, ?)");
mysqli_stmt_bind_param($stmtIns, 'sssi', $codigo, $tipo, $tiempo_expira, $id_usuario);

if (!mysqli_stmt_execute($stmtIns)) {
    mysqli_stmt_close($stmtIns);
    mysqli_close($con);
    http_response_code(500);
    echo json_encode(['success' => false, 'mensaje' => 'No se pudo guardar el código.']);
    exit;
}
mysqli_stmt_close($stmtIns);
mysqli_close($con);

// ── 5. Devolver el código al frontend (EmailJS lo envía) ─────
// El frontend recibe el código y usa EmailJS para mandarlo por correo.
echo json_encode([
    'success' => true,
    'codigo'  => $codigo,
    'mensaje' => 'Código generado correctamente.'
]);
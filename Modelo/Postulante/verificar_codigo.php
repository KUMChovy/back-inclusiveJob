<?php
// ============================================================
//  verificar_codigo.php
//  Método: POST
//  Body JSON: { "correo": "usuario@correo.com", "codigo": "123456" }
//  Respuesta JSON: { "success": true|false, "mensaje": "..." }
// ============================================================

header('Access-Control-Allow-Origin: http://localhost:5173');
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

require_once '../../conexion.php';   // sube de Postulante → Modelo → back-inclusiveJob

// ── 1. Leer y validar el body ────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true);
$correo = trim($body['correo'] ?? '');
$codigo = trim($body['codigo'] ?? '');

if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'Correo electrónico inválido.']);
    exit;
}

if (empty($codigo) || !preg_match('/^\d{6}$/', $codigo)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'El código debe tener exactamente 6 dígitos.']);
    exit;
}

$con = conectarbd();   // <-- tu función

// ── 2. Obtener el id_usuario por correo ──────────────────────
$stmtU = mysqli_prepare($con, "SELECT id_usuario FROM usuarios WHERE correo = ?");
mysqli_stmt_bind_param($stmtU, 's', $correo);
mysqli_stmt_execute($stmtU);
$resU = mysqli_stmt_get_result($stmtU);

if (mysqli_num_rows($resU) === 0) {
    mysqli_stmt_close($stmtU);
    mysqli_close($con);
    http_response_code(404);
    echo json_encode(['success' => false, 'mensaje' => 'No existe una cuenta con ese correo.']);
    exit;
}

$id_usuario = (int)mysqli_fetch_assoc($resU)['id_usuario'];
mysqli_stmt_close($stmtU);

// ── 3. Buscar el token válido y no expirado ──────────────────
$ahora = date('Y-m-d H:i:s');
$tipo  = 'verificacion_correo';

$stmtT = mysqli_prepare($con,
    "SELECT id_token FROM tokens
      WHERE id_usuario = ?
        AND tipo       = ?
        AND token      = ?
        AND tiempo_expira > ?
      LIMIT 1"
);
mysqli_stmt_bind_param($stmtT, 'isss', $id_usuario, $tipo, $codigo, $ahora);
mysqli_stmt_execute($stmtT);
$resT = mysqli_stmt_get_result($stmtT);

if (mysqli_num_rows($resT) === 0) {
    mysqli_stmt_close($stmtT);
    mysqli_close($con);
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'mensaje' => 'Código incorrecto o expirado. Solicita uno nuevo.'
    ]);
    exit;
}

$id_token = (int)mysqli_fetch_assoc($resT)['id_token'];
mysqli_stmt_close($stmtT);

// ── 4. Marcar correo_validado = 1 ────────────────────────────
$stmtVal = mysqli_prepare($con, "UPDATE usuarios SET correo_validado = 1 WHERE id_usuario = ?");
mysqli_stmt_bind_param($stmtVal, 'i', $id_usuario);

if (!mysqli_stmt_execute($stmtVal)) {
    mysqli_stmt_close($stmtVal);
    mysqli_close($con);
    http_response_code(500);
    echo json_encode(['success' => false, 'mensaje' => 'No se pudo validar el correo. Intenta de nuevo.']);
    exit;
}
mysqli_stmt_close($stmtVal);

// ── 5. Eliminar el token usado ───────────────────────────────
$stmtDel = mysqli_prepare($con, "DELETE FROM tokens WHERE id_token = ?");
mysqli_stmt_bind_param($stmtDel, 'i', $id_token);
mysqli_stmt_execute($stmtDel);
mysqli_stmt_close($stmtDel);

mysqli_close($con);

echo json_encode([
    'success' => true,
    'mensaje' => '¡Correo verificado exitosamente! Ya puedes iniciar sesión.'
]);
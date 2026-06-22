<?php
// ============================================================
//  verificar_codigo.php
//  Verifica el código de un registro pendiente y, si es correcto,
//  AHÍ es cuando se crea el usuario real en `usuarios` y `postulantes`.
//
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

require_once '../../conexion.php';

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

$con = conectarbd();

// ── 2. Si el usuario ya existe y ya está validado, no pedir de nuevo ──
$stmtCheck = mysqli_prepare($con, "SELECT id_usuario, correo_validado FROM usuarios WHERE correo = ?");
mysqli_stmt_bind_param($stmtCheck, 's', $correo);
mysqli_stmt_execute($stmtCheck);
$resCheck = mysqli_stmt_get_result($stmtCheck);

if ($usuarioExistente = mysqli_fetch_assoc($resCheck)) {
    mysqli_stmt_close($stmtCheck);

    if ((int)$usuarioExistente['correo_validado'] === 1) {
        mysqli_close($con);
        echo json_encode([
            'success' => true,
            'mensaje' => 'Tu correo ya estaba verificado. Ya puedes iniciar sesión.',
            'ya_verificado' => true
        ]);
        exit;
    }
    // Si existe pero no está validado (caso raro / legado), seguimos el flujo normal abajo
} else {
    mysqli_stmt_close($stmtCheck);
}

// ── 3. Buscar el token pendiente con ese código ──────────────
$ahora = date('Y-m-d H:i:s');
$tipo  = 'registro_pendiente';

$stmtT = mysqli_prepare($con,
    "SELECT id_token, token FROM tokens
      WHERE tipo = ?
        AND tiempo_expira > ?
        AND JSON_UNQUOTE(JSON_EXTRACT(token, '$.correo')) = ?
        AND JSON_UNQUOTE(JSON_EXTRACT(token, '$.codigo')) = ?
      LIMIT 1"
);
mysqli_stmt_bind_param($stmtT, 'ssss', $tipo, $ahora, $correo, $codigo);
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

$tokenRow = mysqli_fetch_assoc($resT);
mysqli_stmt_close($stmtT);

$id_token = (int)$tokenRow['id_token'];
$payload  = json_decode($tokenRow['token'], true);

if (!$payload || empty($payload['nombres']) || empty($payload['password'])) {
    mysqli_close($con);
    http_response_code(500);
    echo json_encode(['success' => false, 'mensaje' => 'El registro pendiente está corrupto. Vuelve a registrarte.']);
    exit;
}

$nombres        = $payload['nombres'];
$telefono       = $payload['telefono'] ?? '';
$password_hash  = $payload['password'];
$id_rol         = 2; // postulante

// ── 4. Crear el usuario, AHORA SÍ, con correo_validado = 1 ───
$stmtIns = mysqli_prepare($con,
    "INSERT INTO usuarios (nombres, correo, contraseña, telefono, correo_validado, id_rol)
     VALUES (?, ?, ?, ?, 1, ?)"
);
mysqli_stmt_bind_param($stmtIns, 'ssssi', $nombres, $correo, $password_hash, $telefono, $id_rol);

if (!mysqli_stmt_execute($stmtIns)) {
    mysqli_stmt_close($stmtIns);
    mysqli_close($con);
    http_response_code(500);
    echo json_encode(['success' => false, 'mensaje' => 'No se pudo crear la cuenta. Intenta de nuevo.']);
    exit;
}

$id_usuario = mysqli_insert_id($con);
mysqli_stmt_close($stmtIns);

// ── 5. Crear el registro en postulantes ──────────────────────
$stmtPost = mysqli_prepare($con, "INSERT INTO postulantes (id_usuario) VALUES (?)");
mysqli_stmt_bind_param($stmtPost, 'i', $id_usuario);
mysqli_stmt_execute($stmtPost);
mysqli_stmt_close($stmtPost);

// ── 6. Eliminar el token usado ───────────────────────────────
$stmtDel = mysqli_prepare($con, "DELETE FROM tokens WHERE id_token = ?");
mysqli_stmt_bind_param($stmtDel, 'i', $id_token);
mysqli_stmt_execute($stmtDel);
mysqli_stmt_close($stmtDel);

mysqli_close($con);

echo json_encode([
    'success'    => true,
    'mensaje'    => '¡Correo verificado y cuenta creada exitosamente! Ya puedes iniciar sesión.',
    'id_usuario' => $id_usuario
]);
<?php

require_once __DIR__ . '/../../config/cors.php';
// ============================================================
//  verificar_codigo.php
//  Verifica el codigo de un registro pendiente y, si es correcto,
//  crea el usuario real en `usuarios` y el perfil segun su rol.
//
//  Metodo: POST
//  Body JSON: { "correo": "usuario@correo.com", "codigo": "123456" }
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
    echo json_encode(['success' => false, 'mensaje' => 'Metodo no permitido.']);
    exit;
}

require_once '../../conexion.php';

$body   = json_decode(file_get_contents('php://input'), true);
$correo = trim($body['correo'] ?? '');
$codigo = trim($body['codigo'] ?? '');

if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'Correo electronico invalido.']);
    exit;
}

if (empty($codigo) || !preg_match('/^\d{6}$/', $codigo)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'El codigo debe tener exactamente 6 digitos.']);
    exit;
}

$con = conectarbd();
mysqli_set_charset($con, 'utf8mb4');

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
            'mensaje' => 'Tu correo ya estaba verificado. Ya puedes iniciar sesion.',
            'ya_verificado' => true
        ]);
        exit;
    }
} else {
    mysqli_stmt_close($stmtCheck);
}

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
        'mensaje' => 'Codigo incorrecto o expirado. Solicita uno nuevo.'
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
    echo json_encode(['success' => false, 'mensaje' => 'El registro pendiente esta corrupto. Vuelve a registrarte.']);
    exit;
}

$nombres       = $payload['nombres'];
$apellidos     = $payload['apellidos'] ?? '';
$telefono      = $payload['telefono'] ?? '';
$password_hash = $payload['password'];
$id_rol        = (int)($payload['id_rol'] ?? 2);

if (!in_array($id_rol, [2, 3], true)) {
    mysqli_close($con);
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'Rol de registro invalido.']);
    exit;
}

$tablaPerfil = $id_rol === 3 ? 'reclutadores' : 'postulantes';

mysqli_begin_transaction($con);

try {
    $stmtIns = mysqli_prepare($con,
        "INSERT INTO usuarios (nombres, apellidos, correo, contraseña, telefono, correo_validado, id_rol)
         VALUES (?, ?, ?, ?, ?, 1, ?)"
    );

    if (!$stmtIns) {
        throw new Exception('No se pudo preparar el registro de usuario: ' . mysqli_error($con));
    }

    mysqli_stmt_bind_param($stmtIns, 'sssssi', $nombres, $apellidos, $correo, $password_hash, $telefono, $id_rol);

    if (!mysqli_stmt_execute($stmtIns)) {
        throw new Exception('No se pudo crear el usuario');
    }

    $id_usuario = mysqli_insert_id($con);
    mysqli_stmt_close($stmtIns);

    $stmtPerfil = mysqli_prepare($con, "INSERT INTO {$tablaPerfil} (id_usuario) VALUES (?)");
    mysqli_stmt_bind_param($stmtPerfil, 'i', $id_usuario);

    if (!mysqli_stmt_execute($stmtPerfil)) {
        throw new Exception('No se pudo crear el perfil');
    }

    mysqli_stmt_close($stmtPerfil);

    $stmtDel = mysqli_prepare($con, "DELETE FROM tokens WHERE id_token = ?");
    mysqli_stmt_bind_param($stmtDel, 'i', $id_token);
    mysqli_stmt_execute($stmtDel);
    mysqli_stmt_close($stmtDel);

    mysqli_commit($con);
} catch (Throwable $e) {
    error_log('[VERIFICAR_CODIGO] ' . $e->getMessage());
    mysqli_rollback($con);
    mysqli_close($con);
    http_response_code(500);
    echo json_encode(['success' => false, 'mensaje' => 'No se pudo crear la cuenta. Intenta de nuevo.']);
    exit;
}

mysqli_close($con);

echo json_encode([
    'success'    => true,
    'mensaje'    => 'Correo verificado y cuenta creada exitosamente. Ya puedes iniciar sesion.',
    'id_usuario' => $id_usuario,
    'id_rol'     => $id_rol
]);




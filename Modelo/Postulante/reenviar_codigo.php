<?php
// ============================================================
//  reenviar_codigo.php
//  Genera un NUEVO código para un registro que aún está pendiente
//  de verificación (token tipo 'registro_pendiente'), sin tocar
//  los demás datos del registro guardados en el JSON.
//
//  Método: POST
//  Body JSON: { "correo": "usuario@correo.com" }
//  Respuesta JSON: { "success": true|false, "codigo": "...", "mensaje": "..." }
// ============================================================

header("Access-Control-Allow-Origin: http://localhost:5173");
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

require_once '../../conexion.php';

$body   = json_decode(file_get_contents('php://input'), true);
$correo = trim($body['correo'] ?? '');

if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensaje' => 'Correo electrónico inválido.']);
    exit;
}

$con = conectarbd();

// Si ya existe y ya está validado, no tiene caso reenviar nada
$stmtCheck = mysqli_prepare($con, "SELECT correo_validado FROM usuarios WHERE correo = ?");
mysqli_stmt_bind_param($stmtCheck, 's', $correo);
mysqli_stmt_execute($stmtCheck);
$resCheck = mysqli_stmt_get_result($stmtCheck);

if ($u = mysqli_fetch_assoc($resCheck)) {
    mysqli_stmt_close($stmtCheck);
    if ((int)$u['correo_validado'] === 1) {
        mysqli_close($con);
        echo json_encode(['success' => false, 'mensaje' => 'Este correo ya está verificado.']);
        exit;
    }
} else {
    mysqli_stmt_close($stmtCheck);
}

// Buscar el token pendiente de este correo
$tipo = 'registro_pendiente';
$stmtT = mysqli_prepare($con,
    "SELECT id_token, token FROM tokens
      WHERE tipo = ?
        AND JSON_UNQUOTE(JSON_EXTRACT(token, '$.correo')) = ?
      LIMIT 1"
);
mysqli_stmt_bind_param($stmtT, 'ss', $tipo, $correo);
mysqli_stmt_execute($stmtT);
$resT = mysqli_stmt_get_result($stmtT);

if (mysqli_num_rows($resT) === 0) {
    mysqli_stmt_close($stmtT);
    mysqli_close($con);
    http_response_code(404);
    echo json_encode(['success' => false, 'mensaje' => 'No hay un registro pendiente para este correo. Vuelve a registrarte.']);
    exit;
}

$row     = mysqli_fetch_assoc($resT);
$id_token = (int)$row['id_token'];
$payload  = json_decode($row['token'], true);
mysqli_stmt_close($stmtT);

// Generar un código nuevo y actualizar el payload + expiración
$nuevoCodigo = sprintf('%06d', random_int(0, 999999));
$payload['codigo'] = $nuevoCodigo;
$nuevoPayload  = json_encode($payload);
$nuevaExpira   = date('Y-m-d H:i:s', strtotime('+15 minutes'));

$stmtUpd = mysqli_prepare($con,
    "UPDATE tokens SET token = ?, tiempo_expira = ? WHERE id_token = ?"
);
mysqli_stmt_bind_param($stmtUpd, 'ssi', $nuevoPayload, $nuevaExpira, $id_token);

if (!mysqli_stmt_execute($stmtUpd)) {
    mysqli_stmt_close($stmtUpd);
    mysqli_close($con);
    http_response_code(500);
    echo json_encode(['success' => false, 'mensaje' => 'No se pudo generar un nuevo código.']);
    exit;
}
mysqli_stmt_close($stmtUpd);
mysqli_close($con);

echo json_encode([
    'success' => true,
    'codigo'  => $nuevoCodigo,
    'mensaje' => 'Nuevo código generado.'
]);
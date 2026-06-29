<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../Conexion.php';
$con = conectarbd();

function responder($ok, $mensaje, $datos = null) {
    if (ob_get_length()) ob_clean(); 
    echo json_encode([
        'success' => $ok,
        'message' => $mensaje,
        'data'    => $datos
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$accion = $_GET['accion'] ?? '';

// ─────────────────────────────────────────────────────────────
// ACCIÓN 1: OBTENER LOS DATOS DEL PERFIL Y ESTADO DE VALIDACIÓN
// ─────────────────────────────────────────────────────────────
if ($accion === 'obtener') {
    $id_usuario = (int)($_GET['id_usuario'] ?? 0);
    if (!$id_usuario) responder(false, 'ID de usuario requerido.');

    $sql = "SELECT id_usuario, nombres, apellidos, correo, telefono, 
                   COALESCE(empresa, '') AS empresa, 
                   COALESCE(puesto, 'Reclutador') AS puesto,
                   COALESCE(sector, 'No especificado') AS sector,
                   empresa_validada
            FROM usuarios 
            WHERE id_usuario = ? AND rol = 'reclutador'";
            
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_usuario);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($res)) {
        // Castear a entero para manejo limpio en React
        $row['empresa_validada'] = (int)$row['empresa_validada'];
        mysqli_stmt_close($stmt);
        mysqli_close($con);
        responder(true, 'Perfil obtenido', $row);
    } else {
        mysqli_stmt_close($stmt);
        mysqli_close($con);
        responder(false, 'No se encontró el perfil del reclutador.');
    }
}

// ─────────────────────────────────────────────────────────────
// ACCIÓN 2: ACTUALIZAR PERFIL Y VALIDAR CAMBIO DE EMPRESA
// ─────────────────────────────────────────────────────────────
if ($accion === 'actualizar') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id_usuario = (int)($input['id_usuario'] ?? 0);
    $nombres    = trim($input['nombres'] ?? '');
    $apellidos  = trim($input['apellidos'] ?? '');
    $telefono   = trim($input['telefono'] ?? '');
    $empresa    = trim($input['empresa'] ?? '');
    $puesto     = trim($input['puesto'] ?? '');
    $sector     = trim($input['sector'] ?? '');

    if (!$id_usuario || empty($nombres) || empty($apellidos)) {
        responder(false, 'Faltan campos obligatorios (Nombre y Apellidos).');
    }

    // 1. Verificar si el nombre de la empresa cambió comparándolo con el valor actual
    $sql_check = "SELECT empresa, empresa_validada FROM usuarios WHERE id_usuario = ?";
    $stmt_check = mysqli_prepare($con, $sql_check);
    mysqli_stmt_bind_param($stmt_check, 'i', $id_usuario);
    mysqli_stmt_execute($stmt_check);
    $res_check = mysqli_stmt_get_result($stmt_check);
    $current_data = mysqli_fetch_assoc($res_check);
    mysqli_stmt_close($stmt_check);

    $empresa_actual = $current_data['empresa'] ?? '';
    $validacion_actual = (int)($current_data['empresa_validada'] ?? 0);

    // Si cambia la empresa o estaba vacía y ahora escribe una, el estado vuelve/se queda en 0 (Pendiente)
    // Si la empresa es exactamente la misma, mantiene el estado de validación que ya tenía.
    $nueva_validacion = ($empresa !== $empresa_actual) ? 0 : $validacion_actual;

    // 2. Ejecutar la actualización completa
    $sql = "UPDATE usuarios SET 
                nombres = ?, 
                apellidos = ?, 
                telefono = ?, 
                empresa = ?, 
                puesto = ?, 
                sector = ?,
                empresa_validada = ?
            WHERE id_usuario = ? AND rol = 'reclutador'";
            
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, 'ssssssii', $nombres, $apellidos, $telefono, $empresa, $puesto, $sector, $nueva_validacion, $id_usuario);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_close($con);
        
        responder(true, 'Perfil actualizado', [
            'empresa_validada' => $nueva_validacion,
            'cambio_empresa' => ($empresa !== $empresa_actual)
        ]);
    } else {
        mysqli_stmt_close($stmt);
        mysqli_close($con);
        responder(false, 'Error al actualizar los datos en la base de datos.');
    }
}

responder(false, 'Acción inválida.');
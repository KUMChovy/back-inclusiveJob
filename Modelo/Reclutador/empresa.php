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
// ACCIÓN 1: OBTENER DATOS CORPORATIVOS
// ─────────────────────────────────────────────────────────────
if ($accion === 'obtener') {
    $id_usuario = (int)($_GET['id_usuario'] ?? 0);
    if (!$id_usuario) responder(false, 'ID de usuario requerido.');

    $sql = "SELECT 
                COALESCE(empresa, '') AS nombre_empresa,
                COALESCE(rfc_empresa, '') AS rfc_empresa,
                COALESCE(sitio_web, '') AS sitio_web,
                COALESCE(descripcion_empresa, '') AS descripcion_empresa,
                COALESCE(direccion_empresa, '') AS direccion_empresa,
                empresa_validada
            FROM usuarios 
            WHERE id_usuario = ? AND rol = 'reclutador'";
            
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_usuario);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($res)) {
        $row['empresa_validada'] = (int)$row['empresa_validada'];
        mysqli_stmt_close($stmt);
        mysqli_close($con);
        responder(true, 'Datos de la empresa obtenidos', $row);
    } else {
        mysqli_stmt_close($stmt);
        mysqli_close($con);
        responder(false, 'No se encontraron datos corporativos.');
    }
}

// ─────────────────────────────────────────────────────────────
// ACCIÓN 2: ACTUALIZAR DATOS DE LA EMPRESA Y ENVIAR A VALIDACIÓN
// ─────────────────────────────────────────────────────────────
if ($accion === 'actualizar') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id_usuario          = (int)($input['id_usuario'] ?? 0);
    $nombre_empresa      = trim($input['nombre_empresa'] ?? '');
    $rfc_empresa         = trim($input['rfc_empresa'] ?? '');
    $sitio_web           = trim($input['sitio_web'] ?? '');
    $descripcion_empresa = trim($input['descripcion_empresa'] ?? '');
    $direccion_empresa   = trim($input['direccion_empresa'] ?? '');

    if (!$id_usuario || empty($nombre_empresa)) {
        responder(false, 'El nombre de la empresa es obligatorio.');
    }

    // Consultamos el nombre actual para saber si cambió
    $sql_check = "SELECT empresa, empresa_validada FROM usuarios WHERE id_usuario = ?";
    $stmt_check = mysqli_prepare($con, $sql_check);
    mysqli_stmt_bind_param($stmt_check, 'i', $id_usuario);
    mysqli_stmt_execute($stmt_check);
    $res_check = mysqli_stmt_get_result($stmt_check);
    $current = mysqli_fetch_assoc($res_check);
    mysqli_stmt_close($stmt_check);

    $empresa_actual = $current['empresa'] ?? '';
    $validacion_actual = (int)($current['empresa_validada'] ?? 0);

    // Si cambia el nombre de la empresa, el estatus baja inmediatamente a 0 (pendiente)
    $nueva_validacion = ($nombre_empresa !== $empresa_actual) ? 0 : $validacion_actual;

    // Asegúrate de tener estas columnas en tu tabla 'usuarios' o ajustarlas según tu estructura:
    // ALTER TABLE usuarios ADD COLUMN rfc_empresa VARCHAR(20), ADD COLUMN sitio_web VARCHAR(100), ADD COLUMN descripcion_empresa TEXT, ADD COLUMN direccion_empresa TEXT;
    $sql = "UPDATE usuarios SET 
                empresa = ?, 
                rfc_empresa = ?, 
                sitio_web = ?, 
                descripcion_empresa = ?, 
                direccion_empresa = ?,
                empresa_validada = ?
            WHERE id_usuario = ? AND rol = 'reclutador'";
            
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, 'sssssii', $nombre_empresa, $rfc_empresa, $sitio_web, $descripcion_empresa, $direccion_empresa, $nueva_validacion, $id_usuario);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_close($con);
        responder(true, 'Datos corporativos actualizados', [
            'empresa_validada' => $nueva_validacion,
            'cambio_nombre' => ($nombre_empresa !== $empresa_actual)
        ]);
    } else {
        mysqli_stmt_close($stmt);
        mysqli_close($con);
        responder(false, 'Error al guardar los datos de la empresa.');
    }
}

responder(false, 'Acción no permitida.');
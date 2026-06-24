<?php
session_start();
// ============================================================
//  guardar_formulario.php
//  Guarda los datos del formulario de evaluaciÃ³n del postulante.
//
//  MÃ©todo: POST (multipart/form-data)
//  Campos esperados:
//    - id_usuario         (int)    â€” hardcodeado a 1 por ahora
//    - tipoDiscapacidad   (string) â€” nombre del tipo de discapacidad
//    - descripcionDiscapacidad (string)
//    - esfuerzoFisico     (string) â€” "SÃ­" | "Con algunas limitaciones" | "No"
//    - experiencia        (string)
//    - habilidades        (string)
//    - fotoPerfil         (file)   â€” imagen opcional
//  Respuesta JSON: { "success": true|false, "mensaje": "..." }
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
    echo json_encode(['success' => false, 'mensaje' => 'MÃ©todo no permitido.']);
    exit;
}

require_once '../../conexion.php';

// â”€â”€ 1. ID de usuario (hardcodeado hasta tener login) â”€â”€â”€â”€â”€â”€â”€â”€â”€
$usuarioSesion = $_SESSION['user'] ?? null;
$id_usuario = (int)($usuarioSesion['id'] ?? 0);
$id_usuario_front = (int)($_POST['id_usuario'] ?? 0);

if (!$usuarioSesion || $id_usuario <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'mensaje' => 'Sesion no valida.']);
    exit;
}

if ($id_usuario_front > 0 && $id_usuario_front !== $id_usuario) {
    http_response_code(403);
    echo json_encode(['success' => false, 'mensaje' => 'No puedes modificar el perfil de otro usuario.']);
    exit;
}

// â”€â”€ 2. Leer campos del formulario â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$tipoDiscapacidad        = trim($_POST['tipoDiscapacidad']        ?? '');
$descripcionDiscapacidad = trim($_POST['descripcionDiscapacidad'] ?? '');
$esfuerzoFisicoRaw       = trim($_POST['esfuerzoFisico']          ?? '');
$experiencia             = trim($_POST['experiencia']             ?? '');
$habilidades             = trim($_POST['habilidades']             ?? '');

// Convertir esfuerzo fÃ­sico a tinyint(1)
// "SÃ­" â†’ 1 | "Con algunas limitaciones" â†’ 1 | "No" â†’ 0
$esfuerzo_fisico = ($esfuerzoFisicoRaw === 'No') ? 0 : 1;

// â”€â”€ 3. Validaciones bÃ¡sicas â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (empty($tipoDiscapacidad)) {
    echo json_encode(['success' => false, 'mensaje' => 'Debes seleccionar un tipo de discapacidad.']);
    exit;
}

if (empty($descripcionDiscapacidad)) {
    echo json_encode(['success' => false, 'mensaje' => 'La descripciÃ³n de la discapacidad es requerida.']);
    exit;
}

if (empty($experiencia)) {
    echo json_encode(['success' => false, 'mensaje' => 'La experiencia es requerida.']);
    exit;
}

if (empty($habilidades)) {
    echo json_encode(['success' => false, 'mensaje' => 'Las habilidades son requeridas.']);
    exit;
}

$con = conectarbd();
mysqli_set_charset($con, 'utf8mb4');

$stmtUsuario = mysqli_prepare($con, "SELECT id_rol FROM usuarios WHERE id_usuario = ? AND estado = 1 LIMIT 1");
mysqli_stmt_bind_param($stmtUsuario, 'i', $id_usuario);
mysqli_stmt_execute($stmtUsuario);
$resUsuario = mysqli_stmt_get_result($stmtUsuario);
$usuarioDb = mysqli_fetch_assoc($resUsuario);
mysqli_stmt_close($stmtUsuario);

if (!$usuarioDb || (int)$usuarioDb['id_rol'] !== 2) {
    mysqli_close($con);
    http_response_code(403);
    echo json_encode(['success' => false, 'mensaje' => 'Solo los postulantes pueden guardar este formulario.']);
    exit;
}
// â”€â”€ 4. Foto de perfil (opcional) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$foto_perfil_ruta = null;

if (!empty($_FILES['fotoPerfil']['name'])) {
    $archivo    = $_FILES['fotoPerfil'];
    $extensiones = ['jpg', 'jpeg', 'png', 'webp'];
    $ext        = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $extensiones)) {
        echo json_encode(['success' => false, 'mensaje' => 'Formato de imagen no vÃ¡lido. Usa JPG, PNG o WEBP.']);
        exit;
    }

    if ($archivo['size'] > 5 * 1024 * 1024) { // 5 MB mÃ¡ximo
        echo json_encode(['success' => false, 'mensaje' => 'La imagen no debe superar los 5 MB.']);
        exit;
    }

    // Carpeta donde se guardan las fotos dentro de htdocs
    $carpeta_destino = __DIR__ . '/../../uploads/fotos_perfil/';
    if (!is_dir($carpeta_destino)) {
        mkdir($carpeta_destino, 0755, true);
    }

    $nombre_archivo  = 'perfil_' . $id_usuario . '_' . time() . '.' . $ext;
    $ruta_completa   = $carpeta_destino . $nombre_archivo;

    if (!move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
        echo json_encode(['success' => false, 'mensaje' => 'No se pudo guardar la imagen.']);
        exit;
    }

    // Ruta relativa que se guarda en la BD
    $foto_perfil_ruta = 'uploads/fotos_perfil/' . $nombre_archivo;
}



// â”€â”€ 5. Verificar si ya existe un postulante para este usuario â”€
$stmtCheck = mysqli_prepare($con, "SELECT id_postulante FROM postulantes WHERE id_usuario = ?");
mysqli_stmt_bind_param($stmtCheck, 'i', $id_usuario);
mysqli_stmt_execute($stmtCheck);
$resCheck = mysqli_stmt_get_result($stmtCheck);
$postulante_existente = mysqli_fetch_assoc($resCheck);
mysqli_stmt_close($stmtCheck);

// â”€â”€ 6. Insertar o actualizar postulante â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($postulante_existente) {
    // Ya existe â†’ UPDATE
    $id_postulante = (int)$postulante_existente['id_postulante'];

    if ($foto_perfil_ruta) {
        $stmtP = mysqli_prepare($con,
            "UPDATE postulantes
                SET descripcion_discapacidad = ?,
                    esfuerzo_fisico_posible  = ?,
                    experiencia              = ?,
                    habilidades              = ?
              WHERE id_postulante = ?"
        );
        mysqli_stmt_bind_param($stmtP, 'sissi',
            $descripcionDiscapacidad,
            $esfuerzo_fisico,
            $experiencia,
            $habilidades,
            $id_postulante
        );
    } else {
        $stmtP = mysqli_prepare($con,
            "UPDATE postulantes
                SET descripcion_discapacidad = ?,
                    esfuerzo_fisico_posible  = ?,
                    experiencia              = ?,
                    habilidades              = ?
              WHERE id_postulante = ?"
        );
        mysqli_stmt_bind_param($stmtP, 'sissi',
            $descripcionDiscapacidad,
            $esfuerzo_fisico,
            $experiencia,
            $habilidades,
            $id_postulante
        );
    }

} else {
    // No existe â†’ INSERT
    $stmtP = mysqli_prepare($con,
        "INSERT INTO postulantes
            (descripcion_discapacidad, esfuerzo_fisico_posible, experiencia, habilidades, id_usuario)
         VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmtP, 'sissi',
        $descripcionDiscapacidad,
        $esfuerzo_fisico,
        $experiencia,
        $habilidades,
        $id_usuario
    );
}

if (!mysqli_stmt_execute($stmtP)) {
    mysqli_stmt_close($stmtP);
    mysqli_close($con);
    http_response_code(500);
    echo json_encode(['success' => false, 'mensaje' => 'Error al guardar el perfil.']);
    exit;
}

// Obtener el id_postulante reciÃ©n insertado si era nuevo
if (!$postulante_existente) {
    $id_postulante = mysqli_insert_id($con);
}
mysqli_stmt_close($stmtP);

// â”€â”€ 7. Actualizar foto de perfil en usuarios â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($foto_perfil_ruta) {
    $stmtFoto = mysqli_prepare($con,
        "UPDATE usuarios SET foto_perfil = ? WHERE id_usuario = ?"
    );
    mysqli_stmt_bind_param($stmtFoto, 'si', $foto_perfil_ruta, $id_usuario);
    mysqli_stmt_execute($stmtFoto);
    mysqli_stmt_close($stmtFoto);
}

// â”€â”€ 8. Guardar tipo de discapacidad en postulante_discapacidad â”€
// Buscar el id_tipo_discapacidad por nombre
$stmtTipo = mysqli_prepare($con,
    "SELECT id_tipo_discapacidad FROM tipo_discapacidad WHERE nombre_discapacidad = ?"
);
mysqli_stmt_bind_param($stmtTipo, 's', $tipoDiscapacidad);
mysqli_stmt_execute($stmtTipo);
$resTipo = mysqli_stmt_get_result($stmtTipo);
$tipo    = mysqli_fetch_assoc($resTipo);
mysqli_stmt_close($stmtTipo);

if ($tipo) {
    $id_tipo = (int)$tipo['id_tipo_discapacidad'];

    // Borrar relaciones anteriores para este postulante
    $stmtDelTipo = mysqli_prepare($con,
        "DELETE FROM postulante_discapacidad WHERE id_postulante = ?"
    );
    mysqli_stmt_bind_param($stmtDelTipo, 'i', $id_postulante);
    mysqli_stmt_execute($stmtDelTipo);
    mysqli_stmt_close($stmtDelTipo);

    // Insertar la nueva relaciÃ³n
    $stmtInsTipo = mysqli_prepare($con,
        "INSERT INTO postulante_discapacidad (id_postulante, id_tipo_discapacidad) VALUES (?, ?)"
    );
    mysqli_stmt_bind_param($stmtInsTipo, 'ii', $id_postulante, $id_tipo);
    mysqli_stmt_execute($stmtInsTipo);
    mysqli_stmt_close($stmtInsTipo);
}
// Si el tipo no estÃ¡ en la tabla (ej: "Otra", "Prefiero no decirlo"), simplemente no se guarda relaciÃ³n

mysqli_close($con);

echo json_encode([
    'success'      => true,
    'mensaje'      => 'Perfil guardado correctamente.',
    'id_postulante'=> $id_postulante
]);




<?php
// ============================================================
//  guardar_formulario.php
//  Guarda los datos del formulario de evaluación del postulante.
//
//  Método: POST (multipart/form-data)
//  Campos esperados:
//    - id_usuario         (int)    — hardcodeado a 1 por ahora
//    - tipoDiscapacidad   (string) — nombre del tipo de discapacidad
//    - descripcionDiscapacidad (string)
//    - esfuerzoFisico     (string) — "Sí" | "Con algunas limitaciones" | "No"
//    - experiencia        (string)
//    - habilidades        (string)
//    - fotoPerfil         (file)   — imagen opcional
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

// ── 1. ID de usuario (hardcodeado hasta tener login) ─────────
$id_usuario = 1;

// ── 2. Leer campos del formulario ────────────────────────────
$tipoDiscapacidad        = trim($_POST['tipoDiscapacidad']        ?? '');
$descripcionDiscapacidad = trim($_POST['descripcionDiscapacidad'] ?? '');
$esfuerzoFisicoRaw       = trim($_POST['esfuerzoFisico']          ?? '');
$experiencia             = trim($_POST['experiencia']             ?? '');
$habilidades             = trim($_POST['habilidades']             ?? '');

// Convertir esfuerzo físico a tinyint(1)
// "Sí" → 1 | "Con algunas limitaciones" → 1 | "No" → 0
$esfuerzo_fisico = ($esfuerzoFisicoRaw === 'No') ? 0 : 1;

// ── 3. Validaciones básicas ───────────────────────────────────
if (empty($tipoDiscapacidad)) {
    echo json_encode(['success' => false, 'mensaje' => 'Debes seleccionar un tipo de discapacidad.']);
    exit;
}

if (empty($descripcionDiscapacidad)) {
    echo json_encode(['success' => false, 'mensaje' => 'La descripción de la discapacidad es requerida.']);
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

// ── 4. Foto de perfil (opcional) ─────────────────────────────
$foto_perfil_ruta = null;

if (!empty($_FILES['fotoPerfil']['name'])) {
    $archivo    = $_FILES['fotoPerfil'];
    $extensiones = ['jpg', 'jpeg', 'png', 'webp'];
    $ext        = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $extensiones)) {
        echo json_encode(['success' => false, 'mensaje' => 'Formato de imagen no válido. Usa JPG, PNG o WEBP.']);
        exit;
    }

    if ($archivo['size'] > 5 * 1024 * 1024) { // 5 MB máximo
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

$con = conectarbd();

// ── 5. Verificar si ya existe un postulante para este usuario ─
$stmtCheck = mysqli_prepare($con, "SELECT id_postulante FROM postulantes WHERE id_usuario = ?");
mysqli_stmt_bind_param($stmtCheck, 'i', $id_usuario);
mysqli_stmt_execute($stmtCheck);
$resCheck = mysqli_stmt_get_result($stmtCheck);
$postulante_existente = mysqli_fetch_assoc($resCheck);
mysqli_stmt_close($stmtCheck);

// ── 6. Insertar o actualizar postulante ───────────────────────
if ($postulante_existente) {
    // Ya existe → UPDATE
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
    // No existe → INSERT
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

// Obtener el id_postulante recién insertado si era nuevo
if (!$postulante_existente) {
    $id_postulante = mysqli_insert_id($con);
}
mysqli_stmt_close($stmtP);

// ── 7. Actualizar foto de perfil en usuarios ──────────────────
if ($foto_perfil_ruta) {
    $stmtFoto = mysqli_prepare($con,
        "UPDATE usuarios SET foto_perfil = ? WHERE id_usuario = ?"
    );
    mysqli_stmt_bind_param($stmtFoto, 'si', $foto_perfil_ruta, $id_usuario);
    mysqli_stmt_execute($stmtFoto);
    mysqli_stmt_close($stmtFoto);
}

// ── 8. Guardar tipo de discapacidad en postulante_discapacidad ─
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

    // Insertar la nueva relación
    $stmtInsTipo = mysqli_prepare($con,
        "INSERT INTO postulante_discapacidad (id_postulante, id_tipo_discapacidad) VALUES (?, ?)"
    );
    mysqli_stmt_bind_param($stmtInsTipo, 'ii', $id_postulante, $id_tipo);
    mysqli_stmt_execute($stmtInsTipo);
    mysqli_stmt_close($stmtInsTipo);
}
// Si el tipo no está en la tabla (ej: "Otra", "Prefiero no decirlo"), simplemente no se guarda relación

mysqli_close($con);

echo json_encode([
    'success'      => true,
    'mensaje'      => 'Perfil guardado correctamente.',
    'id_postulante'=> $id_postulante
]);
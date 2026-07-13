<?php

require_once __DIR__ . '/../../config/cors.php';
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

inclusijob_session_cookie_params();

session_start();

if (!in_array($_SERVER["REQUEST_METHOD"], ["GET", "POST"], true)) {
    http_response_code(405);
    echo json_encode(["success" => false, "mensaje" => "Metodo no permitido."]);
    exit;
}

if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "mensaje" => "No hay sesion activa. Inicia sesion."]);
    exit;
}

$id_usuario       = (int)$_SESSION["user"]["id"];
$id_usuario_front = (int)($_POST["id_usuario"] ?? 0);

if ($id_usuario <= 0) {
    http_response_code(401);
    echo json_encode(["success" => false, "mensaje" => "Sesion no valida."]);
    exit;
}

if ($id_usuario_front > 0 && $id_usuario_front !== $id_usuario) {
    http_response_code(403);
    echo json_encode(["success" => false, "mensaje" => "No puedes modificar el perfil de otro usuario."]);
    exit;
}

require_once __DIR__ . "/../../Conexion.php";
$con = conectarbd();
mysqli_set_charset($con, "utf8mb4");

$stmtUsuario = mysqli_prepare($con, "SELECT id_rol FROM usuarios WHERE id_usuario = ? AND estado = 1 LIMIT 1");
mysqli_stmt_bind_param($stmtUsuario, "i", $id_usuario);
mysqli_stmt_execute($stmtUsuario);
$resUsuario = mysqli_stmt_get_result($stmtUsuario);
$usuarioDb  = mysqli_fetch_assoc($resUsuario);
mysqli_stmt_close($stmtUsuario);

if (!$usuarioDb || (int)$usuarioDb["id_rol"] !== 2) {
    mysqli_close($con);
    http_response_code(403);
    echo json_encode(["success" => false, "mensaje" => "Solo los postulantes pueden usar este formulario."]);
    exit;
}

// ════════════════════════════════════════════════════════════
//  GET — verificar si el formulario ya fue completado
// ════════════════════════════════════════════════════════════
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $catalogoDiscapacidades = [];
    $resCatalogo = mysqli_query($con,
        "SELECT id_tipo_discapacidad, nombre_discapacidad, descripcion
         FROM tipo_discapacidad
         ORDER BY nombre_discapacidad ASC"
    );
    if ($resCatalogo) {
        while ($row = mysqli_fetch_assoc($resCatalogo)) {
            $catalogoDiscapacidades[] = [
                "id_tipo_discapacidad" => (int)$row["id_tipo_discapacidad"],
                "nombre_discapacidad" => $row["nombre_discapacidad"],
                "descripcion" => $row["descripcion"] ?? "",
            ];
        }
    }

    $stmtCheck = mysqli_prepare($con,
        "SELECT
            p.id_postulante,
            p.descripcion_discapacidad,
            p.esfuerzo_fisico_posible,
            p.experiencia,
            p.habilidades
         FROM postulantes p
         WHERE p.id_usuario = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmtCheck, "i", $id_usuario);
    mysqli_stmt_execute($stmtCheck);
    $resCheck   = mysqli_stmt_get_result($stmtCheck);
    $postulante = mysqli_fetch_assoc($resCheck);
    mysqli_stmt_close($stmtCheck);

    // ── NUEVO: traer TODOS los tipos de discapacidad asociados ──
    $tiposIds = [];
    if ($postulante) {
        $stmtTipos = mysqli_prepare($con,
            "SELECT id_tipo_discapacidad FROM postulante_discapacidad WHERE id_postulante = ?"
        );
        mysqli_stmt_bind_param($stmtTipos, "i", $postulante["id_postulante"]);
        mysqli_stmt_execute($stmtTipos);
        $resTipos = mysqli_stmt_get_result($stmtTipos);
        while ($row = mysqli_fetch_assoc($resTipos)) {
            $tiposIds[] = (int)$row["id_tipo_discapacidad"];
        }
        mysqli_stmt_close($stmtTipos);
    }

    mysqli_close($con);

    $completado = false;
    if ($postulante) {
        $completado =
            trim((string)($postulante["descripcion_discapacidad"] ?? "")) !== "" &&
            trim((string)($postulante["experiencia"]              ?? "")) !== "" &&
            trim((string)($postulante["habilidades"]              ?? "")) !== "" &&
            $postulante["esfuerzo_fisico_posible"] !== null &&
            !empty($tiposIds);
    }

    echo json_encode([
        "success"             => true,
        "completado"          => $completado,
        "requiere_formulario" => !$completado,
        "id_postulante"       => $postulante ? (int)$postulante["id_postulante"] : null,
        "discapacidades"      => $catalogoDiscapacidades,
        "tipos_discapacidad"  => $tiposIds, // ← ids ya seleccionados
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════
//  POST — guardar formulario
// ════════════════════════════════════════════════════════════

// ── NUEVO: tipoDiscapacidad ahora llega como arreglo (checkboxes) ──
$tiposDiscapacidad = $_POST["tipoDiscapacidad"] ?? [];
if (!is_array($tiposDiscapacidad)) {
    $tiposDiscapacidad = [$tiposDiscapacidad];
}
$tiposDiscapacidad = array_values(array_unique(array_filter(array_map('trim', $tiposDiscapacidad))));

$tiposDiscapacidadIds = [];
foreach ($tiposDiscapacidad as $tipoRaw) {
    if (is_numeric($tipoRaw)) {
        $tiposDiscapacidadIds[] = (int)$tipoRaw;
        continue;
    }

    $stmtTipoNombre = mysqli_prepare($con,
        "SELECT id_tipo_discapacidad FROM tipo_discapacidad WHERE nombre_discapacidad = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($stmtTipoNombre, "s", $tipoRaw);
    mysqli_stmt_execute($stmtTipoNombre);
    $resTipoNombre = mysqli_stmt_get_result($stmtTipoNombre);
    $tipoNombre = mysqli_fetch_assoc($resTipoNombre);
    mysqli_stmt_close($stmtTipoNombre);

    if ($tipoNombre) {
        $tiposDiscapacidadIds[] = (int)$tipoNombre["id_tipo_discapacidad"];
    }
}
$tiposDiscapacidadIds = array_values(array_unique(array_filter($tiposDiscapacidadIds, fn($id) => $id > 0)));

$descripcionDiscapacidad = trim($_POST["descripcionDiscapacidad"] ?? "");
$esfuerzoFisicoRaw       = trim($_POST["esfuerzoFisico"]          ?? "");
$experiencia             = trim($_POST["experiencia"]             ?? "");
$habilidades             = trim($_POST["habilidades"]             ?? "");
$esfuerzo_fisico         = ($esfuerzoFisicoRaw === "No") ? 0 : 1;

// ── Validaciones de texto ─────────────────────────────────
if (empty($tiposDiscapacidadIds)) {
    echo json_encode(["success" => false, "mensaje" => "Debes seleccionar al menos un tipo de discapacidad."]);
    exit;
}

$placeholdersTipos = implode(',', array_fill(0, count($tiposDiscapacidadIds), '?'));
$tiposBind = str_repeat('i', count($tiposDiscapacidadIds));
$stmtValidarTipos = mysqli_prepare($con, "SELECT id_tipo_discapacidad FROM tipo_discapacidad WHERE id_tipo_discapacidad IN ($placeholdersTipos)");
mysqli_stmt_bind_param($stmtValidarTipos, $tiposBind, ...$tiposDiscapacidadIds);
mysqli_stmt_execute($stmtValidarTipos);
$resValidarTipos = mysqli_stmt_get_result($stmtValidarTipos);
$tiposValidos = [];
while ($rowTipo = mysqli_fetch_assoc($resValidarTipos)) {
    $tiposValidos[] = (int)$rowTipo["id_tipo_discapacidad"];
}
mysqli_stmt_close($stmtValidarTipos);
sort($tiposDiscapacidadIds);
sort($tiposValidos);
if ($tiposDiscapacidadIds !== $tiposValidos) {
    echo json_encode(["success" => false, "mensaje" => "Uno o mas tipos de discapacidad no son validos."]);
    exit;
}

if ($descripcionDiscapacidad === "") {
    echo json_encode(["success" => false, "mensaje" => "La descripcion de la discapacidad es requerida."]);
    exit;
}
if ($esfuerzoFisicoRaw === "") {
    echo json_encode(["success" => false, "mensaje" => "Indica si puedes realizar esfuerzo fisico."]);
    exit;
}
if ($experiencia === "") {
    echo json_encode(["success" => false, "mensaje" => "La experiencia es requerida."]);
    exit;
}
if ($habilidades === "") {
    echo json_encode(["success" => false, "mensaje" => "Las habilidades son requeridas."]);
    exit;
}

// ── Foto obligatoria ───────────────────────────────────────
if (empty($_FILES["fotoPerfil"]["name"]) || $_FILES["fotoPerfil"]["error"] === UPLOAD_ERR_NO_FILE) {
    mysqli_close($con);
    echo json_encode(["success" => false, "mensaje" => "La foto de perfil es obligatoria."]);
    exit;
}

// ── Procesar foto ─────────────────────────────────────────
$archivo     = $_FILES["fotoPerfil"];
$extensiones = ["jpg", "jpeg", "png", "webp"];
$ext         = strtolower(pathinfo($archivo["name"], PATHINFO_EXTENSION));

if (!in_array($ext, $extensiones, true)) {
    mysqli_close($con);
    echo json_encode(["success" => false, "mensaje" => "Formato de imagen no valido. Usa JPG, PNG o WEBP."]);
    exit;
}

if ($archivo["size"] > 5 * 1024 * 1024) {
    mysqli_close($con);
    echo json_encode(["success" => false, "mensaje" => "La imagen no debe superar los 5 MB."]);
    exit;
}

$carpeta_destino = __DIR__ . "/../../uploads/fotos_perfil/";
if (!is_dir($carpeta_destino)) {
    mkdir($carpeta_destino, 0755, true);
}

$nombre_archivo = "perfil_" . $id_usuario . "_" . time() . "." . $ext;
$ruta_completa  = $carpeta_destino . $nombre_archivo;

if (!move_uploaded_file($archivo["tmp_name"], $ruta_completa)) {
    mysqli_close($con);
    echo json_encode(["success" => false, "mensaje" => "No se pudo guardar la imagen."]);
    exit;
}

$foto_perfil_ruta = "uploads/fotos_perfil/" . $nombre_archivo;

// ── Verificar si ya existe el postulante ──────────────────
$stmtCheck = mysqli_prepare($con, "SELECT id_postulante FROM postulantes WHERE id_usuario = ? LIMIT 1");
mysqli_stmt_bind_param($stmtCheck, "i", $id_usuario);
mysqli_stmt_execute($stmtCheck);
$resCheck             = mysqli_stmt_get_result($stmtCheck);
$postulante_existente = mysqli_fetch_assoc($resCheck);
mysqli_stmt_close($stmtCheck);

// ── INSERT o UPDATE ───────────────────────────────────────
if ($postulante_existente) {
    $id_postulante = (int)$postulante_existente["id_postulante"];
    $stmtP = mysqli_prepare($con,
        "UPDATE postulantes
            SET descripcion_discapacidad = ?,
                esfuerzo_fisico_posible  = ?,
                experiencia              = ?,
                habilidades              = ?
          WHERE id_postulante = ?"
    );
    mysqli_stmt_bind_param($stmtP, "sissi",
        $descripcionDiscapacidad, $esfuerzo_fisico,
        $experiencia, $habilidades, $id_postulante
    );
} else {
    $stmtP = mysqli_prepare($con,
        "INSERT INTO postulantes
            (descripcion_discapacidad, esfuerzo_fisico_posible, experiencia, habilidades, id_usuario)
         VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmtP, "sissi",
        $descripcionDiscapacidad, $esfuerzo_fisico,
        $experiencia, $habilidades, $id_usuario
    );
}

if (!mysqli_stmt_execute($stmtP)) {
    mysqli_stmt_close($stmtP);
    mysqli_close($con);
    http_response_code(500);
    echo json_encode(["success" => false, "mensaje" => "Error al guardar el perfil."]);
    exit;
}

if (!$postulante_existente) {
    $id_postulante = mysqli_insert_id($con);
}
mysqli_stmt_close($stmtP);

// ── Guardar foto en usuarios ──────────────────────────────
$stmtFoto = mysqli_prepare($con, "UPDATE usuarios SET foto_perfil = ? WHERE id_usuario = ?");
mysqli_stmt_bind_param($stmtFoto, "si", $foto_perfil_ruta, $id_usuario);
mysqli_stmt_execute($stmtFoto);
mysqli_stmt_close($stmtFoto);

// ── NUEVO: Tipos de discapacidad (múltiples) ──────────────
// Se borran los tipos previos una sola vez y se insertan todos los seleccionados
$stmtDel = mysqli_prepare($con, "DELETE FROM postulante_discapacidad WHERE id_postulante = ?");
mysqli_stmt_bind_param($stmtDel, "i", $id_postulante);
mysqli_stmt_execute($stmtDel);
mysqli_stmt_close($stmtDel);

foreach ($tiposDiscapacidadIds as $id_tipo) {
    $stmtIns = mysqli_prepare($con,
        "INSERT INTO postulante_discapacidad (id_postulante, id_tipo_discapacidad) VALUES (?, ?)"
    );
    mysqli_stmt_bind_param($stmtIns, "ii", $id_postulante, $id_tipo);
    mysqli_stmt_execute($stmtIns);
    mysqli_stmt_close($stmtIns);
}

mysqli_close($con);

echo json_encode([
    "success"       => true,
    "mensaje"       => "Perfil guardado correctamente.",
    "id_postulante" => $id_postulante,
]);

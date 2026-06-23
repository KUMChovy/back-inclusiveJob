<?php

session_set_cookie_params([
    "httponly"  => true,
    "secure"    => false, // cambiar a true en producción con HTTPS
    "samesite"  => "Strict"
]);

session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Pre-flight CORS
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "mensaje" => "Método no permitido."]);
    exit;
}

// ── 1. Verificar sesión activa ───────────────────────────────
if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "mensaje" => "No hay sesión activa. Inicia sesión."]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

// ── 2. Leer campos del formulario ────────────────────────────
$tipoDiscapacidad        = trim($_POST["tipoDiscapacidad"]        ?? "");
$descripcionDiscapacidad = trim($_POST["descripcionDiscapacidad"] ?? "");
$esfuerzoFisicoRaw       = trim($_POST["esfuerzoFisico"]          ?? "");
$experiencia             = trim($_POST["experiencia"]             ?? "");
$habilidades             = trim($_POST["habilidades"]             ?? "");

// Convertir esfuerzo físico → tinyint(1)
// "No" → 0 | "Sí" o "Con algunas limitaciones" → 1
$esfuerzo_fisico = ($esfuerzoFisicoRaw === "No") ? 0 : 1;

// ── 3. Validaciones básicas ───────────────────────────────────
if (empty($tipoDiscapacidad)) {
    echo json_encode(["success" => false, "mensaje" => "Debes seleccionar un tipo de discapacidad."]);
    exit;
}
if (empty($descripcionDiscapacidad)) {
    echo json_encode(["success" => false, "mensaje" => "La descripción de la discapacidad es requerida."]);
    exit;
}
if (empty($esfuerzoFisicoRaw)) {
    echo json_encode(["success" => false, "mensaje" => "Indica si puedes realizar esfuerzo físico."]);
    exit;
}
if (empty($experiencia)) {
    echo json_encode(["success" => false, "mensaje" => "La experiencia es requerida."]);
    exit;
}
if (empty($habilidades)) {
    echo json_encode(["success" => false, "mensaje" => "Las habilidades son requeridas."]);
    exit;
}

// ── 4. Foto de perfil (opcional) ─────────────────────────────
$foto_perfil_ruta = null;

if (!empty($_FILES["fotoPerfil"]["name"])) {
    $archivo     = $_FILES["fotoPerfil"];
    $extensiones = ["jpg", "jpeg", "png", "webp"];
    $ext         = strtolower(pathinfo($archivo["name"], PATHINFO_EXTENSION));

    if (!in_array($ext, $extensiones)) {
        echo json_encode(["success" => false, "mensaje" => "Formato de imagen no válido. Usa JPG, PNG o WEBP."]);
        exit;
    }
    if ($archivo["size"] > 5 * 1024 * 1024) {
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
        echo json_encode(["success" => false, "mensaje" => "No se pudo guardar la imagen."]);
        exit;
    }

    $foto_perfil_ruta = "uploads/fotos_perfil/" . $nombre_archivo;
}

// ── 5. Conectar a la BD ──────────────────────────────────────
require_once "../../conexion.php";
$con = conectarbd();

// ── 6. Verificar si ya existe un postulante para este usuario ─
$stmtCheck = mysqli_prepare($con,
    "SELECT id_postulante FROM postulantes WHERE id_usuario = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmtCheck, "i", $id_usuario);
mysqli_stmt_execute($stmtCheck);
$resCheck            = mysqli_stmt_get_result($stmtCheck);
$postulante_existente = mysqli_fetch_assoc($resCheck);
mysqli_stmt_close($stmtCheck);

// ── 7. Insertar o actualizar postulante ───────────────────────
if ($postulante_existente) {
    // Ya existe → UPDATE
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
        $descripcionDiscapacidad,
        $esfuerzo_fisico,
        $experiencia,
        $habilidades,
        $id_postulante
    );
} else {
    // No existe → INSERT
    $stmtP = mysqli_prepare($con,
        "INSERT INTO postulantes
            (descripcion_discapacidad, esfuerzo_fisico_posible, experiencia, habilidades, id_usuario)
         VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmtP, "sissi",
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
    echo json_encode(["success" => false, "mensaje" => "Error al guardar el perfil."]);
    exit;
}

if (!$postulante_existente) {
    $id_postulante = mysqli_insert_id($con);
}
mysqli_stmt_close($stmtP);

// ── 8. Actualizar foto de perfil en usuarios ──────────────────
if ($foto_perfil_ruta) {
    $stmtFoto = mysqli_prepare($con,
        "UPDATE usuarios SET foto_perfil = ? WHERE id_usuario = ?"
    );
    mysqli_stmt_bind_param($stmtFoto, "si", $foto_perfil_ruta, $id_usuario);
    mysqli_stmt_execute($stmtFoto);
    mysqli_stmt_close($stmtFoto);
}

// ── 9. Guardar tipo de discapacidad en postulante_discapacidad ─
// "Otra" y "Prefiero no decirlo" no están en la tabla → se omiten sin error
$stmtTipo = mysqli_prepare($con,
    "SELECT id_tipo_discapacidad FROM tipo_discapacidad WHERE nombre_discapacidad = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmtTipo, "s", $tipoDiscapacidad);
mysqli_stmt_execute($stmtTipo);
$resTipo = mysqli_stmt_get_result($stmtTipo);
$tipo    = mysqli_fetch_assoc($resTipo);
mysqli_stmt_close($stmtTipo);

if ($tipo) {
    $id_tipo = (int)$tipo["id_tipo_discapacidad"];

    // Borrar relaciones anteriores para este postulante
    $stmtDel = mysqli_prepare($con,
        "DELETE FROM postulante_discapacidad WHERE id_postulante = ?"
    );
    mysqli_stmt_bind_param($stmtDel, "i", $id_postulante);
    mysqli_stmt_execute($stmtDel);
    mysqli_stmt_close($stmtDel);

    // Insertar la nueva relación
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
    "id_postulante" => $id_postulante
]);
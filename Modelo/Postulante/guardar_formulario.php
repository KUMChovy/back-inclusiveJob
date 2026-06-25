<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

session_set_cookie_params([
    "httponly" => true,
    "secure"   => false,
    "samesite" => "Strict"
]);

session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "mensaje" => "Metodo no permitido."]);
    exit;
}

if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "mensaje" => "No hay sesion activa. Inicia sesion."]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];
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

$tipoDiscapacidad        = trim($_POST["tipoDiscapacidad"] ?? "");
$descripcionDiscapacidad = trim($_POST["descripcionDiscapacidad"] ?? "");
$esfuerzoFisicoRaw       = trim($_POST["esfuerzoFisico"] ?? "");
$experiencia             = trim($_POST["experiencia"] ?? "");
$habilidades             = trim($_POST["habilidades"] ?? "");
$esfuerzo_fisico         = ($esfuerzoFisicoRaw === "No") ? 0 : 1;

if ($tipoDiscapacidad === "") {
    echo json_encode(["success" => false, "mensaje" => "Debes seleccionar un tipo de discapacidad."]);
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

require_once __DIR__ . "/../../Conexion.php";
$con = conectarbd();
mysqli_set_charset($con, "utf8mb4");

$stmtUsuario = mysqli_prepare($con, "SELECT id_rol FROM usuarios WHERE id_usuario = ? AND estado = 1 LIMIT 1");
mysqli_stmt_bind_param($stmtUsuario, "i", $id_usuario);
mysqli_stmt_execute($stmtUsuario);
$resUsuario = mysqli_stmt_get_result($stmtUsuario);
$usuarioDb = mysqli_fetch_assoc($resUsuario);
mysqli_stmt_close($stmtUsuario);

if (!$usuarioDb || (int)$usuarioDb["id_rol"] !== 2) {
    mysqli_close($con);
    http_response_code(403);
    echo json_encode(["success" => false, "mensaje" => "Solo los postulantes pueden guardar este formulario."]);
    exit;
}

$foto_perfil_ruta = null;

if (!empty($_FILES["fotoPerfil"]["name"])) {
    $archivo = $_FILES["fotoPerfil"];
    $extensiones = ["jpg", "jpeg", "png", "webp"];
    $ext = strtolower(pathinfo($archivo["name"], PATHINFO_EXTENSION));

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
    $ruta_completa = $carpeta_destino . $nombre_archivo;

    if (!move_uploaded_file($archivo["tmp_name"], $ruta_completa)) {
        mysqli_close($con);
        echo json_encode(["success" => false, "mensaje" => "No se pudo guardar la imagen."]);
        exit;
    }

    $foto_perfil_ruta = "uploads/fotos_perfil/" . $nombre_archivo;
}

$stmtCheck = mysqli_prepare($con, "SELECT id_postulante FROM postulantes WHERE id_usuario = ? LIMIT 1");
mysqli_stmt_bind_param($stmtCheck, "i", $id_usuario);
mysqli_stmt_execute($stmtCheck);
$resCheck = mysqli_stmt_get_result($stmtCheck);
$postulante_existente = mysqli_fetch_assoc($resCheck);
mysqli_stmt_close($stmtCheck);

if ($postulante_existente) {
    $id_postulante = (int)$postulante_existente["id_postulante"];
    $stmtP = mysqli_prepare($con,
        "UPDATE postulantes
            SET descripcion_discapacidad = ?,
                esfuerzo_fisico_posible = ?,
                experiencia = ?,
                habilidades = ?
          WHERE id_postulante = ?"
    );
    mysqli_stmt_bind_param($stmtP, "sissi", $descripcionDiscapacidad, $esfuerzo_fisico, $experiencia, $habilidades, $id_postulante);
} else {
    $stmtP = mysqli_prepare($con,
        "INSERT INTO postulantes
            (descripcion_discapacidad, esfuerzo_fisico_posible, experiencia, habilidades, id_usuario)
         VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmtP, "sissi", $descripcionDiscapacidad, $esfuerzo_fisico, $experiencia, $habilidades, $id_usuario);
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

if ($foto_perfil_ruta) {
    $stmtFoto = mysqli_prepare($con, "UPDATE usuarios SET foto_perfil = ? WHERE id_usuario = ?");
    mysqli_stmt_bind_param($stmtFoto, "si", $foto_perfil_ruta, $id_usuario);
    mysqli_stmt_execute($stmtFoto);
    mysqli_stmt_close($stmtFoto);
}

$stmtTipo = mysqli_prepare($con, "SELECT id_tipo_discapacidad FROM tipo_discapacidad WHERE nombre_discapacidad = ? LIMIT 1");
mysqli_stmt_bind_param($stmtTipo, "s", $tipoDiscapacidad);
mysqli_stmt_execute($stmtTipo);
$resTipo = mysqli_stmt_get_result($stmtTipo);
$tipo = mysqli_fetch_assoc($resTipo);
mysqli_stmt_close($stmtTipo);

if ($tipo) {
    $id_tipo = (int)$tipo["id_tipo_discapacidad"];

    $stmtDel = mysqli_prepare($con, "DELETE FROM postulante_discapacidad WHERE id_postulante = ?");
    mysqli_stmt_bind_param($stmtDel, "i", $id_postulante);
    mysqli_stmt_execute($stmtDel);
    mysqli_stmt_close($stmtDel);

    $stmtIns = mysqli_prepare($con, "INSERT INTO postulante_discapacidad (id_postulante, id_tipo_discapacidad) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmtIns, "ii", $id_postulante, $id_tipo);
    mysqli_stmt_execute($stmtIns);
    mysqli_stmt_close($stmtIns);
}

mysqli_close($con);

echo json_encode([
    "success" => true,
    "mensaje" => "Perfil guardado correctamente.",
    "id_postulante" => $id_postulante
]);
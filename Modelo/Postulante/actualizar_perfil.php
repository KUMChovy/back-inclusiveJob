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

session_set_cookie_params(["httponly" => true, "secure" => false, "samesite" => "Strict"]);
session_start();

if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["ok" => false, "msg" => "No hay sesion activa."]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "msg" => "Metodo no permitido."]);
    exit;
}

require_once __DIR__ . "/../../Conexion.php";
$con = conectarbd();
mysqli_set_charset($con, "utf8mb4");

$stmt = mysqli_prepare($con,
    "SELECT u.id_rol, p.id_postulante
     FROM usuarios u
     LEFT JOIN postulantes p ON p.id_usuario = u.id_usuario
     WHERE u.id_usuario = ? AND u.estado = 1 LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $id_usuario);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$row || (int)$row["id_rol"] !== 2) {
    mysqli_close($con);
    http_response_code(403);
    echo json_encode(["ok" => false, "msg" => "Solo postulantes pueden usar este formulario."]);
    exit;
}
if (!$row["id_postulante"]) {
    mysqli_close($con);
    http_response_code(404);
    echo json_encode(["ok" => false, "msg" => "No tienes perfil creado."]);
    exit;
}

$id_postulante = (int)$row["id_postulante"];

function limpiar($v) {
    return htmlspecialchars(strip_tags(trim((string)$v)), ENT_QUOTES, "UTF-8");
}

// ── Leer campos de $_POST (FormData) ─────────────────────────
$nombres   = limpiar($_POST["nombres"]   ?? "");
$apellidos = limpiar($_POST["apellidos"] ?? "");
$telefono  = limpiar($_POST["telefono"]  ?? "");
$experiencia = limpiar($_POST["experiencia"] ?? "");
$portafolio  = limpiar($_POST["portafolio_url"] ?? "");
$esfuerzo    = isset($_POST["esfuerzo_fisico_posible"]) ? (int)$_POST["esfuerzo_fisico_posible"] : 0;

// Skills llegan como JSON string
$habilidades = "";
$skillsRaw = $_POST["skills"] ?? "";
if ($skillsRaw !== "") {
    $skillsArr = json_decode($skillsRaw, true);
    if (is_array($skillsArr)) {
        $habilidades = json_encode($skillsArr, JSON_UNESCAPED_UNICODE);
    }
}

// Discapacidades llegan como JSON string
$discapacidades = [];
$discRaw = $_POST["discapacidad"] ?? "";
if ($discRaw !== "") {
    $discArr = json_decode($discRaw, true);
    if (is_array($discArr)) $discapacidades = $discArr;
}

// descripcion_discapacidad = nota + porcentaje
$nota       = limpiar($_POST["nota"]       ?? "");
$porcentaje = limpiar($_POST["porcentaje"] ?? "");
$desc_disc  = $nota;
if ($porcentaje !== "") {
    $desc_disc = $nota !== "" ? $nota . " | " . $porcentaje . "%" : $porcentaje . "%";
}

// ── Foto de perfil (opcional) ─────────────────────────────────
$foto_perfil_ruta = null;

if (!empty($_FILES["fotoPerfil"]["name"])) {
    $archivo     = $_FILES["fotoPerfil"];
    $extensiones = ["jpg", "jpeg", "png", "webp"];
    $ext         = strtolower(pathinfo($archivo["name"], PATHINFO_EXTENSION));

    if (!in_array($ext, $extensiones, true)) {
        // ── ACTUALIZAR FOTO DE PERFIL ────────────────────────────────
        if ($foto_perfil_ruta) {

            $stmtFoto = mysqli_prepare(
                $con,
                "UPDATE usuarios
                    SET foto_perfil = ?
                WHERE id_usuario = ?"
            );

            mysqli_stmt_bind_param(
                $stmtFoto,
                "si",
                $foto_perfil_ruta,
                $id_usuario
            );

            mysqli_stmt_execute($stmtFoto);
            mysqli_stmt_close($stmtFoto);
        }
        mysqli_close($con);
        echo json_encode(["ok" => false, "msg" => "Formato no valido. Usa JPG, PNG o WEBP."]);
        exit;
    }
    if ($archivo["size"] > 5 * 1024 * 1024) {
        mysqli_close($con);
        echo json_encode(["ok" => false, "msg" => "La imagen no debe superar 5 MB."]);
        exit;
    }

    $carpeta = __DIR__ . "/../../uploads/fotos_perfil/";
    if (!is_dir($carpeta)) mkdir($carpeta, 0755, true);

    $nombre_archivo = "perfil_" . $id_usuario . "_" . time() . "." . $ext;
    if (!move_uploaded_file($archivo["tmp_name"], $carpeta . $nombre_archivo)) {
        mysqli_close($con);
        echo json_encode(["ok" => false, "msg" => "No se pudo guardar la imagen."]);
        exit;
    }

    $foto_perfil_ruta = "uploads/fotos_perfil/" . $nombre_archivo;
}

// ── UPDATE usuarios ───────────────────────────────────────────
// ── UPDATE usuarios ───────────────────────────────────────────
$stmtU = mysqli_prepare(
    $con,
    "UPDATE usuarios
        SET nombres = ?,
            apellidos = ?,
            telefono = ?
     WHERE id_usuario = ?"
);

mysqli_stmt_bind_param(
    $stmtU,
    "sssi",
    $nombres,
    $apellidos,
    $telefono,
    $id_usuario
);

mysqli_stmt_execute($stmtU);
mysqli_stmt_close($stmtU);


$_SESSION["user"]["nombre"]   = $nombres;
$_SESSION["user"]["apellido"] = $apellidos;

// ── UPDATE postulantes ────────────────────────────────────────
$stmtP = mysqli_prepare($con,
    "UPDATE postulantes
        SET descripcion_discapacidad = ?,
            esfuerzo_fisico_posible  = ?,
            experiencia              = ?,
            habilidades              = ?,
            portafolio_url           = ?
      WHERE id_postulante = ?"
);
mysqli_stmt_bind_param($stmtP, "sisssi",
    $desc_disc, $esfuerzo, $experiencia, $habilidades, $portafolio, $id_postulante
);

if (!mysqli_stmt_execute($stmtP)) {
    mysqli_stmt_close($stmtP);
    mysqli_close($con);
    http_response_code(500);
    echo json_encode(["ok" => false, "msg" => "Error al actualizar el perfil."]);
    exit;
}
mysqli_stmt_close($stmtP);

// ── Discapacidades: DELETE + INSERT ──────────────────────────
$stmtDel = mysqli_prepare($con, "DELETE FROM postulante_discapacidad WHERE id_postulante = ?");
mysqli_stmt_bind_param($stmtDel, "i", $id_postulante);
mysqli_stmt_execute($stmtDel);
mysqli_stmt_close($stmtDel);

if (!empty($discapacidades)) {
    $stmtTipo = mysqli_prepare($con,
        "SELECT id_tipo_discapacidad FROM tipo_discapacidad WHERE nombre_discapacidad = ? LIMIT 1"
    );
    $stmtIns = mysqli_prepare($con,
        "INSERT IGNORE INTO postulante_discapacidad (id_postulante, id_tipo_discapacidad) VALUES (?, ?)"
    );
    foreach ($discapacidades as $nombreDisc) {
        $nombreDisc = limpiar($nombreDisc);
        mysqli_stmt_bind_param($stmtTipo, "s", $nombreDisc);
        mysqli_stmt_execute($stmtTipo);
        $tipo = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTipo));
        mysqli_stmt_free_result($stmtTipo);
        if ($tipo) {
            $id_tipo = (int)$tipo["id_tipo_discapacidad"];
            mysqli_stmt_bind_param($stmtIns, "ii", $id_postulante, $id_tipo);
            mysqli_stmt_execute($stmtIns);
        }
    }
    mysqli_stmt_close($stmtTipo);
    mysqli_stmt_close($stmtIns);
}

mysqli_close($con);
echo json_encode([
    "ok"         => true,
    "msg"        => "Perfil actualizado correctamente.",
    "foto_perfil" => $foto_perfil_ruta
    ? "[http://localhost/inclusijob_back/back-inclusiveJob/](http://localhost/inclusijob_back/back-inclusiveJob/)" . $foto_perfil_ruta
    : null
]);
?>



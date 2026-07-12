<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "mensaje" => "No hay sesion activa."]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

require_once __DIR__ . "/../../Conexion.php";
$con = conectarbd();
mysqli_set_charset($con, "utf8mb4");

// ── Datos de usuario + postulante ────────────────────────────
$stmt = mysqli_prepare($con,
    "SELECT
        u.nombres,
        u.apellidos,
        u.correo,
        u.telefono,
        u.foto_perfil,
        r.nombre_rol,
        p.id_postulante,
        p.descripcion_discapacidad,
        p.esfuerzo_fisico_posible,
        p.experiencia,
        p.habilidades,
        p.portafolio_url
     FROM usuarios u
     INNER JOIN rol r ON r.id_rol = u.id_rol
     LEFT JOIN postulantes p ON p.id_usuario = u.id_usuario
     WHERE u.id_usuario = ? AND u.estado = 1
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $id_usuario);
mysqli_stmt_execute($stmt);
$res  = mysqli_stmt_get_result($stmt);
$fila = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$fila) {
    mysqli_close($con);
    http_response_code(404);
    echo json_encode(["success" => false, "mensaje" => "Usuario no encontrado."]);
    exit;
}

// ── Tipo de discapacidad del postulante ──────────────────────
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
    mysqli_free_result($resCatalogo);
}

$discapacidades = [];
$discapacidadIds = [];

if ($fila["id_postulante"]) {
    $id_postulante = (int)$fila["id_postulante"];

    $stmtDisc = mysqli_prepare($con,
        "SELECT td.id_tipo_discapacidad, td.nombre_discapacidad
         FROM postulante_discapacidad pd
         INNER JOIN tipo_discapacidad td ON td.id_tipo_discapacidad = pd.id_tipo_discapacidad
         WHERE pd.id_postulante = ?"
    );
    mysqli_stmt_bind_param($stmtDisc, "i", $id_postulante);
    mysqli_stmt_execute($stmtDisc);
    $resDisc = mysqli_stmt_get_result($stmtDisc);
    while ($row = mysqli_fetch_assoc($resDisc)) {
        $discapacidadIds[] = (int)$row["id_tipo_discapacidad"];
        $discapacidades[] = $row["nombre_discapacidad"];
    }
    mysqli_stmt_close($stmtDisc);
}

mysqli_close($con);

echo json_encode([
    "success"   => true,
    "nombres"   => $fila["nombres"]   ?? "",
    "apellidos" => $fila["apellidos"] ?? "",
    "correo"    => $fila["correo"]    ?? "",
    "telefono"  => $fila["telefono"]  ?? "",
    "foto_perfil"             => $fila["foto_perfil"]             ?? null,
    "rol"                     => $fila["nombre_rol"]              ?? "",
    "id_postulante"           => $fila["id_postulante"]           ? (int)$fila["id_postulante"] : null,
    "descripcion_discapacidad"=> $fila["descripcion_discapacidad"]?? "",
    "esfuerzo_fisico_posible" => (int)($fila["esfuerzo_fisico_posible"] ?? 0),
    "experiencia"             => $fila["experiencia"]             ?? "",
    "habilidades"             => $fila["habilidades"]             ?? "",
    "portafolio_url"          => $fila["portafolio_url"]          ?? "",
    "discapacidad"            => $discapacidades,
    "discapacidad_ids"        => $discapacidadIds,
    "catalogo_discapacidades" => $catalogoDiscapacidades,
]);

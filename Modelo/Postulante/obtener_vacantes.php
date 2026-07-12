<?php
// ============================================================
//  obtener_vacantes.php
//  Devuelve las vacantes activas compatibles con la discapacidad
//  del postulante autenticado.
//
//  Flujo:
//    1. Verifica sesión activa → obtiene id_usuario
//    2. Busca el id_postulante del usuario
//    3. Obtiene los id_tipo_discapacidad del postulante
//    4. Retorna vacantes activas que tengan al menos una de esas
//       discapacidades en vacante_discapacidad, con los nombres
//       de las discapacidades incluidas en cada vacante.
//
//  Método: GET
//  Respuesta JSON:
//    { "auth": false }
//    { "auth": true, "vacantes": [ {...}, ... ] }
// ============================================================

session_set_cookie_params([
    "httponly"  => true,
    "secure"    => false, // cambiar a true en HTTPS
    "samesite"  => "Strict"
]);

session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ── 1. Verificar sesión ──────────────────────────────────────
if (!isset($_SESSION["user"]["id"])) {
    echo json_encode(["auth" => false]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

require_once "../../conexion.php";
$con = conectarbd();

// ── 2. Obtener id_postulante ─────────────────────────────────
$stmtP = mysqli_prepare($con,
    "SELECT id_postulante FROM postulantes WHERE id_usuario = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmtP, "i", $id_usuario);
mysqli_stmt_execute($stmtP);
$resP       = mysqli_stmt_get_result($stmtP);
$postulante = mysqli_fetch_assoc($resP);
mysqli_stmt_close($stmtP);

if (!$postulante) {
    // El usuario no tiene perfil de postulante todavía
    echo json_encode(["auth" => true, "vacantes" => []]);
    mysqli_close($con);
    exit;
}

$id_postulante = (int)$postulante["id_postulante"];

//obtener postulacion
$stmtPost = mysqli_prepare($con,
"SELECT id_vacante 
 FROM postulaciones 
 WHERE id_postulante = ?"
);

mysqli_stmt_bind_param($stmtPost, "i", $id_postulante);
mysqli_stmt_execute($stmtPost);
$resPost = mysqli_stmt_get_result($stmtPost);

$postuladas = [];

while ($row = mysqli_fetch_assoc($resPost)) {
    $postuladas[(int)$row["id_vacante"]] = true;
}

mysqli_stmt_close($stmtPost);


//obtener reporte
$stmtRep = mysqli_prepare($con,
"SELECT id_vacante 
 FROM reporte_vacante 
 WHERE id_usuario = ?"
);

mysqli_stmt_bind_param($stmtRep, "i", $id_usuario);
mysqli_stmt_execute($stmtRep);
$resRep = mysqli_stmt_get_result($stmtRep);

$reportadas = [];

while ($row = mysqli_fetch_assoc($resRep)) {
    $reportadas[(int)$row["id_vacante"]] = true;
}

mysqli_stmt_close($stmtRep);

// ── 3. Obtener tipos de discapacidad del postulante ──────────
$stmtD = mysqli_prepare($con,
    "SELECT id_tipo_discapacidad FROM postulante_discapacidad WHERE id_postulante = ?"
);
mysqli_stmt_bind_param($stmtD, "i", $id_postulante);
mysqli_stmt_execute($stmtD);
$resD  = mysqli_stmt_get_result($stmtD);
$tipos = [];
while ($row = mysqli_fetch_assoc($resD)) {
    $tipos[] = (int)$row["id_tipo_discapacidad"];
}
mysqli_stmt_close($stmtD);

if (empty($tipos)) {
    // El postulante no tiene discapacidades registradas → sin vacantes
    echo json_encode(["auth" => true, "vacantes" => []]);
    mysqli_close($con);
    exit;
}

// ── 4. Obtener vacantes activas compatibles ──────────────────
// Construir placeholders para IN (?, ?, ...)
$placeholders = implode(",", array_fill(0, count($tipos), "?"));
$types        = str_repeat("i", count($tipos));

// Traemos la vacante + reclutador.
// Usamos GROUP BY para no duplicar vacantes cuando comparten
// más de una discapacidad con el postulante.
$sqlVacantes = "
    SELECT DISTINCT
        v.id_vacante,
        v.titulo_puesto,
        v.descripcion_puesto,
        v.requisitos,
        v.modalidad,
        v.salario_min,
        v.salario_max,
        v.estado,
        v.fecha_publicacion,
        v.fecha_cierre,
        v.id_reclutador,
        CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS reclutador_nombre,
        u.correo AS reclutador_correo,
        u.telefono AS reclutador_telefono,
        u.foto_perfil AS reclutador_foto,
        COALESCE(r.puesto, 'Reclutador') AS reclutador_puesto,
        e.id_empresas,
        e.nombre_empresas AS empresa,
        e.descripcion AS empresa_descripcion,
        e.direccion AS empresa_direccion,
        e.telefono_empresa,
        e.correo_empresa,
        e.sitio_web AS empresa_sitio_web,
        e.estado_validacion AS empresa_estado_validacion
    FROM vacantes v
    INNER JOIN vacante_discapacidad vd ON v.id_vacante = vd.id_vacante
    INNER JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
    INNER JOIN empresas e ON e.id_empresas = r.id_empresas
    INNER JOIN usuarios u ON u.id_usuario = r.id_usuario
    WHERE v.estado = 'activa'
      AND COALESCE(e.estado_validacion, 0) = 1
      AND vd.id_tipo_discapacidad IN ($placeholders)
    ORDER BY v.fecha_publicacion DESC
";

$stmtV = mysqli_prepare($con, $sqlVacantes);
mysqli_stmt_bind_param($stmtV, $types, ...$tipos);
mysqli_stmt_execute($stmtV);
$resV     = mysqli_stmt_get_result($stmtV);
$vacantes = [];

while ($v = mysqli_fetch_assoc($resV)) {
    $vacantes[] = $v;
}
mysqli_stmt_close($stmtV);

if (empty($vacantes)) {
    echo json_encode(["auth" => true, "vacantes" => []]);
    mysqli_close($con);
    exit;
}

// ── 5. Para cada vacante, obtener sus discapacidades ─────────
// Hacemos una sola consulta para todos los ids de vacante
$idsVacantes         = array_column($vacantes, "id_vacante");
$placeholdersV       = implode(",", array_fill(0, count($idsVacantes), "?"));
$typesV              = str_repeat("i", count($idsVacantes));

$sqlDisc = "
    SELECT vd.id_vacante, td.nombre_discapacidad
    FROM vacante_discapacidad vd
    INNER JOIN tipo_discapacidad td ON vd.id_tipo_discapacidad = td.id_tipo_discapacidad
    WHERE vd.id_vacante IN ($placeholdersV)
";

$stmtDisc = mysqli_prepare($con, $sqlDisc);
mysqli_stmt_bind_param($stmtDisc, $typesV, ...$idsVacantes);
mysqli_stmt_execute($stmtDisc);
$resDisc  = mysqli_stmt_get_result($stmtDisc);

// Indexar discapacidades por id_vacante
$discPorVacante = [];
while ($row = mysqli_fetch_assoc($resDisc)) {
    $discPorVacante[(int)$row["id_vacante"]][] = $row["nombre_discapacidad"];
}
mysqli_stmt_close($stmtDisc);
mysqli_close($con);

// ── 6. Combinar datos y formatear respuesta ──────────────────
foreach ($vacantes as &$v) {
    $id = (int)$v["id_vacante"];

    // Discapacidades de la vacante
    $v["discapacidades"] = $discPorVacante[$id] ?? [];

    // Requisitos: convertir saltos de línea a array (si están en texto plano)
    if (is_string($v["requisitos"])) {
        $lineas = array_filter(
            array_map("trim", explode("\n", $v["requisitos"]))
        );
        $v["requisitos"] = array_values($lineas);
    }

    // Castear numéricos
    $v["id_vacante"]  = $id;
    $v["salario_min"] = (float)$v["salario_min"];
    $v["salario_max"] = (float)$v["salario_max"];
    $v["postulada"] = isset($postuladas[$id]);
    $v["reportada"] = isset($reportadas[$id]);
    $v["id_reclutador"] = (int)$v["id_reclutador"];
    $v["id_empresas"] = (int)$v["id_empresas"];
    $v["empresa_estado_validacion"] = (int)$v["empresa_estado_validacion"];
    $v["reclutador"] = [
        "id_reclutador" => $v["id_reclutador"],
        "nombre" => trim($v["reclutador_nombre"] ?? "") ?: "Reclutador",
        "correo" => $v["reclutador_correo"] ?? "",
        "telefono" => $v["reclutador_telefono"] ?? "",
        "foto_perfil" => $v["reclutador_foto"] ?? null,
        "puesto" => $v["reclutador_puesto"] ?? "Reclutador",
    ];
    $v["empresa_info"] = [
        "id_empresas" => $v["id_empresas"],
        "nombre" => $v["empresa"] ?? "Empresa",
        "descripcion" => $v["empresa_descripcion"] ?? "",
        "direccion" => $v["empresa_direccion"] ?? "",
        "telefono" => $v["telefono_empresa"] ?? "",
        "correo" => $v["correo_empresa"] ?? "",
        "sitio_web" => $v["empresa_sitio_web"] ?? "",
        "estado_validacion" => $v["empresa_estado_validacion"],
    ];
}
unset($v);

echo json_encode([
    "auth"     => true,
    "vacantes" => $vacantes,
]);

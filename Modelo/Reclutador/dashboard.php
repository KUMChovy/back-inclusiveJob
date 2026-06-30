<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers();

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);

$u_empresa = reclutador_columna_existe($con, "usuarios", "empresa") ? "u.empresa" : "NULL";
$u_validada = reclutador_columna_existe($con, "usuarios", "empresa_validada") ? "u.empresa_validada" : "NULL";

$stmt_user = mysqli_prepare($con, "SELECT
        u.id_usuario,
        u.nombres,
        u.apellidos,
        u.correo,
        COALESCE(e.nombre_empresas, $u_empresa, '') AS empresa,
        COALESCE(e.estado_validacion, $u_validada, 0) AS empresa_validada
    FROM usuarios u
    LEFT JOIN reclutadores r ON r.id_usuario = u.id_usuario
    LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
    WHERE u.id_usuario = ? AND u.id_rol = 3
    LIMIT 1");
mysqli_stmt_bind_param($stmt_user, "i", $id_usuario);
mysqli_stmt_execute($stmt_user);
$user_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_user));
mysqli_stmt_close($stmt_user);

if (!$user_row) {
    reclutador_responder(false, "No se encontro el usuario reclutador.", null, 404);
}

$user = [
    "id" => (int)$user_row["id_usuario"],
    "nombre" => trim(($user_row["nombres"] ?? "") . " " . ($user_row["apellidos"] ?? "")),
    "correo" => $user_row["correo"] ?? "",
    "rol" => "Reclutador"
];

$empresa_validada = (int)($user_row["empresa_validada"] ?? 0);
$empresa = [
    "nombre" => $user_row["empresa"] ?: "Empresa sin registrar",
    "aprobada" => $empresa_validada === 1,
    "estado" => $empresa_validada === 1 ? "aprobada" : "pendiente"
];

function scalar_query($con, $sql, $types, ...$params) {
    $stmt = mysqli_prepare($con, $sql);
    if ($types !== "") {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_row($res);
    mysqli_stmt_close($stmt);
    return (int)($row[0] ?? 0);
}

$stats = [
    "vacantes_activas" => scalar_query($con, "SELECT COUNT(*) FROM vacantes WHERE id_reclutador = ? AND estado = 'activa'", "i", $id_reclutador),
    "candidatos_totales" => scalar_query($con, "SELECT COUNT(DISTINCT p.id_postulante) FROM postulaciones p INNER JOIN vacantes v ON p.id_vacante = v.id_vacante WHERE v.id_reclutador = ?", "i", $id_reclutador),
    "postulaciones_hoy" => scalar_query($con, "SELECT COUNT(*) FROM postulaciones p INNER JOIN vacantes v ON p.id_vacante = v.id_vacante WHERE v.id_reclutador = ? AND DATE(p.fecha_postulacion) = CURDATE()", "i", $id_reclutador),
    "reportes_activos" => scalar_query($con, "SELECT COUNT(*) FROM reporte_vacante rv INNER JOIN vacantes v ON rv.id_vacante = v.id_vacante WHERE v.id_reclutador = ?", "i", $id_reclutador)
];

$chart = [];
for ($i = 6; $i >= 0; $i--) {
    $fecha = date("Y-m-d", strtotime("-{$i} days"));
    $postulaciones = scalar_query($con, "SELECT COUNT(*) FROM postulaciones p INNER JOIN vacantes v ON p.id_vacante = v.id_vacante WHERE v.id_reclutador = ? AND DATE(p.fecha_postulacion) = ?", "is", $id_reclutador, $fecha);
    $chart[] = [
        "day" => date("d/m", strtotime($fecha)),
        "postulaciones" => $postulaciones,
        "vistas" => 0
    ];
}

$stmt_vac = mysqli_prepare($con, "SELECT
        v.id_vacante AS id,
        v.titulo_puesto AS titulo,
        v.estado,
        v.fecha_publicacion,
        COUNT(p.id_postulacion) AS postulaciones
    FROM vacantes v
    LEFT JOIN postulaciones p ON v.id_vacante = p.id_vacante
    WHERE v.id_reclutador = ?
    GROUP BY v.id_vacante, v.titulo_puesto, v.estado, v.fecha_publicacion
    ORDER BY v.fecha_publicacion DESC
    LIMIT 5");
mysqli_stmt_bind_param($stmt_vac, "i", $id_reclutador);
mysqli_stmt_execute($stmt_vac);
$res_vac = mysqli_stmt_get_result($stmt_vac);
$vacantes_recientes = [];
while ($row = mysqli_fetch_assoc($res_vac)) {
    $row["id"] = (int)$row["id"];
    $row["postulaciones"] = (int)$row["postulaciones"];
    $row["vistas"] = 0;
    $row["dias"] = $row["fecha_publicacion"] ? max(0, (int)floor((time() - strtotime($row["fecha_publicacion"])) / 86400)) : 0;
    $vacantes_recientes[] = $row;
}
mysqli_stmt_close($stmt_vac);

$stmt_can = mysqli_prepare($con, "SELECT
        p.id_postulacion AS id,
        CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS nombre,
        v.titulo_puesto AS vacante,
        COALESCE(p.estado, 'pendiente') AS estado,
        p.fecha_postulacion
    FROM postulaciones p
    INNER JOIN vacantes v ON p.id_vacante = v.id_vacante
    INNER JOIN postulantes pos ON p.id_postulante = pos.id_postulante
    INNER JOIN usuarios u ON pos.id_usuario = u.id_usuario
    WHERE v.id_reclutador = ?
    ORDER BY p.fecha_postulacion DESC
    LIMIT 5");
mysqli_stmt_bind_param($stmt_can, "i", $id_reclutador);
mysqli_stmt_execute($stmt_can);
$res_can = mysqli_stmt_get_result($stmt_can);
$candidatos_recientes = [];
while ($row = mysqli_fetch_assoc($res_can)) {
    $row["id"] = (int)$row["id"];
    $row["tiempo"] = $row["fecha_postulacion"] ? date("d/m/Y", strtotime($row["fecha_postulacion"])) : "";
    $candidatos_recientes[] = $row;
}
mysqli_stmt_close($stmt_can);

reclutador_responder(true, "Dashboard obtenido", [
    "user" => $user,
    "empresa" => $empresa,
    "stats" => $stats,
    "chart" => $chart,
    "vacantes_recientes" => $vacantes_recientes,
    "candidatos_recientes" => $candidatos_recientes
]);
?>

<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers();

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);
$id_vacante = (int)($_GET["id"] ?? 0);
$u_empresa = reclutador_columna_existe($con, "usuarios", "empresa") ? "u.empresa" : "NULL";

if (!$id_vacante) {
    reclutador_responder(false, "ID de vacante requerido.", null, 400);
}

$sql_vacante = "SELECT
                    v.*,
                    COALESCE(e.nombre_empresas, $u_empresa, '') AS nombre_empresa
                FROM vacantes v
                INNER JOIN reclutadores r ON v.id_reclutador = r.id_reclutador
                INNER JOIN usuarios u ON r.id_usuario = u.id_usuario
                LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
                WHERE v.id_vacante = ? AND v.id_reclutador = ?
                LIMIT 1";
$stmt = mysqli_prepare($con, $sql_vacante);
mysqli_stmt_bind_param($stmt, "ii", $id_vacante, $id_reclutador);
mysqli_stmt_execute($stmt);
$vacante = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$vacante) {
    reclutador_responder(false, "No se encontro la vacante.", null, 404);
}

$sql_postulaciones = "SELECT
                        p.id_postulacion,
                        p.fecha_postulacion,
                        COALESCE(p.estado, 'pendiente') AS estado,
                        CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS postulante,
                        u.correo
                      FROM postulaciones p
                      INNER JOIN postulantes pos ON p.id_postulante = pos.id_postulante
                      INNER JOIN usuarios u ON pos.id_usuario = u.id_usuario
                      WHERE p.id_vacante = ?
                      ORDER BY p.fecha_postulacion DESC";
$stmt_post = mysqli_prepare($con, $sql_postulaciones);
mysqli_stmt_bind_param($stmt_post, "i", $id_vacante);
mysqli_stmt_execute($stmt_post);
$res_post = mysqli_stmt_get_result($stmt_post);

$postulaciones = [];
while ($row = mysqli_fetch_assoc($res_post)) {
    $postulaciones[] = $row;
}

reclutador_responder(true, "Detalle de vacante obtenido", [
    "vacante" => $vacante,
    "postulaciones" => $postulaciones
]);
?>

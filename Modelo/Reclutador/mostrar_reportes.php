<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers("GET, POST, DELETE, PUT, OPTIONS");

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);

$sql = "SELECT
            rv.id_reporte,
            rv.motivo,
            rv.fecha_reporte,
            v.id_vacante,
            v.titulo_puesto,
            v.fecha_publicacion,
            u.nombres,
            u.apellidos,
            u.correo
        FROM reporte_vacante rv
        INNER JOIN vacantes v ON rv.id_vacante = v.id_vacante
        INNER JOIN usuarios u ON rv.id_usuario = u.id_usuario
        WHERE v.id_reclutador = ?
        ORDER BY rv.fecha_reporte DESC";

$stmt = mysqli_prepare($con, $sql);
mysqli_stmt_bind_param($stmt, "i", $id_reclutador);
mysqli_stmt_execute($stmt);
$resultado = mysqli_stmt_get_result($stmt);

$reportes = [];
while ($fila = mysqli_fetch_assoc($resultado)) {
    $reportes[] = [
<<<<<<< HEAD
        "id" => (int)$fila["id_reporte"],
=======

        "id" => $fila["id_reporte"],
        "id_vacante"   => $fila["id_vacante"],
>>>>>>> 9c12442f48a480a83674472513139362cf0b0b00
        "motivo" => $fila["motivo"],
        "fecha_reporte" => $fila["fecha_reporte"],
        "id_vacante" => (int)$fila["id_vacante"],
        "vacante" => $fila["titulo_puesto"],
        "fecha_publicacion" => $fila["fecha_publicacion"],
        "usuario_nombre" => trim(($fila["nombres"] ?? "") . " " . ($fila["apellidos"] ?? "")),
        "correo" => $fila["correo"]
    ];
}

reclutador_responder(true, "Reportes obtenidos", $reportes);
?>

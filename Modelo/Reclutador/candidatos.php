<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers();

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);
$accion = $_GET["accion"] ?? "";

if ($accion === "listar_por_vacante") {
    $sql = "SELECT
                v.id_vacante,
                v.titulo_puesto,
                v.modalidad,
                v.estado,
                COUNT(p.id_postulacion) AS total_candidatos
            FROM vacantes v
            LEFT JOIN postulaciones p ON v.id_vacante = p.id_vacante
            WHERE v.id_reclutador = ? AND v.estado != 'eliminada'
            GROUP BY v.id_vacante, v.titulo_puesto, v.modalidad, v.estado
            ORDER BY total_candidatos DESC, v.fecha_publicacion DESC";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_reclutador);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $resultados = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row["id_vacante"] = (int)$row["id_vacante"];
        $row["total_candidatos"] = (int)$row["total_candidatos"];
        $resultados[] = $row;
    }

    reclutador_responder(true, "Vacantes con candidatos obtenidas", $resultados);
}

if ($accion === "detalles_candidatos") {
    $id_vacante = (int)($_GET["id_vacante"] ?? 0);

    if (!$id_vacante) {
        reclutador_responder(false, "ID de vacante requerido.", null, 400);
    }

    $sql = "SELECT
                p.id_postulacion,
                p.fecha_postulacion,
                u.id_usuario,
                CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS nombre_completo,
                u.correo,
                COALESCE(u.telefono, 'No especificado') AS telefono,
                COALESCE(pos.cv, '') AS cv_url,
                COALESCE(pos.descripcion_discapacidad, 'Sin resumen profesional registrado.') AS resumen_profesional,
                COALESCE(pos.habilidades, 'No especificadas') AS habilidades,
                COALESCE(pos.experiencia, 'Sin experiencia laboral registrada.') AS experiencia,
                'No especificada' AS educacion
            FROM postulaciones p
            INNER JOIN vacantes v ON p.id_vacante = v.id_vacante
            INNER JOIN postulantes pos ON p.id_postulante = pos.id_postulante
            INNER JOIN usuarios u ON pos.id_usuario = u.id_usuario
            WHERE p.id_vacante = ? AND v.id_reclutador = ?";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $id_vacante, $id_reclutador);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $candidatos = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $candidatos[] = $row;
    }

    reclutador_responder(true, "Candidatos obtenidos", $candidatos);
}

reclutador_responder(false, "Accion no valida en el modulo de candidatos.", null, 400);
?>

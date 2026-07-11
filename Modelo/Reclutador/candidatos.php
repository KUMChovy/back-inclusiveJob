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

    // NUEVO: se agrega un LEFT JOIN a `chabots` para saber, por cada
    // candidato, si ya realizo la ENTREVISTA REAL (la que se guarda desde
    // entrevista_real.php con resultado = 'entrevista_real_realizada').
    // Esa fila del postulante guarda en respuesta_ia (JSON) tanto
    // vacante.id_vacante como evaluacion.puntuacion, asi que se extraen
    // ambos con JSON_EXTRACT y se cruzan por id_usuario + id_vacante.
    // No se toco ninguna columna ni JOIN existente, solo se agregaron
    // entrevista_realizada y puntuacion_entrevista al SELECT.
    $sql = "SELECT
                p.id_postulacion,
                COALESCE(p.estado, 'pendiente') AS estado_postulacion,
                p.fecha_postulacion,
                u.id_usuario,
                CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS nombre_completo,
                u.correo,
                COALESCE(u.telefono, 'No especificado') AS telefono,
                COALESCE(pos.cv, '') AS cv_url,
                COALESCE(pos.descripcion_discapacidad, 'Sin resumen profesional registrado.') AS resumen_profesional,
                COALESCE(pos.habilidades, 'No especificadas') AS habilidades,
                COALESCE(pos.experiencia, 'Sin experiencia laboral registrada.') AS experiencia,
                'No especificada' AS educacion,
                (ce.id_usuario IS NOT NULL) AS entrevista_realizada,
                ce.puntuacion_entrevista
            FROM postulaciones p
            INNER JOIN vacantes v ON p.id_vacante = v.id_vacante
            INNER JOIN postulantes pos ON p.id_postulante = pos.id_postulante
            INNER JOIN usuarios u ON pos.id_usuario = u.id_usuario
            LEFT JOIN (
                SELECT
                    id_usuario,
                    JSON_UNQUOTE(JSON_EXTRACT(respuesta_ia, '$.vacante.id_vacante')) AS id_vacante_ref,
                    JSON_UNQUOTE(JSON_EXTRACT(respuesta_ia, '$.evaluacion.puntuacion')) AS puntuacion_entrevista
                FROM chabots
                WHERE resultado = 'entrevista_real_realizada'
            ) ce ON ce.id_usuario = u.id_usuario AND ce.id_vacante_ref = v.id_vacante
            WHERE p.id_vacante = ? AND v.id_reclutador = ?";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $id_vacante, $id_reclutador);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $candidatos = [];
    while ($row = mysqli_fetch_assoc($res)) {
        // NUEVO: normalizar tipos para que el frontend reciba boolean/int
        // en vez de strings "0"/"1" o null crudo de MySQL.
        $row["entrevista_realizada"] = (bool)(int)$row["entrevista_realizada"];
        $row["puntuacion_entrevista"] = $row["puntuacion_entrevista"] !== null ? (int)$row["puntuacion_entrevista"] : null;
        $candidatos[] = $row;
    }

    reclutador_responder(true, "Candidatos obtenidos", $candidatos);
}

if ($accion === "listar_aceptados") {
    $sql = "SELECT
                v.id_vacante,
                v.titulo_puesto,
                v.modalidad,
                v.estado AS estado_vacante,
                p.id_postulacion,
                p.fecha_postulacion,
                COALESCE(p.estado, 'pendiente') AS estado_postulacion,
                u.id_usuario,
                CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS nombre_completo,
                u.correo,
                COALESCE(u.telefono, 'No especificado') AS telefono,
                COALESCE(pos.cv, '') AS cv_url,
                COALESCE(pos.descripcion_discapacidad, 'Sin resumen profesional registrado.') AS resumen_profesional,
                COALESCE(pos.habilidades, 'No especificadas') AS habilidades,
                COALESCE(pos.experiencia, 'Sin experiencia laboral registrada.') AS experiencia,
                'No especificada' AS educacion
            FROM vacantes v
            LEFT JOIN postulaciones p
                ON p.id_vacante = v.id_vacante
               AND LOWER(COALESCE(p.estado, 'pendiente')) = 'aceptado'
            LEFT JOIN postulantes pos ON p.id_postulante = pos.id_postulante
            LEFT JOIN usuarios u ON pos.id_usuario = u.id_usuario
            WHERE v.id_reclutador = ? AND v.estado != 'eliminada'
            ORDER BY v.fecha_publicacion DESC, p.fecha_postulacion DESC";

    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_reclutador);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $vacantes = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $id_vacante = (int)$row["id_vacante"];

        if (!isset($vacantes[$id_vacante])) {
            $vacantes[$id_vacante] = [
                "id_vacante" => $id_vacante,
                "titulo_puesto" => $row["titulo_puesto"],
                "modalidad" => $row["modalidad"],
                "estado" => $row["estado_vacante"],
                "candidatos_aceptados" => []
            ];
        }

        if (!empty($row["id_postulacion"])) {
            $vacantes[$id_vacante]["candidatos_aceptados"][] = [
                "id_postulacion" => (int)$row["id_postulacion"],
                "fecha_postulacion" => $row["fecha_postulacion"],
                "estado_postulacion" => $row["estado_postulacion"],
                "id_usuario" => (int)$row["id_usuario"],
                "nombre_completo" => $row["nombre_completo"],
                "correo" => $row["correo"],
                "telefono" => $row["telefono"],
                "cv_url" => $row["cv_url"],
                "resumen_profesional" => $row["resumen_profesional"],
                "habilidades" => $row["habilidades"],
                "experiencia" => $row["experiencia"],
                "educacion" => $row["educacion"]
            ];
        }
    }
    mysqli_stmt_close($stmt);

    reclutador_responder(true, "Candidatos aceptados obtenidos", array_values($vacantes));
}

if ($accion === "actualizar_estado_postulacion" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $data = reclutador_json_body();
    $id_postulacion = (int)($data["id_postulacion"] ?? 0);
    $estado = strtolower(trim($data["estado"] ?? ""));
    $estados_validos = ["pendiente", "aceptado", "rechazado"];

    if (!$id_postulacion || !in_array($estado, $estados_validos, true)) {
        reclutador_responder(false, "Datos invalidos para actualizar la postulacion.", null, 400);
    }

    $sql_check = "SELECT p.id_postulacion
                  FROM postulaciones p
                  INNER JOIN vacantes v ON p.id_vacante = v.id_vacante
                  WHERE p.id_postulacion = ? AND v.id_reclutador = ?
                  LIMIT 1";
    $stmt_check = mysqli_prepare($con, $sql_check);
    mysqli_stmt_bind_param($stmt_check, "ii", $id_postulacion, $id_reclutador);
    mysqli_stmt_execute($stmt_check);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));
    mysqli_stmt_close($stmt_check);

    if (!$row) {
        reclutador_responder(false, "No se encontro la postulacion para tus vacantes.", null, 404);
    }

    $stmt = mysqli_prepare($con, "UPDATE postulaciones SET estado = ? WHERE id_postulacion = ?");
    mysqli_stmt_bind_param($stmt, "si", $estado, $id_postulacion);

    if (!mysqli_stmt_execute($stmt)) {
        reclutador_responder(false, "No se pudo actualizar el estado de la postulacion.", null, 500);
    }

    reclutador_responder(true, "Estado de postulacion actualizado", [
        "id_postulacion" => $id_postulacion,
        "estado" => $estado
    ]);
}

reclutador_responder(false, "Accion no valida en el modulo de candidatos.", null, 400);
?>
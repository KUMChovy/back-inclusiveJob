<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers();

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);
$accion = $_GET["accion"] ?? "";

function candidato_pdf_disponible($contenido) {
    if ($contenido === null || $contenido === '') {
        return false;
    }

    $contenido = (string)$contenido;
    if (strncmp($contenido, '%PDF', 4) === 0) {
        return true;
    }

    if (strpos($contenido, 'uploads/') !== 0) {
        return false;
    }

    $path = realpath(__DIR__ . '/../../' . $contenido);
    $base = realpath(__DIR__ . '/../../uploads');
    if (!$path || !$base || strpos($path, $base) !== 0 || !is_file($path)) {
        return false;
    }

    $handle = fopen($path, 'rb');
    if (!$handle) return false;
    $signature = fread($handle, 4);
    fclose($handle);

    return $signature === '%PDF';
}

function candidato_output_pdf($contenido, $filename) {
    if (!candidato_pdf_disponible($contenido)) {
        reclutador_responder(false, 'El CV no esta disponible como PDF.', null, 404);
    }

    $contenido = (string)$contenido;
    if (strpos($contenido, 'uploads/') === 0) {
        $path = realpath(__DIR__ . '/../../' . $contenido);
        $base = realpath(__DIR__ . '/../../uploads');
        if (!$path || !$base || strpos($path, $base) !== 0 || !is_file($path)) {
            reclutador_responder(false, 'El PDF no esta disponible.', null, 404);
        }
        $contenido = file_get_contents($path);
    }

    if (ob_get_length()) {
        ob_clean();
    }

    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($contenido));
    echo $contenido;
    exit;
}

function candidato_certificaciones($con, $id_postulante) {
    $stmt = mysqli_prepare($con, "SELECT
            id_certificaciones,
            nombre_certificacion,
            institucion_dada,
            fecha_emitido,
            pdf
        FROM certificaciones
        WHERE id_postulante = ?
        ORDER BY fecha_emitido DESC, id_certificaciones DESC
        LIMIT 10");
    mysqli_stmt_bind_param($stmt, "i", $id_postulante);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $certificaciones = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row["id_certificaciones"] = (int)$row["id_certificaciones"];
        $row["id_certificacion"] = $row["id_certificaciones"];
        $row["tiene_pdf"] = candidato_pdf_disponible($row["pdf"] ?? null) ? 1 : 0;
        unset($row["pdf"]);
        $certificaciones[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $certificaciones;
}

function candidato_discapacidades($con, $id_postulante) {
    $stmt = mysqli_prepare($con, "SELECT
            td.id_tipo_discapacidad AS id_discapacidad,
            td.nombre_discapacidad AS nombre,
            td.descripcion
        FROM postulante_discapacidad pd
        INNER JOIN tipo_discapacidad td ON td.id_tipo_discapacidad = pd.id_tipo_discapacidad
        WHERE pd.id_postulante = ?
        ORDER BY td.nombre_discapacidad ASC");
    mysqli_stmt_bind_param($stmt, "i", $id_postulante);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $discapacidades = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row["id_discapacidad"] = (int)$row["id_discapacidad"];
        $discapacidades[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $discapacidades;
}

if ($accion === "cv") {
    $id_postulacion = (int)($_GET["id_postulacion"] ?? 0);

    if (!$id_postulacion) {
        reclutador_responder(false, "ID de postulacion requerido.", null, 400);
    }

    $stmt = mysqli_prepare($con, "SELECT pos.cv
        FROM postulaciones p
        INNER JOIN vacantes v ON p.id_vacante = v.id_vacante
        INNER JOIN postulantes pos ON p.id_postulante = pos.id_postulante
        WHERE p.id_postulacion = ? AND v.id_reclutador = ?
        LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ii", $id_postulacion, $id_reclutador);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    candidato_output_pdf($row["cv"] ?? null, "cv_postulacion_" . $id_postulacion . ".pdf");
}

if ($accion === "certificacion_pdf") {
    $id_certificacion = (int)($_GET["id_certificacion"] ?? $_GET["id"] ?? 0);

    if (!$id_certificacion) {
        reclutador_responder(false, "ID de certificacion requerido.", null, 400);
    }

    $stmt = mysqli_prepare($con, "SELECT c.pdf
        FROM certificaciones c
        INNER JOIN postulantes pos ON c.id_postulante = pos.id_postulante
        INNER JOIN postulaciones p ON p.id_postulante = pos.id_postulante
        INNER JOIN vacantes v ON p.id_vacante = v.id_vacante
        WHERE c.id_certificaciones = ? AND v.id_reclutador = ?
        LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ii", $id_certificacion, $id_reclutador);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    candidato_output_pdf($row["pdf"] ?? null, "certificacion_" . $id_certificacion . ".pdf");
}

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
                u.nombres,
                u.apellidos,
                CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS nombre_completo,
                u.correo,
                COALESCE(u.telefono, 'No especificado') AS telefono,
                u.foto_perfil,
                u.estado AS estado_usuario,
                u.fecha_registro,
                pos.id_postulante,
                pos.descripcion_discapacidad,
                pos.esfuerzo_fisico_posible,
                pos.portafolio_url,
                pos.cv AS cv_pdf,
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
                    id_vacante_ref,
                    MAX(CAST(puntuacion_entrevista AS UNSIGNED)) AS puntuacion_entrevista
                FROM (
                    SELECT
                        id_usuario,
                        JSON_UNQUOTE(JSON_EXTRACT(respuesta_ia, '$.vacante.id_vacante')) AS id_vacante_ref,
                        JSON_UNQUOTE(JSON_EXTRACT(respuesta_ia, '$.evaluacion.puntuacion')) AS puntuacion_entrevista
                    FROM chabots
                    WHERE resultado = 'entrevista_real_realizada'
                ) entrevistas
                WHERE id_vacante_ref IS NOT NULL
                GROUP BY id_usuario, id_vacante_ref
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
        $row["tiene_cv"] = candidato_pdf_disponible($row["cv_pdf"] ?? null) ? 1 : 0;
        $row["cv_url"] = "";
        unset($row["cv_pdf"]);
        $row["id_usuario"] = (int)$row["id_usuario"];
        $row["id_postulante"] = (int)$row["id_postulante"];
        $row["esfuerzo_fisico_posible"] = (int)$row["esfuerzo_fisico_posible"];
        $row["certificaciones"] = candidato_certificaciones($con, $row["id_postulante"]);
        $row["discapacidades"] = candidato_discapacidades($con, $row["id_postulante"]);
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
                pos.cv AS cv_pdf,
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
                "cv_url" => "",
                "tiene_cv" => candidato_pdf_disponible($row["cv_pdf"] ?? null) ? 1 : 0,
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

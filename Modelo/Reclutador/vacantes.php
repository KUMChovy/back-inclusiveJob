<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers("GET, POST, OPTIONS, PUT, DELETE");

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);
$accion = $_GET["accion"] ?? "";
$data = reclutador_json_body();

if ($accion === "listar") {
    $sql = "SELECT id_vacante, titulo_puesto, descripcion_puesto, requisitos, modalidad,
                   salario_min, salario_max, estado, fecha_publicacion, fecha_cierre, id_reclutador
            FROM vacantes
            WHERE id_reclutador = ?
            ORDER BY id_vacante DESC";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_reclutador);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $vacantes = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $vacantes[] = $row;
    }

    reclutador_responder(true, "Vacantes obtenidas", $vacantes);
}

if (($accion === "insertar" || $accion === "guardar") && $_SERVER["REQUEST_METHOD"] === "POST") {
    $titulo = trim($data["titulo_puesto"] ?? "");
    $descripcion = trim($data["descripcion_puesto"] ?? "");
    $requisitos = trim($data["requisitos"] ?? "");
    $modalidad = trim($data["modalidad"] ?? "Presencial");
    $salario_min = ($data["salario_min"] ?? "") !== "" ? (float)$data["salario_min"] : 0;
    $salario_max = ($data["salario_max"] ?? "") !== "" ? (float)$data["salario_max"] : 0;
    $estado = trim($data["estado"] ?? "activa");
    $fecha_cierre = trim($data["fecha_cierre"] ?? "");
    $fecha_cierre = $fecha_cierre !== "" ? $fecha_cierre : null;

    if ($titulo === "") {
        reclutador_responder(false, "El titulo de la vacante es obligatorio.", null, 400);
    }

    $sql = "INSERT INTO vacantes
                (titulo_puesto, descripcion_puesto, requisitos, modalidad, salario_min, salario_max, estado, fecha_publicacion, fecha_cierre, id_reclutador)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "ssssddssi", $titulo, $descripcion, $requisitos, $modalidad, $salario_min, $salario_max, $estado, $fecha_cierre, $id_reclutador);

    if (!mysqli_stmt_execute($stmt)) {
        reclutador_responder(false, "Error al crear la vacante: " . mysqli_error($con), null, 500);
    }

    reclutador_responder(true, "Vacante creada correctamente", ["id_vacante" => mysqli_insert_id($con)]);
}

if ($accion === "editar" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $id_vacante = (int)($data["id_vacante"] ?? 0);
    $titulo = trim($data["titulo_puesto"] ?? "");
    $descripcion = trim($data["descripcion_puesto"] ?? "");
    $requisitos = trim($data["requisitos"] ?? "");
    $modalidad = trim($data["modalidad"] ?? "Presencial");
    $salario_min = ($data["salario_min"] ?? "") !== "" ? (float)$data["salario_min"] : 0;
    $salario_max = ($data["salario_max"] ?? "") !== "" ? (float)$data["salario_max"] : 0;
    $estado = trim($data["estado"] ?? "activa");
    $fecha_cierre = trim($data["fecha_cierre"] ?? "");
    $fecha_cierre = $fecha_cierre !== "" ? $fecha_cierre : null;

    if (!$id_vacante || $titulo === "") {
        reclutador_responder(false, "Faltan datos para actualizar la vacante.", null, 400);
    }

    $sql = "UPDATE vacantes
            SET titulo_puesto = ?, descripcion_puesto = ?, requisitos = ?, modalidad = ?,
                salario_min = ?, salario_max = ?, estado = ?, fecha_cierre = ?
            WHERE id_vacante = ? AND id_reclutador = ?";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "ssssddssii", $titulo, $descripcion, $requisitos, $modalidad, $salario_min, $salario_max, $estado, $fecha_cierre, $id_vacante, $id_reclutador);

    if (!mysqli_stmt_execute($stmt)) {
        reclutador_responder(false, "Error al actualizar la vacante: " . mysqli_error($con), null, 500);
    }

    reclutador_responder(true, "Vacante actualizada con exito");
}

if ($accion === "actualizar_estado" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $id_vacante = (int)($data["id_vacante"] ?? 0);
    $estado = trim($data["estado"] ?? "activa");

    if (!$id_vacante) {
        reclutador_responder(false, "ID de vacante requerido.", null, 400);
    }

    $stmt = mysqli_prepare($con, "UPDATE vacantes SET estado = ? WHERE id_vacante = ? AND id_reclutador = ?");
    mysqli_stmt_bind_param($stmt, "sii", $estado, $id_vacante, $id_reclutador);

    if (!mysqli_stmt_execute($stmt)) {
        reclutador_responder(false, "No se pudo actualizar el estado.", null, 500);
    }

    reclutador_responder(true, "Estado actualizado");
}

if ($accion === "eliminar") {
    $id_vacante = (int)($_GET["id_vacante"] ?? ($data["id_vacante"] ?? 0));

    if (!$id_vacante) {
        reclutador_responder(false, "ID de vacante requerido.", null, 400);
    }

    $stmt = mysqli_prepare($con, "DELETE FROM vacantes WHERE id_vacante = ? AND id_reclutador = ?");
    mysqli_stmt_bind_param($stmt, "ii", $id_vacante, $id_reclutador);

    if (!mysqli_stmt_execute($stmt)) {
        reclutador_responder(false, "No se pudo eliminar la vacante.", null, 500);
    }

    reclutador_responder(true, "Vacante eliminada");
}

reclutador_responder(false, "Accion no valida.", null, 400);
?>

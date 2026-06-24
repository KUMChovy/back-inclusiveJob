<?php
// Modelo/Administrador/reportes.php

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../Conexion.php';
$con = conectarbd();

function responder(bool $ok, string $mensaje, mixed $datos = null, int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'success' => $ok,
        'message' => $mensaje,
        'data'    => $datos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

function get_reporte(mysqli $con, int $id): ?array
{
    $stmt = mysqli_prepare($con, "SELECT id_reporte, id_vacante FROM reporte_vacante WHERE id_reporte = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row ?: null;
}

$accion = $_GET['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'listar') {
    $buscar = trim($_GET['buscar'] ?? '');
    $estado = trim($_GET['estado'] ?? '');
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $limite = max(1, min(100, (int)($_GET['limite'] ?? 15)));
    $offset = ($pagina - 1) * $limite;

    $where = [];
    $types = '';
    $params = [];

    if ($buscar !== '') {
        $where[] = "(v.titulo_puesto LIKE ? OR e.nombre_empresas LIKE ? OR u.correo LIKE ? OR u.nombres LIKE ? OR u.apellidos LIKE ? OR rv.motivo LIKE ?)";
        $like = '%' . $buscar . '%';
        for ($i = 0; $i < 6; $i++) $params[] = $like;
        $types .= 'ssssss';
    }

    if ($estado !== '' && $estado !== 'todas') {
        $where[] = 'v.estado = ?';
        $params[] = $estado;
        $types .= 's';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countSql = "
        SELECT COUNT(*) AS total
        FROM reporte_vacante rv
        INNER JOIN vacantes v ON rv.id_vacante = v.id_vacante
        INNER JOIN reclutadores r ON v.id_reclutador = r.id_reclutador
        INNER JOIN empresas e ON r.id_empresas = e.id_empresas
        INNER JOIN usuarios u ON rv.id_usuario = u.id_usuario
        $whereSql
    ";
    $stmtCount = mysqli_prepare($con, $countSql);
    bind_params($stmtCount, $types, $params);
    mysqli_stmt_execute($stmtCount);
    $countRes = mysqli_stmt_get_result($stmtCount);
    $total = (int)(mysqli_fetch_assoc($countRes)['total'] ?? 0);

    $sql = "
        SELECT
            rv.id_reporte AS id,
            rv.id_reporte,
            rv.motivo,
            rv.fecha_reporte,
            rv.id_vacante,
            rv.id_usuario,
            v.titulo_puesto,
            v.titulo_puesto AS vacante,
            v.estado AS estado_vacante,
            e.id_empresas,
            e.nombre_empresas,
            e.nombre_empresas AS empresa,
            u.nombres,
            u.apellidos,
            u.correo,
            u.correo AS usuario,
            CONCAT(u.nombres, ' ', u.apellidos) AS usuario_nombre
        FROM reporte_vacante rv
        INNER JOIN vacantes v ON rv.id_vacante = v.id_vacante
        INNER JOIN reclutadores r ON v.id_reclutador = r.id_reclutador
        INNER JOIN empresas e ON r.id_empresas = e.id_empresas
        INNER JOIN usuarios u ON rv.id_usuario = u.id_usuario
        $whereSql
        ORDER BY rv.fecha_reporte DESC, rv.id_reporte DESC
        LIMIT ? OFFSET ?
    ";

    $listTypes = $types . 'ii';
    $listParams = $params;
    $listParams[] = $limite;
    $listParams[] = $offset;

    $stmt = mysqli_prepare($con, $sql);
    bind_params($stmt, $listTypes, $listParams);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $reportes = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row['id'] = (int)$row['id'];
        $row['id_reporte'] = (int)$row['id_reporte'];
        $row['id_vacante'] = (int)$row['id_vacante'];
        $row['id_usuario'] = (int)$row['id_usuario'];
        $row['id_empresas'] = (int)$row['id_empresas'];
        $reportes[] = $row;
    }

    mysqli_close($con);
    responder(true, 'Reportes obtenidos.', [
        'reportes' => $reportes,
        'total' => $total,
        'pagina' => $pagina,
        'limite' => $limite,
        'total_paginas' => (int)ceil($total / $limite),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'ignorar') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $stmt = mysqli_prepare($con, 'DELETE FROM reporte_vacante WHERE id_reporte = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok) responder(false, 'No se pudo ignorar el reporte.', null, 400);
    if (mysqli_stmt_affected_rows($stmt) === 0) responder(false, 'Reporte no encontrado.', null, 404);

    mysqli_close($con);
    responder(true, 'Reporte ignorado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'eliminar_vacante') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $reporte = get_reporte($con, $id);
    if (!$reporte) responder(false, 'Reporte no encontrado.', null, 404);

    $idVacante = (int)$reporte['id_vacante'];
    mysqli_begin_transaction($con);

    $stmtVacante = mysqli_prepare($con, "UPDATE vacantes SET estado = 'eliminada' WHERE id_vacante = ?");
    mysqli_stmt_bind_param($stmtVacante, 'i', $idVacante);
    $okVacante = mysqli_stmt_execute($stmtVacante);

    $stmtReportes = mysqli_prepare($con, 'DELETE FROM reporte_vacante WHERE id_vacante = ?');
    mysqli_stmt_bind_param($stmtReportes, 'i', $idVacante);
    $okReportes = mysqli_stmt_execute($stmtReportes);

    if (!$okVacante || !$okReportes) {
        mysqli_rollback($con);
        responder(false, 'No se pudo eliminar la vacante reportada.', null, 400);
    }

    mysqli_commit($con);
    mysqli_close($con);
    responder(true, 'Vacante eliminada.', ['id_vacante' => $idVacante]);
}

responder(false, 'Acción no reconocida.', null, 400);

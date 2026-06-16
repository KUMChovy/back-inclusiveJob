<?php
// Modelo/Administrador/postulaciones.php

header("Access-Control-Allow-Origin: *");
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

function table_exists(mysqli $con, string $table): bool
{
    $safe = mysqli_real_escape_string($con, $table);
    $res = mysqli_query($con, "SHOW TABLES LIKE '$safe'");
    return $res && mysqli_num_rows($res) > 0;
}

function column_exists(mysqli $con, string $table, string $column): bool
{
    $safeTable = mysqli_real_escape_string($con, $table);
    $safeColumn = mysqli_real_escape_string($con, $column);
    $res = mysqli_query($con, "SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return $res && mysqli_num_rows($res) > 0;
}

function first_column(mysqli $con, string $table, array $candidates): ?string
{
    foreach ($candidates as $column) {
        if (column_exists($con, $table, $column)) return $column;
    }
    return null;
}

function fetch_one(mysqli $con, string $sql, string $types = '', mixed ...$params): ?array
{
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) return null;
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row ?: null;
}

function fetch_all(mysqli $con, string $sql, string $types = '', mixed ...$params): array
{
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) return [];
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) $rows[] = $row;
    return $rows;
}

function postulante_join(mysqli $con): array
{
    $postCol = first_column($con, 'postulaciones', ['id_postulante', 'id_usuario']);
    if ($postCol === 'id_postulante' && table_exists($con, 'postulantes') && table_exists($con, 'usuarios')) {
        return [
            'column' => $postCol,
            'join' => 'LEFT JOIN postulantes po ON po.id_postulante = p.id_postulante LEFT JOIN usuarios u ON u.id_usuario = po.id_usuario',
        ];
    }
    if ($postCol === 'id_usuario' && table_exists($con, 'usuarios')) {
        return [
            'column' => $postCol,
            'join' => 'LEFT JOIN usuarios u ON u.id_usuario = p.id_usuario LEFT JOIN postulantes po ON po.id_usuario = u.id_usuario',
        ];
    }
    return [
        'column' => null,
        'join' => 'LEFT JOIN usuarios u ON 1 = 0 LEFT JOIN postulantes po ON 1 = 0',
    ];
}

function base_join(mysqli $con): string
{
    $post = postulante_join($con);
    return "
        {$post['join']}
        LEFT JOIN vacantes v ON v.id_vacante = p.id_vacante
        LEFT JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
        LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
    ";
}

function base_select(mysqli $con): string
{
    $fecha = column_exists($con, 'postulaciones', 'fecha_postulacion') ? 'p.fecha_postulacion' : 'NULL';
    $estado = column_exists($con, 'postulaciones', 'estado') ? 'p.estado' : "'pendiente'";
    $tituloCol = first_column($con, 'vacantes', ['titulo_puesto', 'titulo']) ?? 'titulo_puesto';
    $descCol = first_column($con, 'vacantes', ['descripcion_puesto', 'descripcion']);
    $descripcionExpr = $descCol ? "v.`$descCol`" : 'NULL';

    return "
        p.id_postulacion,
        p.id_vacante,
        po.id_postulante,
        u.id_usuario,
        u.nombres,
        u.apellidos,
        u.correo,
        u.telefono,
        u.foto_perfil,
        $estado AS estado,
        $fecha AS fecha_postulacion,
        v.`$tituloCol` AS vacante,
        v.`$tituloCol` AS titulo_puesto,
        $descripcionExpr AS descripcion_puesto,
        v.modalidad,
        v.estado AS estado_vacante,
        e.id_empresas,
        e.nombre_empresas AS empresa,
        CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS postulante
    ";
}

function normalize_postulacion(array $row): array
{
    $row['id_postulacion'] = (int)$row['id_postulacion'];
    if (isset($row['id_vacante'])) $row['id_vacante'] = $row['id_vacante'] !== null ? (int)$row['id_vacante'] : null;
    if (isset($row['id_postulante'])) $row['id_postulante'] = $row['id_postulante'] !== null ? (int)$row['id_postulante'] : null;
    if (isset($row['id_usuario'])) $row['id_usuario'] = $row['id_usuario'] !== null ? (int)$row['id_usuario'] : null;
    $row['estado'] = $row['estado'] ?: 'pendiente';
    return $row;
}

$accion = $_GET['accion'] ?? '';

if (!table_exists($con, 'postulaciones')) {
    responder(false, 'La tabla postulaciones no existe.', null, 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'listar') {
    $estado = $_GET['estado'] ?? 'todas';
    $buscar = trim($_GET['buscar'] ?? '');
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $limite = max(1, min(100, (int)($_GET['limite'] ?? 15)));
    $offset = ($pagina - 1) * $limite;

    $condiciones = [];
    $params = [];
    $tipos = '';

    if (in_array($estado, ['pendiente', 'entrevista', 'aceptado', 'rechazado'], true) && column_exists($con, 'postulaciones', 'estado')) {
        $condiciones[] = 'p.estado = ?';
        $params[] = $estado;
        $tipos .= 's';
    }

    if ($buscar !== '') {
        $condiciones[] = '(u.nombres LIKE ? OR u.apellidos LIKE ? OR u.correo LIKE ? OR v.titulo_puesto LIKE ? OR e.nombre_empresas LIKE ?)';
        $like = "%$buscar%";
        for ($i = 0; $i < 5; $i++) {
            $params[] = $like;
            $tipos .= 's';
        }
    }

    $where = count($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';
    $join = base_join($con);

    $stmtT = mysqli_prepare($con, "SELECT COUNT(DISTINCT p.id_postulacion) AS total FROM postulaciones p $join $where");
    if ($tipos !== '') mysqli_stmt_bind_param($stmtT, $tipos, ...$params);
    mysqli_stmt_execute($stmtT);
    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtT))['total'];

    $orderExpr = column_exists($con, 'postulaciones', 'fecha_postulacion') ? 'p.fecha_postulacion DESC' : 'p.id_postulacion DESC';
    $select = base_select($con);
    $sql = "
        SELECT $select
        FROM postulaciones p
        $join
        $where
        ORDER BY $orderExpr
        LIMIT ? OFFSET ?
    ";

    $allParams = array_merge($params, [$limite, $offset]);
    $allTipos = $tipos . 'ii';
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, $allTipos, ...$allParams);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $postulaciones = [];
    while ($row = mysqli_fetch_assoc($res)) $postulaciones[] = normalize_postulacion($row);

    mysqli_close($con);
    responder(true, 'Postulaciones obtenidas.', [
        'postulaciones' => $postulaciones,
        'total' => $total,
        'pagina' => $pagina,
        'limite' => $limite,
        'total_paginas' => ceil($total / $limite),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'detalle') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $join = base_join($con);
    $select = base_select($con);
    $postulacion = fetch_one($con, "
        SELECT $select
        FROM postulaciones p
        $join
        WHERE p.id_postulacion = ?
    ", 'i', $id);

    if (!$postulacion) responder(false, 'Postulación no encontrada.', null, 404);
    $postulacion = normalize_postulacion($postulacion);

    $postulante = null;
    $vacante = null;
    $discapacidades = [];
    $certificaciones = [];

    if ($postulacion['id_postulante'] && table_exists($con, 'postulantes')) {
        $postulante = fetch_one($con, 'SELECT * FROM postulantes WHERE id_postulante = ?', 'i', (int)$postulacion['id_postulante']);
    } elseif ($postulacion['id_usuario'] && table_exists($con, 'postulantes')) {
        $postulante = fetch_one($con, 'SELECT * FROM postulantes WHERE id_usuario = ?', 'i', (int)$postulacion['id_usuario']);
    }

    if ($postulacion['id_vacante'] && table_exists($con, 'vacantes')) {
        $vacante = fetch_one($con, "
            SELECT v.*, e.nombre_empresas AS empresa
            FROM vacantes v
            LEFT JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
            LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
            WHERE v.id_vacante = ?
        ", 'i', (int)$postulacion['id_vacante']);
    }

    $idPostulante = $postulante['id_postulante'] ?? $postulacion['id_postulante'] ?? null;
    $idUsuario = $postulacion['id_usuario'] ?? null;

    if ($idPostulante && table_exists($con, 'certificaciones')) {
        $certCol = first_column($con, 'certificaciones', ['id_postulante', 'id_usuario']);
        if ($certCol) {
            $value = $certCol === 'id_postulante' ? (int)$idPostulante : (int)$idUsuario;
            $certificaciones = fetch_all($con, "SELECT * FROM certificaciones WHERE `$certCol` = ? ORDER BY 1 DESC LIMIT 10", 'i', $value);
        }
    }

    $relTables = ['postulante_discapacidad', 'postulantes_discapacidades', 'usuarios_discapacidades'];
    foreach ($relTables as $relTable) {
        if (!table_exists($con, $relTable) || !table_exists($con, 'tipo_discapacidad')) continue;
        $relPostCol = first_column($con, $relTable, ['id_postulante', 'id_usuario']);
        $relDiscCol = first_column($con, $relTable, ['id_tipo_discapacidad', 'id_discapacidad']);
        $discNameCol = first_column($con, 'tipo_discapacidad', ['nombre_discapacidad', 'nombre', 'tipo']);
        if ($relPostCol && $relDiscCol && $discNameCol) {
            $value = $relPostCol === 'id_postulante' ? (int)$idPostulante : (int)$idUsuario;
            if ($value) {
                $discapacidades = fetch_all($con, "
                    SELECT d.id_tipo_discapacidad AS id_discapacidad, d.`$discNameCol` AS nombre
                    FROM `$relTable` rel
                    JOIN tipo_discapacidad d ON d.id_tipo_discapacidad = rel.`$relDiscCol`
                    WHERE rel.`$relPostCol` = ?
                    ORDER BY d.`$discNameCol`
                ", 'i', $value);
                break;
            }
        }
    }

    mysqli_close($con);
    responder(true, 'Detalle obtenido.', [
        'postulacion' => $postulacion,
        'postulante' => $postulante,
        'vacante' => $vacante,
        'discapacidades' => $discapacidades,
        'certificaciones' => $certificaciones,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'actualizar_estado') {
    $id = (int)($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $estado = $body['estado'] ?? '';

    if (!$id) responder(false, 'ID requerido.', null, 400);
    if (!in_array($estado, ['pendiente', 'entrevista', 'aceptado', 'rechazado'], true)) {
        responder(false, 'Estado inválido.', null, 400);
    }
    if (!column_exists($con, 'postulaciones', 'estado')) {
        responder(false, 'La postulación no tiene campo estado.', null, 400);
    }

    $stmt = mysqli_prepare($con, 'UPDATE postulaciones SET estado = ? WHERE id_postulacion = ?');
    mysqli_stmt_bind_param($stmt, 'si', $estado, $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok || mysqli_stmt_affected_rows($stmt) === 0) responder(false, 'No se pudo actualizar la postulación.', null, 400);

    mysqli_close($con);
    responder(true, 'Postulación actualizada.');
}

responder(false, 'Acción no reconocida.', null, 400);


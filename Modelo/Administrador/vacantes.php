<?php
// Modelo/Administrador/vacantes.php

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

function select_expr(mysqli $con, string $table, string $alias, array $candidates, string $as, string $fallback = 'NULL'): string
{
    $column = first_column($con, $table, $candidates);
    if (!$column) return "$fallback AS $as";
    return "$alias.`$column` AS $as";
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

function vacante_select(mysqli $con): string
{
    $fields = [
        'v.id_vacante',
        select_expr($con, 'vacantes', 'v', ['titulo_puesto', 'titulo'], 'titulo_puesto'),
        select_expr($con, 'vacantes', 'v', ['descripcion_puesto', 'descripcion'], 'descripcion_puesto'),
        select_expr($con, 'vacantes', 'v', ['requisitos'], 'requisitos'),
        select_expr($con, 'vacantes', 'v', ['modalidad'], 'modalidad'),
        select_expr($con, 'vacantes', 'v', ['salario_min', 'salario_minimo'], 'salario_min'),
        select_expr($con, 'vacantes', 'v', ['salario_max', 'salario_maximo'], 'salario_max'),
        select_expr($con, 'vacantes', 'v', ['estado'], 'estado', "'activa'"),
        select_expr($con, 'vacantes', 'v', ['fecha_publicacion', 'created_at'], 'fecha_publicacion'),
        select_expr($con, 'vacantes', 'v', ['fecha_cierre'], 'fecha_cierre'),
        'v.id_reclutador',
        'e.id_empresas',
        'e.nombre_empresas AS empresa',
        'e.nombre_empresas',
        'u.id_usuario AS id_reclutador_usuario',
        'u.nombres',
        'u.apellidos',
        'u.correo AS correo_reclutador',
        "CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS reclutador"
    ];

    return implode(",\n            ", $fields);
}

function normalize_vacante(array $row): array
{
    $row['id_vacante'] = (int)$row['id_vacante'];
    if (isset($row['id_reclutador'])) $row['id_reclutador'] = (int)$row['id_reclutador'];
    if (isset($row['id_empresas'])) $row['id_empresas'] = $row['id_empresas'] !== null ? (int)$row['id_empresas'] : null;
    $row['estado'] = $row['estado'] ?: 'activa';
    return $row;
}

$accion = $_GET['accion'] ?? '';

if (!table_exists($con, 'vacantes')) {
    responder(false, 'La tabla vacantes no existe.', null, 500);
}

$hasReclutadores = table_exists($con, 'reclutadores');
$hasUsuarios = table_exists($con, 'usuarios');
$hasEmpresas = table_exists($con, 'empresas');
$joinSql = "
    LEFT JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
    LEFT JOIN usuarios u ON u.id_usuario = r.id_usuario
    LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
";

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'listar') {
    $estado = $_GET['estado'] ?? 'todas';
    $modalidad = $_GET['modalidad'] ?? 'todas';
    $buscar = trim($_GET['buscar'] ?? '');
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $limite = max(1, min(100, (int)($_GET['limite'] ?? 15)));
    $offset = ($pagina - 1) * $limite;

    $condiciones = [];
    $params = [];
    $tipos = '';

    if (in_array($estado, ['activa', 'cerrada', 'eliminada'], true) && column_exists($con, 'vacantes', 'estado')) {
        $condiciones[] = 'v.estado = ?';
        $params[] = $estado;
        $tipos .= 's';
    }

    if ($modalidad !== 'todas' && column_exists($con, 'vacantes', 'modalidad')) {
        $condiciones[] = 'v.modalidad = ?';
        $params[] = $modalidad;
        $tipos .= 's';
    }

    if ($buscar !== '') {
        $searchParts = [];
        if (column_exists($con, 'vacantes', 'titulo_puesto')) $searchParts[] = 'v.titulo_puesto LIKE ?';
        elseif (column_exists($con, 'vacantes', 'titulo')) $searchParts[] = 'v.titulo LIKE ?';
        if ($hasEmpresas) $searchParts[] = 'e.nombre_empresas LIKE ?';

        if ($searchParts) {
            $condiciones[] = '(' . implode(' OR ', $searchParts) . ')';
            $like = "%$buscar%";
            foreach ($searchParts as $_) {
                $params[] = $like;
                $tipos .= 's';
            }
        }
    }

    $where = count($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    $stmtT = mysqli_prepare($con, "SELECT COUNT(DISTINCT v.id_vacante) AS total FROM vacantes v $joinSql $where");
    if ($tipos !== '') mysqli_stmt_bind_param($stmtT, $tipos, ...$params);
    mysqli_stmt_execute($stmtT);
    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtT))['total'];

    $orderExpr = column_exists($con, 'vacantes', 'fecha_publicacion') ? 'v.fecha_publicacion DESC' : 'v.id_vacante DESC';
    $select = vacante_select($con);
    $sql = "
        SELECT
            $select,
            COUNT(DISTINCT p.id_postulacion) AS total_postulaciones
        FROM vacantes v
        $joinSql
        LEFT JOIN postulaciones p ON p.id_vacante = v.id_vacante
        $where
        GROUP BY v.id_vacante
        ORDER BY $orderExpr
        LIMIT ? OFFSET ?
    ";

    $allParams = array_merge($params, [$limite, $offset]);
    $allTipos = $tipos . 'ii';
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, $allTipos, ...$allParams);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $vacantes = [];
    while ($row = mysqli_fetch_assoc($res)) $vacantes[] = normalize_vacante($row);

    mysqli_close($con);
    responder(true, 'Vacantes obtenidas.', [
        'vacantes' => $vacantes,
        'total' => $total,
        'pagina' => $pagina,
        'limite' => $limite,
        'total_paginas' => ceil($total / $limite),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'detalle') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $select = vacante_select($con);
    $vacante = fetch_one($con, "
        SELECT
            $select,
            COUNT(DISTINCT p.id_postulacion) AS total_postulaciones
        FROM vacantes v
        $joinSql
        LEFT JOIN postulaciones p ON p.id_vacante = v.id_vacante
        WHERE v.id_vacante = ?
        GROUP BY v.id_vacante
    ", 'i', $id);

    if (!$vacante) responder(false, 'Vacante no encontrada.', null, 404);
    $vacante = normalize_vacante($vacante);

    $postulaciones = [];
    if (table_exists($con, 'postulaciones') && column_exists($con, 'postulaciones', 'id_vacante')) {
        $postulanteJoin = '';
        $postulanteSelect = "NULL AS nombres, NULL AS apellidos, NULL AS correo";
        $postulanteColumn = first_column($con, 'postulaciones', ['id_postulante', 'id_usuario']);

        if ($postulanteColumn === 'id_postulante' && table_exists($con, 'postulantes') && table_exists($con, 'usuarios')) {
            $postulanteJoin = "LEFT JOIN postulantes po ON po.id_postulante = p.id_postulante LEFT JOIN usuarios us ON us.id_usuario = po.id_usuario";
            $postulanteSelect = "us.nombres, us.apellidos, us.correo";
        } elseif ($postulanteColumn === 'id_usuario' && table_exists($con, 'usuarios')) {
            $postulanteJoin = "LEFT JOIN usuarios us ON us.id_usuario = p.id_usuario";
            $postulanteSelect = "us.nombres, us.apellidos, us.correo";
        }

        $fechaPost = column_exists($con, 'postulaciones', 'fecha_postulacion') ? 'p.fecha_postulacion' : 'NULL';
        $estadoPost = column_exists($con, 'postulaciones', 'estado') ? 'p.estado' : "'pendiente'";
        $postulaciones = fetch_all($con, "
            SELECT p.id_postulacion, $estadoPost AS estado, $fechaPost AS fecha_postulacion, $postulanteSelect
            FROM postulaciones p
            $postulanteJoin
            WHERE p.id_vacante = ?
            ORDER BY p.id_postulacion DESC
            LIMIT 50
        ", 'i', $id);
    }

    $discapacidades = [];
    $relTables = ['vacante_discapacidad', 'vacantes_discapacidades', 'vacante_discapacidades'];
    foreach ($relTables as $relTable) {
        if (!table_exists($con, $relTable) || !table_exists($con, 'tipo_discapacidad')) continue;
        $relVacCol = first_column($con, $relTable, ['id_vacante']);
        $relDiscCol = first_column($con, $relTable, ['id_tipo_discapacidad', 'id_discapacidad']);
        $discNameCol = first_column($con, 'tipo_discapacidad', ['nombre_discapacidad', 'nombre', 'tipo']);
        if ($relVacCol && $relDiscCol && $discNameCol) {
            $discapacidades = fetch_all($con, "
                SELECT d.id_tipo_discapacidad AS id_discapacidad, d.`$discNameCol` AS nombre
                FROM `$relTable` rel
                JOIN tipo_discapacidad d ON d.id_tipo_discapacidad = rel.`$relDiscCol`
                WHERE rel.`$relVacCol` = ?
                ORDER BY d.`$discNameCol`
            ", 'i', $id);
            break;
        }
    }

    mysqli_close($con);
    responder(true, 'Detalle obtenido.', [
        'vacante' => $vacante,
        'postulaciones' => $postulaciones,
        'discapacidades' => $discapacidades,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cerrar') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);
    if (!column_exists($con, 'vacantes', 'estado')) responder(false, 'La vacante no tiene campo estado.', null, 400);

    $stmt = mysqli_prepare($con, "UPDATE vacantes SET estado = 'cerrada' WHERE id_vacante = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok || mysqli_stmt_affected_rows($stmt) === 0) responder(false, 'No se pudo cerrar la vacante.', null, 400);

    mysqli_close($con);
    responder(true, 'Vacante cerrada.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'eliminar') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);
    if (!column_exists($con, 'vacantes', 'estado')) responder(false, 'La vacante no tiene campo estado.', null, 400);

    $stmt = mysqli_prepare($con, "UPDATE vacantes SET estado = 'eliminada' WHERE id_vacante = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok || mysqli_stmt_affected_rows($stmt) === 0) responder(false, 'No se pudo eliminar la vacante.', null, 400);

    mysqli_close($con);
    responder(true, 'Vacante eliminada.');
}

responder(false, 'Acción no reconocida.', null, 400);


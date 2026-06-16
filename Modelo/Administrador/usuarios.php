<?php
// Modelo/Administrador/usuarios.php

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

function estado_column_is_numeric(mysqli $con): bool
{
    $res = mysqli_query($con, "SHOW COLUMNS FROM `usuarios` LIKE 'estado'");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    $type = strtolower($row['Type'] ?? '');
    return str_contains($type, 'int') || str_contains($type, 'bit') || str_contains($type, 'bool');
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

function normalize_usuario(array $usuario): array
{
    $rolMap = [1 => 'administrador', 2 => 'postulante', 3 => 'reclutador'];
    $estadoMap = [0 => 'inactivo', 1 => 'activo', 2 => 'suspendido', 3 => 'suspendido'];
    $usuario['id_usuario'] = (int) $usuario['id_usuario'];
    $usuario['id_rol'] = isset($usuario['id_rol']) ? (int) $usuario['id_rol'] : null;
    $usuario['rol'] = $usuario['rol'] ?: ($rolMap[$usuario['id_rol']] ?? 'usuario');

    $rawEstado = $usuario['estado'] ?? null;
    if ($rawEstado === null || $rawEstado === '') {
        $usuario['estado'] = 'activo';
    } elseif (is_numeric($rawEstado)) {
        $usuario['estado'] = $estadoMap[(int)$rawEstado] ?? 'inactivo';
    } else {
        $usuario['estado'] = strtolower((string)$rawEstado);
    }

    return $usuario;
}

$accion = $_GET['accion'] ?? '';

if (!table_exists($con, 'usuarios')) {
    responder(false, 'La tabla usuarios no existe.', null, 500);
}

$estadoNumerico = estado_column_is_numeric($con);

$fechaExpr = 'NULL';
$fechaCol = first_column($con, 'usuarios', ['fecha_registro', 'created_at', 'fecha_creacion']);
if ($fechaCol) $fechaExpr = "u.`$fechaCol`";

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'listar') {
    $rol = $_GET['rol'] ?? 'todos';
    $estado = $_GET['estado'] ?? 'todos';
    $buscar = trim($_GET['buscar'] ?? '');
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $limite = max(1, min(100, (int)($_GET['limite'] ?? 15)));
    $offset = ($pagina - 1) * $limite;

    $rolMap = ['administrador' => 1, 'postulante' => 2, 'reclutador' => 3];
    $condiciones = [];
    $params = [];
    $tipos = '';

    if (isset($rolMap[$rol])) {
        $condiciones[] = 'u.id_rol = ?';
        $params[] = $rolMap[$rol];
        $tipos .= 'i';
    }

    if (in_array($estado, ['activo', 'suspendido', 'inactivo'], true)) {
        $condiciones[] = 'u.estado = ?';
        if ($estadoNumerico) {
            $estadoMap = ['inactivo' => 0, 'activo' => 1, 'suspendido' => 2];
            $params[] = $estadoMap[$estado];
            $tipos .= 'i';
        } else {
            $params[] = $estado;
            $tipos .= 's';
        }
    }

    if ($buscar !== '') {
        $condiciones[] = '(u.nombres LIKE ? OR u.apellidos LIKE ? OR u.correo LIKE ?)';
        $like = "%$buscar%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $tipos .= 'sss';
    }

    $where = count($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    $stmtT = mysqli_prepare($con, "SELECT COUNT(*) AS total FROM usuarios u $where");
    if ($tipos !== '') mysqli_stmt_bind_param($stmtT, $tipos, ...$params);
    mysqli_stmt_execute($stmtT);
    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtT))['total'];

    $orderExpr = $fechaCol ? "u.`$fechaCol` DESC" : 'u.id_usuario DESC';
    $sql = "
        SELECT
            u.id_usuario,
            u.nombres,
            u.apellidos,
            u.correo,
            u.telefono,
            u.foto_perfil,
            u.estado,
            u.id_rol,
            CASE u.id_rol
                WHEN 1 THEN 'administrador'
                WHEN 2 THEN 'postulante'
                WHEN 3 THEN 'reclutador'
                ELSE 'usuario'
            END AS rol,
            $fechaExpr AS fecha_registro
        FROM usuarios u
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

    $usuarios = [];
    while ($row = mysqli_fetch_assoc($res)) $usuarios[] = normalize_usuario($row);

    mysqli_close($con);
    responder(true, 'Usuarios obtenidos.', [
        'usuarios' => $usuarios,
        'total' => $total,
        'pagina' => $pagina,
        'limite' => $limite,
        'total_paginas' => ceil($total / $limite),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'detalle') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $usuario = fetch_one($con, "
        SELECT
            u.id_usuario,
            u.nombres,
            u.apellidos,
            u.correo,
            u.telefono,
            u.foto_perfil,
            u.estado,
            u.id_rol,
            CASE u.id_rol
                WHEN 1 THEN 'administrador'
                WHEN 2 THEN 'postulante'
                WHEN 3 THEN 'reclutador'
                ELSE 'usuario'
            END AS rol,
            $fechaExpr AS fecha_registro
        FROM usuarios u
        WHERE u.id_usuario = ?
    ", 'i', $id);

    if (!$usuario) responder(false, 'Usuario no encontrado.', null, 404);
    $usuario = normalize_usuario($usuario);

    $postulante = null;
    $reclutador = null;
    $discapacidades = [];
    $certificaciones = [];
    $postulaciones = [];
    $vacantes = [];

    if ((int)$usuario['id_rol'] === 2 && table_exists($con, 'postulantes')) {
        $postulante = fetch_one($con, 'SELECT * FROM postulantes WHERE id_usuario = ?', 'i', $id);
        $idPostulante = $postulante['id_postulante'] ?? null;

        if ($idPostulante && table_exists($con, 'certificaciones')) {
            $certCol = first_column($con, 'certificaciones', ['id_postulante', 'id_usuario']);
            if ($certCol) {
                $certificaciones = fetch_all($con, "SELECT * FROM certificaciones WHERE `$certCol` = ? ORDER BY 1 DESC LIMIT 10", 'i', $certCol === 'id_postulante' ? (int)$idPostulante : $id);
            }
        }

        $relTables = ['postulante_discapacidad', 'postulantes_discapacidades', 'usuarios_discapacidades'];
        foreach ($relTables as $relTable) {
            if (!table_exists($con, $relTable) || !table_exists($con, 'tipo_discapacidad')) continue;
            $relPostCol = first_column($con, $relTable, ['id_postulante', 'id_usuario']);
            $relDiscCol = first_column($con, $relTable, ['id_tipo_discapacidad', 'id_discapacidad']);
            $discNameCol = first_column($con, 'tipo_discapacidad', ['nombre_discapacidad', 'nombre', 'tipo']);
            if ($relPostCol && $relDiscCol && $discNameCol) {
                $value = $relPostCol === 'id_postulante' ? (int)$idPostulante : $id;
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

        if ($idPostulante && table_exists($con, 'postulaciones') && table_exists($con, 'vacantes')) {
            $postCol = first_column($con, 'postulaciones', ['id_postulante', 'id_usuario']);
            if ($postCol && column_exists($con, 'postulaciones', 'id_vacante')) {
                $value = $postCol === 'id_postulante' ? (int)$idPostulante : $id;
                $postulaciones = fetch_all($con, "
                    SELECT p.id_postulacion, p.estado, p.fecha_postulacion,
                           v.titulo_puesto, e.nombre_empresas AS empresa
                    FROM postulaciones p
                    JOIN vacantes v ON v.id_vacante = p.id_vacante
                    LEFT JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
                    LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
                    WHERE p.`$postCol` = ?
                    ORDER BY p.fecha_postulacion DESC
                    LIMIT 10
                ", 'i', $value);
            }
        }
    }

    if ((int)$usuario['id_rol'] === 3 && table_exists($con, 'reclutadores')) {
        $reclutador = fetch_one($con, "
            SELECT r.*, e.nombre_empresas, e.correo_empresa, e.estado_validacion
            FROM reclutadores r
            LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
            WHERE r.id_usuario = ?
        ", 'i', $id);

        if ($reclutador && table_exists($con, 'vacantes')) {
            $vacantes = fetch_all($con, "
                SELECT id_vacante, titulo_puesto, modalidad, estado, fecha_publicacion
                FROM vacantes
                WHERE id_reclutador = ?
                ORDER BY fecha_publicacion DESC
                LIMIT 10
            ", 'i', (int)$reclutador['id_reclutador']);
        }
    }

    mysqli_close($con);
    responder(true, 'Detalle obtenido.', [
        'usuario' => $usuario,
        'postulante' => $postulante,
        'reclutador' => $reclutador,
        'discapacidades' => $discapacidades,
        'certificaciones' => $certificaciones,
        'postulaciones' => $postulaciones,
        'vacantes' => $vacantes,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'suspender') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $nuevoEstado = $estadoNumerico ? 2 : 'suspendido';
    $tipoEstado = $estadoNumerico ? 'i' : 's';
    $stmt = mysqli_prepare($con, "UPDATE usuarios SET estado = ? WHERE id_usuario = ?");
    mysqli_stmt_bind_param($stmt, $tipoEstado . 'i', $nuevoEstado, $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok || mysqli_stmt_affected_rows($stmt) === 0) responder(false, 'No se pudo suspender el usuario.', null, 400);

    mysqli_close($con);
    responder(true, 'Usuario suspendido.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'reactivar') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $nuevoEstado = $estadoNumerico ? 1 : 'activo';
    $tipoEstado = $estadoNumerico ? 'i' : 's';
    $stmt = mysqli_prepare($con, "UPDATE usuarios SET estado = ? WHERE id_usuario = ?");
    mysqli_stmt_bind_param($stmt, $tipoEstado . 'i', $nuevoEstado, $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok || mysqli_stmt_affected_rows($stmt) === 0) responder(false, 'No se pudo reactivar el usuario.', null, 400);

    mysqli_close($con);
    responder(true, 'Usuario reactivado.');
}

responder(false, 'Acción no reconocida.', null, 400);



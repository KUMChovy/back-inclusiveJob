<?php
// Modelo/Administrador/discapacidades.php

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

const TABLE_NAME = 'tipo_discapacidad';

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

function get_columns(mysqli $con): array
{
    $id = first_column($con, TABLE_NAME, ['id_tipo_discapacidad', 'id_discapacidad', 'id']);
    $nombre = first_column($con, TABLE_NAME, ['nombre_discapacidad', 'nombre', 'tipo']);
    $descripcion = first_column($con, TABLE_NAME, ['descripcion', 'descripcion_discapacidad', 'detalle']);

    if (!$id || !$nombre) {
        responder(false, 'La tabla tipo_discapacidad no tiene las columnas requeridas.', null, 500);
    }

    return [$id, $nombre, $descripcion];
}

function nombre_exists(mysqli $con, string $idCol, string $nombreCol, string $nombre, ?int $excludeId = null): bool
{
    if ($excludeId) {
        $stmt = mysqli_prepare($con, "SELECT COUNT(*) AS total FROM " . TABLE_NAME . " WHERE LOWER(`$nombreCol`) = LOWER(?) AND `$idCol` <> ?");
        mysqli_stmt_bind_param($stmt, 'si', $nombre, $excludeId);
    } else {
        $stmt = mysqli_prepare($con, "SELECT COUNT(*) AS total FROM " . TABLE_NAME . " WHERE LOWER(`$nombreCol`) = LOWER(?)");
        mysqli_stmt_bind_param($stmt, 's', $nombre);
    }

    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : ['total' => 0];
    return (int)($row['total'] ?? 0) > 0;
}
function normalize_discapacidad(array $row): array
{
    $row['id_discapacidad'] = isset($row['id_discapacidad']) ? (int)$row['id_discapacidad'] : null;
    $row['nombre'] = $row['nombre'] ?? $row['nombre_discapacidad'] ?? '';
    $row['descripcion'] = $row['descripcion'] ?? $row['descripcion_discapacidad'] ?? '';
    return $row;
}

$accion = $_GET['accion'] ?? '';

if (!table_exists($con, TABLE_NAME)) {
    responder(false, 'La tabla tipo_discapacidad no existe.', null, 500);
}

[$idCol, $nombreCol, $descripcionCol] = get_columns($con);
$descripcionSelect = $descripcionCol ? "`$descripcionCol` AS descripcion" : "'' AS descripcion";

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'listar') {
    $sql = "
        SELECT `$idCol` AS id_discapacidad,
               `$nombreCol` AS nombre,
               $descripcionSelect
        FROM " . TABLE_NAME . "
        ORDER BY `$nombreCol` ASC
    ";
    $res = mysqli_query($con, $sql);
    if (!$res) responder(false, 'No se pudieron obtener las discapacidades.', null, 500);

    $discapacidades = [];
    while ($row = mysqli_fetch_assoc($res)) $discapacidades[] = normalize_discapacidad($row);

    mysqli_close($con);
    responder(true, 'Discapacidades obtenidas.', [
        'discapacidades' => $discapacidades,
        'total' => count($discapacidades),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'crear') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $nombre = trim($body['nombre'] ?? '');
    $descripcion = trim($body['descripcion'] ?? '');

    if ($nombre === '') responder(false, 'Nombre requerido.', null, 400);
    if ($descripcionCol && $descripcion === '') responder(false, 'Descripción requerida.', null, 400);
    if (nombre_exists($con, $idCol, $nombreCol, $nombre)) responder(false, 'Ya existe una discapacidad con ese nombre.', null, 409);

    if ($descripcionCol) {
        $stmt = mysqli_prepare($con, "INSERT INTO " . TABLE_NAME . " (`$nombreCol`, `$descripcionCol`) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt, 'ss', $nombre, $descripcion);
    } else {
        $stmt = mysqli_prepare($con, "INSERT INTO " . TABLE_NAME . " (`$nombreCol`) VALUES (?)");
        mysqli_stmt_bind_param($stmt, 's', $nombre);
    }

    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) responder(false, 'No se pudo crear la discapacidad.', null, 400);

    $newId = mysqli_insert_id($con);
    mysqli_close($con);
    responder(true, 'Discapacidad creada.', ['id' => $newId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'editar') {
    $id = (int)($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $nombre = trim($body['nombre'] ?? '');
    $descripcion = trim($body['descripcion'] ?? '');

    if (!$id) responder(false, 'ID requerido.', null, 400);
    if ($nombre === '') responder(false, 'Nombre requerido.', null, 400);
    if ($descripcionCol && $descripcion === '') responder(false, 'Descripción requerida.', null, 400);
    if (nombre_exists($con, $idCol, $nombreCol, $nombre, $id)) responder(false, 'Ya existe una discapacidad con ese nombre.', null, 409);

    if ($descripcionCol) {
        $stmt = mysqli_prepare($con, "UPDATE " . TABLE_NAME . " SET `$nombreCol` = ?, `$descripcionCol` = ? WHERE `$idCol` = ?");
        mysqli_stmt_bind_param($stmt, 'ssi', $nombre, $descripcion, $id);
    } else {
        $stmt = mysqli_prepare($con, "UPDATE " . TABLE_NAME . " SET `$nombreCol` = ? WHERE `$idCol` = ?");
        mysqli_stmt_bind_param($stmt, 'si', $nombre, $id);
    }

    $ok = mysqli_stmt_execute($stmt);
    if (!$ok) responder(false, 'No se pudo editar la discapacidad.', null, 400);

    mysqli_close($con);
    responder(true, 'Discapacidad editada.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'eliminar') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $stmt = mysqli_prepare($con, "DELETE FROM " . TABLE_NAME . " WHERE `$idCol` = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok) responder(false, 'No se pudo eliminar. Puede estar en uso.', null, 400);
    if (mysqli_stmt_affected_rows($stmt) === 0) responder(false, 'Discapacidad no encontrada.', null, 404);

    mysqli_close($con);
    responder(true, 'Discapacidad eliminada.');
}

responder(false, 'Acción no reconocida.', null, 400);




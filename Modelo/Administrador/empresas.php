<?php

require_once __DIR__ . '/../../config/cors.php';
// Modelo/Administrador/empresas.php

header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
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

// Helper respuesta
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

function column_exists(mysqli $con, string $table, string $column): bool
{
    $safeTable = mysqli_real_escape_string($con, $table);
    $safeColumn = mysqli_real_escape_string($con, $column);
    $res = mysqli_query($con, "SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return $res && mysqli_num_rows($res) > 0;
}

function empresa_rfc_select(mysqli $con, string $empresaAlias = 'e', string $usuarioAlias = 'u'): string
{
    $empresaTieneRfc = column_exists($con, 'empresas', 'rfc');
    $usuarioTieneRfc = column_exists($con, 'usuarios', 'rfc_empresa');

    if ($empresaTieneRfc && $usuarioTieneRfc) {
        return "COALESCE(NULLIF($empresaAlias.rfc, ''), MAX(NULLIF($usuarioAlias.rfc_empresa, '')), '') AS rfc";
    }

    if ($empresaTieneRfc) {
        return "COALESCE($empresaAlias.rfc, '') AS rfc";
    }

    if ($usuarioTieneRfc) {
        return "COALESCE(MAX(NULLIF($usuarioAlias.rfc_empresa, '')), '') AS rfc";
    }

    return "'' AS rfc";
}

$accion = $_GET['accion'] ?? '';

// GET - listar empresas con filtros y busqueda
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'listar') {

    $estado = $_GET['estado'] ?? 'todas';    // todas | 0 (pendiente) | 1 (aprobada)
    $buscar = $_GET['buscar'] ?? '';
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $limite = max(1, min(100, (int)($_GET['limite'] ?? 15)));
    $offset = ($pagina - 1) * $limite;

    // Construir WHERE dinamico
    $condiciones = [];
    $params      = [];
    $tipos        = '';

    if (in_array($estado, ['0', '1', '2', '3'], true)) {
        $condiciones[] = 'e.estado_validacion = ?';
        $params[] = (int) $estado;
        $tipos .= 'i';
    }

    if ($buscar !== '') {
        $searchParts = ['e.nombre_empresas LIKE ?', 'e.correo_empresa LIKE ?', 'e.direccion LIKE ?'];
        if (column_exists($con, 'empresas', 'rfc')) {
            $searchParts[] = 'e.rfc LIKE ?';
        }
        if (column_exists($con, 'usuarios', 'rfc_empresa')) {
            $searchParts[] = 'u.rfc_empresa LIKE ?';
        }

        $condiciones[] = '(' . implode(' OR ', $searchParts) . ')';
        $like = "%{$buscar}%";
        foreach ($searchParts as $_) {
            $params[] = $like;
            $tipos .= 's';
        }
    }

    $where = count($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    // Total
    $sqlTotal = "
        SELECT COUNT(DISTINCT e.id_empresas) AS total
        FROM empresas e
        LEFT JOIN reclutadores r ON r.id_empresas = e.id_empresas
        LEFT JOIN usuarios u ON u.id_usuario = r.id_usuario
        $where
    ";
    $stmtT = mysqli_prepare($con, $sqlTotal);
    if ($params) mysqli_stmt_bind_param($stmtT, $tipos, ...$params);
    mysqli_stmt_execute($stmtT);
    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtT))['total'];

    // Datos + numero de reclutadores por empresa
    $rfcSelect = empresa_rfc_select($con);
    $sql = "
        SELECT
            e.id_empresas,
            e.nombre_empresas,
            $rfcSelect,
            e.descripcion,
            e.direccion,
            e.telefono_empresa,
            e.correo_empresa,
            e.sitio_web,
            e.estado_validacion,
            e.fecha_registro_empres AS fecha_registro,
            COUNT(DISTINCT r.id_reclutador) AS total_reclutadores,
            COUNT(DISTINCT v.id_vacante) AS total_vacantes
        FROM empresas e
        LEFT JOIN reclutadores r ON r.id_empresas = e.id_empresas
        LEFT JOIN usuarios u     ON u.id_usuario = r.id_usuario
        LEFT JOIN vacantes v     ON v.id_reclutador = r.id_reclutador
        $where
        GROUP BY e.id_empresas
        ORDER BY e.fecha_registro_empres DESC
        LIMIT ? OFFSET ?
    ";

    $allParams = array_merge($params, [$limite, $offset]);
    $allTipos  = $tipos . 'ii';

    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, $allTipos, ...$allParams);
    mysqli_stmt_execute($stmt);
    $res  = mysqli_stmt_get_result($stmt);

    $empresas = [];
    while ($fila = mysqli_fetch_assoc($res)) {
        $fila['estado_validacion'] = (int) $fila['estado_validacion'];
        $empresas[] = $fila;
    }

    mysqli_close($con);
    responder(true, 'Empresas obtenidas.', [
        'empresas'    => $empresas,
        'total'       => $total,
        'pagina'      => $pagina,
        'limite'      => $limite,
        'total_paginas' => ceil($total / $limite),
    ]);
}

// ------------------------------------------------------------
// GET - detalle de una empresa
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'detalle') {

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    // Datos empresa
    $rfcSelect = empresa_rfc_select($con);
    $stmt = mysqli_prepare($con, "
        SELECT e.*, $rfcSelect
        FROM empresas e
        LEFT JOIN reclutadores r ON r.id_empresas = e.id_empresas
        LEFT JOIN usuarios u ON u.id_usuario = r.id_usuario
        WHERE e.id_empresas = ?
        GROUP BY e.id_empresas
    ");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $empresa = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$empresa) responder(false, 'Empresa no encontrada.', null, 404);

    // Reclutadores
    $stmt2 = mysqli_prepare($con, "
        SELECT r.id_reclutador, r.puesto,
               u.nombres, u.apellidos, u.correo, u.telefono, u.foto_perfil, u.estado
        FROM reclutadores r
        JOIN usuarios u ON u.id_usuario = r.id_usuario
        WHERE r.id_empresas = ?
    ");
    mysqli_stmt_bind_param($stmt2, 'i', $id);
    mysqli_stmt_execute($stmt2);
    $resRec = mysqli_stmt_get_result($stmt2);
    $reclutadores = [];
    while ($r = mysqli_fetch_assoc($resRec)) $reclutadores[] = $r;

    // Vacantes recientes
    $stmt3 = mysqli_prepare($con, "
        SELECT v.id_vacante, v.titulo_puesto, v.modalidad, v.estado,
               v.fecha_publicacion,
               COUNT(p.id_postulacion) AS total_postulaciones
        FROM vacantes v
        JOIN reclutadores rec ON rec.id_reclutador = v.id_reclutador
        LEFT JOIN postulaciones p ON p.id_vacante = v.id_vacante
        WHERE rec.id_empresas = ?
        GROUP BY v.id_vacante
        ORDER BY v.fecha_publicacion DESC
        LIMIT 10
    ");
    mysqli_stmt_bind_param($stmt3, 'i', $id);
    mysqli_stmt_execute($stmt3);
    $resVac = mysqli_stmt_get_result($stmt3);
    $vacantes = [];
    while ($v = mysqli_fetch_assoc($resVac)) $vacantes[] = $v;

    $empresa['estado_validacion'] = (int) $empresa['estado_validacion'];
    mysqli_close($con);
    responder(true, 'Detalle obtenido.', [
        'empresa'      => $empresa,
        'reclutadores' => $reclutadores,
        'vacantes'     => $vacantes,
    ]);
}

// ------------------------------------------------------------
// POST - aprobar empresa (estado_validacion = 1)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'aprobar') {

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $stmt = mysqli_prepare($con, "UPDATE empresas SET estado_validacion = 1 WHERE id_empresas = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok || mysqli_stmt_affected_rows($stmt) === 0)
        responder(false, 'No se pudo aprobar o la empresa ya estaba aprobada.', null, 400);

    mysqli_close($con);
    responder(true, 'Empresa aprobada correctamente.');
}

// ------------------------------------------------------------
// POST - rechazar empresa (estado_validacion = 2)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'rechazar') {

    $id   = (int)($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true);
    $motivo = trim($body['motivo'] ?? '');

    if (!$id) responder(false, 'ID requerido.', null, 400);

    // Guardamos estado 2 = rechazada; si quieres un campo motivo en BD, agregalo
    $stmt = mysqli_prepare($con, "UPDATE empresas SET estado_validacion = 2 WHERE id_empresas = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok || mysqli_stmt_affected_rows($stmt) === 0)
        responder(false, 'No se pudo rechazar la empresa.', null, 400);

    mysqli_close($con);
    responder(true, 'Empresa rechazada.');
}

// ------------------------------------------------------------
// POST - suspender empresa (estado_validacion = 3)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'suspender') {

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $stmt = mysqli_prepare($con, "UPDATE empresas SET estado_validacion = 3 WHERE id_empresas = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok || mysqli_stmt_affected_rows($stmt) === 0)
        responder(false, 'No se pudo suspender la empresa.', null, 400);

    mysqli_close($con);
    responder(true, 'Empresa suspendida.');
}

// ------------------------------------------------------------
// POST - reactivar empresa (estado_validacion = 1)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'reactivar') {

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $stmt = mysqli_prepare($con, "UPDATE empresas SET estado_validacion = 1 WHERE id_empresas = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok || mysqli_stmt_affected_rows($stmt) === 0)
        responder(false, 'No se pudo reactivar la empresa.', null, 400);

    mysqli_close($con);
    responder(true, 'Empresa reactivada.');
}

responder(false, 'Accion no reconocida.', null, 400);

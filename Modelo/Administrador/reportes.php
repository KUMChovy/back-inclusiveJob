<?php

require_once __DIR__ . '/../../config/cors.php';
// Modelo/Administrador/reportes.php

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
    $stmt = mysqli_prepare($con, "
        SELECT
            rv.id_reporte,
            rv.id_vacante,
            rv.motivo,
            rv.fecha_reporte,
            v.titulo_puesto,
            v.estado AS estado_vacante,
            r.id_usuario AS id_usuario_reclutador,
            CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS usuario_reportante,
            u.correo AS correo_reportante
        FROM reporte_vacante rv
        INNER JOIN vacantes v ON rv.id_vacante = v.id_vacante
        INNER JOIN reclutadores r ON v.id_reclutador = r.id_reclutador
        INNER JOIN usuarios u ON rv.id_usuario = u.id_usuario
        WHERE rv.id_reporte = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row ?: null;
}

function get_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function notificar_reclutador_reporte(mysqli $con, array $reporte, string $accion, string $comentarioAdmin = ''): void
{
    $idUsuarioReclutador = (int)($reporte['id_usuario_reclutador'] ?? 0);
    if ($idUsuarioReclutador <= 0) return;

    $tituloVacante = $reporte['titulo_puesto'] ?? 'tu vacante';
    $motivo = trim((string)($reporte['motivo'] ?? ''));
    $comentarioAdmin = trim($comentarioAdmin);
    if (strlen($comentarioAdmin) > 500) {
        $comentarioAdmin = substr($comentarioAdmin, 0, 500);
    }

    if ($accion === 'ignorado') {
        $texto = "✅ El reporte sobre \"{$tituloVacante}\" fue revisado por administracion y descartado. Tu vacante conserva su estado actual.";
        $resultado = 'reporte_ignorado_admin';
        $tipo = 'reporte_resuelto';
    } elseif ($accion === 'vacante_eliminada') {
        $texto = "⚠️ Administracion reviso un reporte sobre \"{$tituloVacante}\" y marco la vacante como eliminada. Revisa tus publicaciones para evitar futuras sanciones.";
        if ($comentarioAdmin !== '') {
            $texto .= " Motivo del administrador: {$comentarioAdmin}";
        }
        $resultado = 'reporte_vacante_eliminada_admin';
        $tipo = 'reporte_reprimenda';
    } else {
        return;
    }

    $payload = [
        'text' => $texto,
        'tipo' => 'reporte_vacante',
        'accion' => $accion,
        'reporte' => [
            'id_reporte' => (int)($reporte['id_reporte'] ?? 0),
            'motivo' => $motivo,
            'fecha_reporte' => $reporte['fecha_reporte'] ?? null,
            'usuario_reportante' => trim((string)($reporte['usuario_reportante'] ?? '')),
            'comentario_admin' => $comentarioAdmin,
        ],
        'vacante' => [
            'id_vacante' => (int)($reporte['id_vacante'] ?? 0),
            'titulo_puesto' => $tituloVacante,
        ],
    ];

    $mensajeUsuario = $accion === 'ignorado'
        ? 'Administracion descarto un reporte de vacante'
        : 'Administracion elimino una vacante reportada';
    $respuesta = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $stmt = mysqli_prepare($con, "
        INSERT INTO chabots
            (tipo_interaccion, mensaje_usuario, respuesta_ia, resultado, calificacion, archivo_cv, id_usuario)
        VALUES (?, ?, ?, ?, NULL, NULL, ?)
    ");

    if (!$stmt) return;

    mysqli_stmt_bind_param($stmt, 'ssssi', $tipo, $mensajeUsuario, $respuesta, $resultado, $idUsuarioReclutador);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
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

    $reporte = get_reporte($con, $id);
    if (!$reporte) responder(false, 'Reporte no encontrado.', null, 404);

    $stmt = mysqli_prepare($con, 'DELETE FROM reporte_vacante WHERE id_reporte = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $ok = mysqli_stmt_execute($stmt);

    if (!$ok) responder(false, 'No se pudo ignorar el reporte.', null, 400);
    if (mysqli_stmt_affected_rows($stmt) === 0) responder(false, 'Reporte no encontrado.', null, 404);

    notificar_reclutador_reporte($con, $reporte, 'ignorado');

    mysqli_close($con);
    responder(true, 'Reporte ignorado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'eliminar_vacante') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) responder(false, 'ID requerido.', null, 400);

    $body = get_json_body();
    $comentarioAdmin = trim((string)($body['explicacion'] ?? $body['comentario_admin'] ?? $body['motivo_admin'] ?? ''));
    if ($comentarioAdmin === '') {
        responder(false, 'La explicacion del administrador es requerida.', null, 400);
    }
    if (strlen($comentarioAdmin) > 500) {
        responder(false, 'La explicacion debe tener maximo 500 caracteres.', null, 400);
    }

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

    notificar_reclutador_reporte($con, $reporte, 'vacante_eliminada', $comentarioAdmin);

    mysqli_commit($con);
    mysqli_close($con);
    responder(true, 'Vacante eliminada.', ['id_vacante' => $idVacante]);
}

responder(false, 'Acción no reconocida.', null, 400);

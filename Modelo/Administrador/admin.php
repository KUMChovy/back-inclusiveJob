<?php
// Modelo/Administrador/admin.php


header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../Conexion.php';

$con = conectarbd();

// ── Helper: respuesta JSON ────────────────────────────────────
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

// ── Solo GET permitido ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responder(false, 'Método no permitido.', null, 405);
}

// ── Acción según parámetro ────────────────────────────────────
$accion = $_GET['accion'] ?? 'stats';

if ($accion === 'stats') {
    $stats = [];

    // Total usuarios registrados
    $r = mysqli_query($con, "SELECT COUNT(*) AS total FROM usuarios");
    $stats['total_usuarios'] = (int) mysqli_fetch_assoc($r)['total'];

    // Total postulantes (id_rol = 2)
    $r = mysqli_query($con, "SELECT COUNT(*) AS total FROM usuarios WHERE id_rol = 2");
    $stats['total_postulantes'] = (int) mysqli_fetch_assoc($r)['total'];

    // Total reclutadores (id_rol = 3)
    $r = mysqli_query($con, "SELECT COUNT(*) AS total FROM usuarios WHERE id_rol = 3");
    $stats['total_reclutadores'] = (int) mysqli_fetch_assoc($r)['total'];

    // Total empresas
    $r = mysqli_query($con, "SELECT COUNT(*) AS total FROM empresas");
    $stats['total_empresas'] = (int) mysqli_fetch_assoc($r)['total'];

    // Empresas pendientes de validación (estado_validacion = 0)
    $r = mysqli_query($con, "SELECT COUNT(*) AS total FROM empresas WHERE estado_validacion = 0");
    $stats['empresas_pendientes'] = (int) mysqli_fetch_assoc($r)['total'];

    // Vacantes activas
    $r = mysqli_query($con, "SELECT COUNT(*) AS total FROM vacantes WHERE estado = 'activa'");
    $stats['vacantes_activas'] = (int) mysqli_fetch_assoc($r)['total'];

    // Vacantes cerradas
    $r = mysqli_query($con, "SELECT COUNT(*) AS total FROM vacantes WHERE estado = 'cerrada'");
    $stats['vacantes_cerradas'] = (int) mysqli_fetch_assoc($r)['total'];

    // Total postulaciones
    $r = mysqli_query($con, "SELECT COUNT(*) AS total FROM postulaciones");
    $stats['postulaciones_realizadas'] = (int) mysqli_fetch_assoc($r)['total'];

    // Total reportes recibidos
    $r = mysqli_query($con, "SELECT COUNT(*) AS total FROM reporte_vacante");
    $stats['reportes_recibidos'] = (int) mysqli_fetch_assoc($r)['total'];

    // ── Actividad por mes (últimos 6 meses) ──────────────────
    $sql_meses = "
        SELECT
            DATE_FORMAT(fecha_publicacion, '%b') AS mes,
            DATE_FORMAT(fecha_publicacion, '%Y-%m') AS mes_orden,
            COUNT(*) AS vacantes
        FROM vacantes
        WHERE fecha_publicacion >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY mes_orden, mes
        ORDER BY mes_orden ASC
    ";
    $r_meses = mysqli_query($con, $sql_meses);
    $meses   = [];
    while ($fila = mysqli_fetch_assoc($r_meses)) {
        $meses[] = [
            'mes'      => $fila['mes'],
            'vacantes' => (int) $fila['vacantes'],
        ];
    }
    $stats['actividad_mensual'] = $meses;

    // ── Últimas 5 vacantes publicadas ────────────────────────
    $sql_vacantes = "
        SELECT
            v.id_vacante,
            v.titulo_puesto,
            e.nombre_empresas AS empresa,
            v.modalidad,
            v.estado,
            v.fecha_publicacion
        FROM vacantes v
        JOIN reclutadores rec ON rec.id_reclutador = v.id_reclutador
        JOIN empresas e       ON e.id_empresas     = rec.id_empresas
        ORDER BY v.fecha_publicacion DESC
        LIMIT 5
    ";
    $r_vac = mysqli_query($con, $sql_vacantes);
    $ultimas_vacantes = [];
    while ($fila = mysqli_fetch_assoc($r_vac)) {
        $ultimas_vacantes[] = $fila;
    }
    $stats['ultimas_vacantes'] = $ultimas_vacantes;

    // ── Últimas 5 empresas pendientes ────────────────────────
    $sql_emp = "
        SELECT
            id_empresas,
            nombre_empresas,
            correo_empresa,
            fecha_registro_empres AS fecha_registro
        FROM empresas
        WHERE estado_validacion = 0
        ORDER BY fecha_registro_empres DESC
        LIMIT 5
    ";
    $r_emp = mysqli_query($con, $sql_emp);
    $empresas_pendientes = [];
    while ($fila = mysqli_fetch_assoc($r_emp)) {
        $empresas_pendientes[] = $fila;
    }
    $stats['empresas_pendientes_lista'] = $empresas_pendientes;

    mysqli_close($con);
    responder(true, 'Estadísticas obtenidas correctamente.', $stats);
}

responder(false, 'Acción no reconocida.', null, 400);
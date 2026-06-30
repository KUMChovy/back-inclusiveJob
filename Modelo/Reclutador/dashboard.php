<?php
<<<<<<< HEAD
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers();

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);

$u_empresa = reclutador_columna_existe($con, "usuarios", "empresa") ? "u.empresa" : "NULL";
$u_validada = reclutador_columna_existe($con, "usuarios", "empresa_validada") ? "u.empresa_validada" : "NULL";

$stmt_user = mysqli_prepare($con, "SELECT
        u.id_usuario,
        u.nombres,
        u.apellidos,
        u.correo,
        COALESCE(e.nombre_empresas, $u_empresa, '') AS empresa,
        COALESCE(e.estado_validacion, $u_validada, 0) AS empresa_validada
    FROM usuarios u
    LEFT JOIN reclutadores r ON r.id_usuario = u.id_usuario
    LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
    WHERE u.id_usuario = ?
    LIMIT 1");
mysqli_stmt_bind_param($stmt_user, "i", $id_usuario);
mysqli_stmt_execute($stmt_user);
$user_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_user));

$user = [
    "id" => (int)$user_row["id_usuario"],
    "nombre" => trim(($user_row["nombres"] ?? "") . " " . ($user_row["apellidos"] ?? "")),
    "correo" => $user_row["correo"] ?? "",
    "rol" => "Reclutador"
];

$empresa = [
    "nombre" => $user_row["empresa"] ?? "",
    "aprobada" => (int)($user_row["empresa_validada"] ?? 0) === 1,
    "estado" => ((int)($user_row["empresa_validada"] ?? 0) === 1) ? "aprobada" : "pendiente"
];

function scalar_query($con, $sql, $types, ...$params) {
    $stmt = mysqli_prepare($con, $sql);
    if ($types !== "") {
=======
session_set_cookie_params([
    "httponly" => true,
    "secure" => false,
    "samesite" => "Strict"
]);
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["auth" => false, "message" => "Metodo no permitido"]);
    exit;
}

if (!isset($_SESSION["user"]["id"])) {
    http_response_code(401);
    echo json_encode(["auth" => false]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

require_once __DIR__ . "/../../Conexion.php";
$con = conectarbd();
mysqli_set_charset($con, "utf8mb4");

function rows_query($con, string $sql, string $types = '', array $params = []) {
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        throw new Exception(mysqli_error($con));
    }
    if ($types !== '') {
>>>>>>> 9c12442f48a480a83674472513139362cf0b0b00
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
<<<<<<< HEAD
    $row = mysqli_fetch_row($res);
    return (int)($row[0] ?? 0);
}

$stats = [
    "vacantes_activas" => scalar_query($con, "SELECT COUNT(*) FROM vacantes WHERE id_reclutador = ? AND estado = 'activa'", "i", $id_reclutador),
    "candidatos_totales" => scalar_query($con, "SELECT COUNT(*) FROM postulaciones p INNER JOIN vacantes v ON p.id_vacante = v.id_vacante WHERE v.id_reclutador = ?", "i", $id_reclutador),
    "postulaciones_hoy" => scalar_query($con, "SELECT COUNT(*) FROM postulaciones p INNER JOIN vacantes v ON p.id_vacante = v.id_vacante WHERE v.id_reclutador = ? AND DATE(p.fecha_postulacion) = CURDATE()", "i", $id_reclutador),
    "reportes_activos" => scalar_query($con, "SELECT COUNT(*) FROM reporte_vacante rv INNER JOIN vacantes v ON rv.id_vacante = v.id_vacante WHERE v.id_reclutador = ?", "i", $id_reclutador)
];

$chart = [];
for ($i = 6; $i >= 0; $i--) {
    $fecha = date("Y-m-d", strtotime("-{$i} days"));
    $chart[] = [
        "day" => date("d/m", strtotime($fecha)),
        "postulaciones" => scalar_query($con, "SELECT COUNT(*) FROM postulaciones p INNER JOIN vacantes v ON p.id_vacante = v.id_vacante WHERE v.id_reclutador = ? AND DATE(p.fecha_postulacion) = ?", "is", $id_reclutador, $fecha),
        "vistas" => 0
    ];
}

$stmt_vac = mysqli_prepare($con, "SELECT v.id_vacante AS id, v.titulo_puesto AS titulo, v.estado, v.fecha_publicacion, COUNT(p.id_postulacion) AS postulaciones FROM vacantes v LEFT JOIN postulaciones p ON v.id_vacante = p.id_vacante WHERE v.id_reclutador = ? GROUP BY v.id_vacante ORDER BY v.fecha_publicacion DESC LIMIT 5");
mysqli_stmt_bind_param($stmt_vac, "i", $id_reclutador);
mysqli_stmt_execute($stmt_vac);
$res_vac = mysqli_stmt_get_result($stmt_vac);
$vacantes_recientes = [];
while ($row = mysqli_fetch_assoc($res_vac)) {
    $row["id"] = (int)$row["id"];
    $row["postulaciones"] = (int)$row["postulaciones"];
    $row["vistas"] = 0;
    $row["dias"] = $row["fecha_publicacion"] ? max(0, (int)floor((time() - strtotime($row["fecha_publicacion"])) / 86400)) : 0;
    $vacantes_recientes[] = $row;
}

$stmt_can = mysqli_prepare($con, "SELECT
        p.id_postulacion AS id,
        CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS nombre,
        v.titulo_puesto AS vacante,
        COALESCE(p.estado, 'pendiente') AS estado,
        p.fecha_postulacion
    FROM postulaciones p
    INNER JOIN vacantes v ON p.id_vacante = v.id_vacante
    INNER JOIN postulantes pos ON p.id_postulante = pos.id_postulante
    INNER JOIN usuarios u ON pos.id_usuario = u.id_usuario
    WHERE v.id_reclutador = ?
    ORDER BY p.fecha_postulacion DESC
    LIMIT 5");
mysqli_stmt_bind_param($stmt_can, "i", $id_reclutador);
mysqli_stmt_execute($stmt_can);
$res_can = mysqli_stmt_get_result($stmt_can);
$candidatos_recientes = [];
while ($row = mysqli_fetch_assoc($res_can)) {
    $row["tiempo"] = $row["fecha_postulacion"] ? date("d/m/Y", strtotime($row["fecha_postulacion"])) : "";
    $candidatos_recientes[] = $row;
}

reclutador_responder(true, "Dashboard obtenido", [
    "user" => $user,
    "empresa" => $empresa,
    "stats" => $stats,
    "chart" => $chart,
    "vacantes_recientes" => $vacantes_recientes,
    "candidatos_recientes" => $candidatos_recientes
]);
?>
=======
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function scalar_query($con, string $sql, string $types = '', array $params = []) {
    $rows = rows_query($con, $sql, $types, $params);
    if (!$rows) return 0;
    $first = array_values($rows[0]);
    return (int)($first[0] ?? 0);
}

function table_exists($con, string $table) {
    $safe = mysqli_real_escape_string($con, $table);
    $res = mysqli_query($con, "SHOW TABLES LIKE '$safe'");
    return $res && mysqli_num_rows($res) > 0;
}

try {
    $usuarioRows = rows_query($con,
        "SELECT id_usuario, nombres, apellidos, correo, foto_perfil, id_rol
         FROM usuarios
         WHERE id_usuario = ? AND estado = 1
         LIMIT 1",
        "i",
        [$id_usuario]
    );

    if (!$usuarioRows || (int)$usuarioRows[0]["id_rol"] !== 3) {
        mysqli_close($con);
        http_response_code(403);
        echo json_encode(["auth" => false, "message" => "Solo reclutadores pueden consultar este dashboard"]);
        exit;
    }

    $usuario = $usuarioRows[0];

    $reclutadorRows = rows_query($con,
        "SELECT
            r.id_reclutador,
            r.puesto,
            r.id_empresas,
            e.nombre_empresas,
            e.estado_validacion
         FROM reclutadores r
         LEFT JOIN empresas e ON e.id_empresas = r.id_empresas
         WHERE r.id_usuario = ?
         LIMIT 1",
        "i",
        [$id_usuario]
    );

    if (!$reclutadorRows) {
        mysqli_close($con);
        http_response_code(404);
        echo json_encode(["auth" => true, "success" => false, "message" => "No existe perfil de reclutador"]);
        exit;
    }

    $reclutador = $reclutadorRows[0];
    $id_reclutador = (int)$reclutador["id_reclutador"];
    $empresaAprobada = ((int)($reclutador["estado_validacion"] ?? 0)) === 1;

    $vacantesActivas = scalar_query($con,
        "SELECT COUNT(*) FROM vacantes WHERE id_reclutador = ? AND estado = 'activa'",
        "i",
        [$id_reclutador]
    );

    $candidatosTotales = scalar_query($con,
        "SELECT COUNT(DISTINCT p.id_postulante)
         FROM postulaciones p
         INNER JOIN vacantes v ON v.id_vacante = p.id_vacante
         WHERE v.id_reclutador = ?",
        "i",
        [$id_reclutador]
    );

    $postulacionesHoy = scalar_query($con,
        "SELECT COUNT(*)
         FROM postulaciones p
         INNER JOIN vacantes v ON v.id_vacante = p.id_vacante
         WHERE v.id_reclutador = ?
           AND DATE(p.fecha_postulacion) = CURDATE()",
        "i",
        [$id_reclutador]
    );

    $reportesActivos = 0;
    if (table_exists($con, "reportes")) {
        $reportesActivos = scalar_query($con,
            "SELECT COUNT(*)
             FROM reportes r
             INNER JOIN vacantes v ON v.id_vacante = r.id_vacante
             WHERE v.id_reclutador = ?
               AND COALESCE(r.estado, 'activo') IN ('activo', 'pendiente')",
            "i",
            [$id_reclutador]
        );
    }

    $dias = [];
    for ($i = 6; $i >= 0; $i--) {
        $fecha = date('Y-m-d', strtotime("-$i days"));
        $label = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'][(int)date('w', strtotime($fecha))];
        $post = scalar_query($con,
            "SELECT COUNT(*)
             FROM postulaciones p
             INNER JOIN vacantes v ON v.id_vacante = p.id_vacante
             WHERE v.id_reclutador = ?
               AND DATE(p.fecha_postulacion) = ?",
            "is",
            [$id_reclutador, $fecha]
        );
        $dias[] = [
            "day" => $label,
            "postulaciones" => $post,
            "vistas" => ($post * 4) + $vacantesActivas,
        ];
    }

    $vacantes = rows_query($con,
        "SELECT
            v.id_vacante AS id,
            v.titulo_puesto AS titulo,
            v.estado,
            v.fecha_publicacion,
            COUNT(p.id_postulacion) AS postulaciones
         FROM vacantes v
         LEFT JOIN postulaciones p ON p.id_vacante = v.id_vacante
         WHERE v.id_reclutador = ?
         GROUP BY v.id_vacante, v.titulo_puesto, v.estado, v.fecha_publicacion
         ORDER BY v.fecha_publicacion DESC
         LIMIT 4",
        "i",
        [$id_reclutador]
    );

    foreach ($vacantes as &$v) {
        $v["id"] = (int)$v["id"];
        $v["postulaciones"] = (int)$v["postulaciones"];
        $v["vistas"] = ($v["postulaciones"] * 4) + 12;
        $v["dias"] = max(0, (int)floor((time() - strtotime($v["fecha_publicacion"])) / 86400));
    }
    unset($v);

    $candidatos = rows_query($con,
        "SELECT
            p.id_postulacion AS id,
            LOWER(p.estado) AS estado,
            p.fecha_postulacion,
            v.titulo_puesto AS vacante,
            CONCAT(u.nombres, ' ', u.apellidos) AS nombre
         FROM postulaciones p
         INNER JOIN vacantes v ON v.id_vacante = p.id_vacante
         INNER JOIN postulantes po ON po.id_postulante = p.id_postulante
         INNER JOIN usuarios u ON u.id_usuario = po.id_usuario
         WHERE v.id_reclutador = ?
         ORDER BY p.fecha_postulacion DESC
         LIMIT 4",
        "i",
        [$id_reclutador]
    );

    foreach ($candidatos as &$c) {
        $segundos = time() - strtotime($c["fecha_postulacion"]);
        if ($segundos < 86400) {
            $c["tiempo"] = max(1, (int)floor($segundos / 3600)) . "h";
        } else {
            $c["tiempo"] = (int)floor($segundos / 86400) . "d";
        }
        $c["id"] = (int)$c["id"];
    }
    unset($c);

    mysqli_close($con);

    echo json_encode([
        "auth" => true,
        "user" => [
            "id" => (int)$usuario["id_usuario"],
            "nombre" => trim($usuario["nombres"] . " " . $usuario["apellidos"]),
            "rol" => $reclutador["puesto"] ?: "Reclutador",
            "avatar" => $usuario["foto_perfil"],
        ],
        "empresa" => [
            "id" => $reclutador["id_empresas"] ? (int)$reclutador["id_empresas"] : null,
            "nombre" => $reclutador["nombre_empresas"] ?: "Empresa sin registrar",
            "estado" => $empresaAprobada ? "aprobada" : "pendiente",
            "aprobada" => $empresaAprobada,
        ],
        "stats" => [
            "vacantes_activas" => $vacantesActivas,
            "candidatos_totales" => $candidatosTotales,
            "postulaciones_hoy" => $postulacionesHoy,
            "reportes_activos" => $reportesActivos,
        ],
        "chart" => $dias,
        "vacantes_recientes" => $vacantes,
        "candidatos_recientes" => $candidatos,
    ]);
} catch (Throwable $e) {
    error_log("[RECLUTADOR_DASHBOARD] " . $e->getMessage());
    if (isset($con)) {
        mysqli_close($con);
    }
    http_response_code(500);
    echo json_encode(["auth" => true, "success" => false, "message" => "Error al cargar dashboard"]);
}
?>
>>>>>>> 9c12442f48a480a83674472513139362cf0b0b00

<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../Conexion.php';
$con = conectarbd();

function responder($ok, $mensaje, $datos = null) {
    echo json_encode([
        'success' => $ok,
        'message' => $mensaje,
        'data'    => $datos
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$accion = $_GET['accion'] ?? '';

// ─────────────────────────────────────────────────────────────
// ACCIÓN 1: LISTAR VACANTES CON SU CONTEO DE CANDIDATOS
// ─────────────────────────────────────────────────────────────
if ($accion === 'listar_por_vacante') {
    $sql = "SELECT 
                v.id_vacante, 
                v.titulo_puesto, 
                v.modalidad, 
                v.estado,
                COUNT(p.id_postulacion) AS total_candidatos
            FROM vacantes v
            LEFT JOIN postulaciones p ON v.id_vacante = p.id_vacante
            WHERE v.estado != 'eliminada'
            GROUP BY v.id_vacante
            ORDER BY total_candidatos DESC";
            
    $res = mysqli_query($con, $sql);
    $resultados = [];
    
    while ($row = mysqli_fetch_assoc($res)) {
        $row['id_vacante'] = (int)$row['id_vacante'];
        $row['total_candidatos'] = (int)$row['total_candidatos'];
        $resultados[] = $row;
    }
    
    mysqli_close($con);
    echo json_encode($resultados, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────
// ACCIÓN 2: VER LOS CANDIDATOS DE UNA VACANTE ESPECÍFICA (CON SU PERFIL EXTENDIDO)
// ─────────────────────────────────────────────────────────────
if ($accion === 'detalles_candidatos') {
    $id_vacante = (int)($_GET['id_vacante'] ?? 0);
    if (!$id_vacante) responder(false, 'ID de vacante requerido.');

    // Agregamos COALESCE para los campos extras del perfil del candidato por si están vacíos
    $sql = "SELECT 
                p.id_postulacion,
                p.fecha_postulacion,
                u.id_usuario,
                CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS nombre_completo,
                u.correo,
                COALESCE(u.telefono, 'No especificado') AS telefono,
                COALESCE(u.cv_url, '') AS cv_url,
                COALESCE(u.resumen_profesional, 'Sin resumen profesional registrado.') AS resumen_profesional,
                COALESCE(u.habilidades, 'No especificadas') AS habilidades,
                COALESCE(u.experiencia, 'Sin experiencia laboral registrada.') AS experiencia,
                COALESCE(u.educacion, 'No especificada') AS educacion
            FROM postulaciones p
            INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
            WHERE p.id_vacante = ?";
            
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_vacante);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    $candidatos = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $candidatos[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($con);
    
    echo json_encode($candidatos, JSON_UNESCAPED_UNICODE);
    exit;
}

responder(false, 'Acción no válida en el módulo de candidatos.');
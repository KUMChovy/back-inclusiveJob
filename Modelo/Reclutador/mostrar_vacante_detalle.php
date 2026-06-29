<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once("../../Conexion.php");

$con = conectarbd();

$id_vacante = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_vacante) {
    echo json_encode(["ok" => false, "mensaje" => "ID de vacante no proporcionado"]);
    exit;
}

// Detalle de la vacante
$sql = "
SELECT
    v.id_vacante,
    v.titulo_puesto,
    v.descripcion_puesto,
    v.requisitos,
    v.salario_min,
    v.salario_max,
    v.modalidad,
    v.estado,
    v.fecha_publicacion,
    v.fecha_cierre,
    e.nombre_empresas AS nombre_empresa

FROM vacantes v
INNER JOIN reclutadores r ON v.id_reclutador = r.id_reclutador
LEFT JOIN empresas e      ON r.id_empresas   = e.id_empresas

WHERE v.id_vacante = ?
";

$stmt = $con->prepare($sql);
$stmt->bind_param("i", $id_vacante);
$stmt->execute();
$vacante = $stmt->get_result()->fetch_assoc();

if (!$vacante) {
    echo json_encode(["ok" => false, "mensaje" => "Vacante no encontrada"]);
    exit;
}

// Postulaciones de esa vacante
$sql2 = "
SELECT
    u.nombres,
    u.apellidos,
    u.correo,
    p.estado,
    p.fecha_postulacion

FROM postulaciones p
INNER JOIN postulantes pt ON p.id_postulante = pt.id_postulante
INNER JOIN usuarios u     ON pt.id_usuario   = u.id_usuario

WHERE p.id_vacante = ?
ORDER BY p.fecha_postulacion DESC
";

$stmt2 = $con->prepare($sql2);
$stmt2->bind_param("i", $id_vacante);
$stmt2->execute();
$res2 = $stmt2->get_result();

$postulaciones = [];
while ($fila = $res2->fetch_assoc()) {
    $postulaciones[] = [
        "postulante" => $fila["nombres"] . " " . $fila["apellidos"],
        "correo"     => $fila["correo"],
        "estado"     => $fila["estado"],
        "fecha_postulacion" => $fila["fecha_postulacion"],
    ];
}

echo json_encode([
    "ok"   => true,
    "data" => [
        "vacante"       => $vacante,
        "postulaciones" => $postulaciones,
    ]
]);

$stmt->close();
$stmt2->close();
mysqli_close($con);
?>
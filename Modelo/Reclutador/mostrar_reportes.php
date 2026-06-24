<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once("../../Conexion.php");

$con = conectarbd();

/* temporal */
$id_usuario = 3;

$sql = "

SELECT

r.id_reporte,
r.motivo,
r.fecha_reporte,

v.id_vacante,
v.titulo_puesto,
v.fecha_publicacion,

u.nombres,
u.apellidos,
u.correo

FROM reporte_vacante r

INNER JOIN vacantes v
ON r.id_vacante = v.id_vacante

INNER JOIN usuarios u
ON r.id_usuario = u.id_usuario

WHERE r.id_usuario = ?

ORDER BY r.fecha_reporte DESC

";

$stmt = $con->prepare($sql);

$stmt->bind_param("i", $id_usuario);

$stmt->execute();

$resultado = $stmt->get_result();

$reportes = [];

while ($fila = $resultado->fetch_assoc()) {

    $reportes[] = [

        "id" => $fila["id_reporte"],
        "motivo" => $fila["motivo"],
        "fecha_reporte" => $fila["fecha_reporte"],

        "vacante" => $fila["titulo_puesto"],
        "fecha_publicacion" => $fila["fecha_publicacion"],

        // 👇 ESTO ES LO QUE TE FALTABA PARA TU FRONTEND
        "usuario_nombre" => $fila["nombres"] . " " . $fila["apellidos"],
        "correo" => $fila["correo"]
    ];
}

echo json_encode([
    "ok" => true,
    "data" => $reportes
]);

$stmt->close();
mysqli_close($con);

?>
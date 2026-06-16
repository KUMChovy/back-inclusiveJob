<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once("../../Conexion.php");


$con = conectarbd();

/*después cambiar por sesión*/
$id_postulante = 1;


$sql = "

SELECT

p.id_postulacion,
p.estado,
p.fecha_postulacion,

v.id_vacante,
v.titulo_puesto,
v.modalidad

FROM postulaciones p

INNER JOIN vacantes v
ON p.id_vacante = v.id_vacante

WHERE p.id_postulante = ?

ORDER BY p.fecha_postulacion DESC

";


$stmt = $con->prepare($sql);

$stmt->bind_param(
    "i",
    $id_postulante
);

$stmt->execute();

$resultado =
$stmt->get_result();


$postulaciones = [];

while(
$fila =
$resultado->fetch_assoc()
){

$postulaciones[] = [

"id" =>
$fila["id_postulacion"],

"vacante" =>
$fila["titulo_puesto"],

"modalidad" =>
$fila["modalidad"],

"estado" =>
strtolower(
$fila["estado"]
),

"fecha" =>
$fila["fecha_postulacion"]

];

}


echo json_encode([

"ok" => true,

"data" => $postulaciones

]);


$stmt->close();

mysqli_close($con);

?>
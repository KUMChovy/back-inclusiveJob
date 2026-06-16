<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once("../../Conexion.php");

$con = conectarbd();

/*Cambiar luego con el sesion*/
$id_usuario = 4;

$sql = "

SELECT

r.id_reporte,
r.motivo,
r.fecha_reporte,

v.titulo_puesto,
v.modalidad

FROM reporte_vacante r

INNER JOIN vacantes v
ON r.id_vacante = v.id_vacante

WHERE r.id_usuario = ?

ORDER BY r.fecha_reporte DESC

";

$stmt =
$con->prepare($sql);

$stmt->bind_param(
"i",
$id_usuario
);

$stmt->execute();

$resultado =
$stmt->get_result();

$reportes=[];

while(
$fila=
$resultado->fetch_assoc()
){

$reportes[]=[

"id"=>
$fila["id_reporte"],

"vacante"=>
$fila["titulo_puesto"],

"motivo"=>
$fila["motivo"],

"modalidad"=>
$fila["modalidad"],

"fecha"=>
$fila["fecha_reporte"]

];

}

echo json_encode([

"ok"=>true,

"data"=>$reportes

]);

$stmt->close();

mysqli_close($con);

?>
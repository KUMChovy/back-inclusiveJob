<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once("../../Conexion.php");

$con = conectarbd();

/*Cambiar luego con el sesion*/
$id_usuario = 3;

$sql = "
SELECT
  r.id_reporte,
  r.motivo,
  r.fecha_reporte,

  v.id_vacante,
  v.titulo_puesto,
  v.descripcion_puesto,
  v.requisitos,
  v.modalidad,
  v.salario_min,
  v.salario_max,
  v.estado,
  v.fecha_publicacion,
  v.fecha_cierre,

  e.nombre_empresas

FROM reporte_vacante r

INNER JOIN vacantes v
  ON r.id_vacante = v.id_vacante

INNER JOIN reclutadores rec
  ON v.id_reclutador = rec.id_reclutador

INNER JOIN empresas e
  ON rec.id_empresas = e.id_empresas

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

$reportes[] = [
  "id" => $fila["id_reporte"],
  "vacante_id" => $fila["id_vacante"],

  "vacante" => $fila["titulo_puesto"],
  "descripcion_puesto" => $fila["descripcion_puesto"],

  "requisitos" => $fila["requisitos"],
  "modalidad" => $fila["modalidad"],

  "salario_min" => $fila["salario_min"],
  "salario_max" => $fila["salario_max"],

  "estado" => $fila["estado"],

  "fecha_publicacion" => $fila["fecha_publicacion"],
  "fecha_cierre" => $fila["fecha_cierre"],

  "motivo" => $fila["motivo"],
  "empresa" => $fila["nombre_empresas"],
  "fecha" => $fila["fecha_reporte"]
];

}

echo json_encode([

"ok"=>true,

"data"=>$reportes

]);

$stmt->close();

mysqli_close($con);

?>
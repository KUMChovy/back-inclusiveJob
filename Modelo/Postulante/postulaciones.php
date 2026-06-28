<?php

session_set_cookie_params([
    "httponly"  => true,
    "secure"    => false,
    "samesite"  => "Strict"
]);

session_start();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once("../../Conexion.php");


$con = conectarbd();

if (!isset($_SESSION["user"]["id"])) {
    echo json_encode(["debug" => "no_session"]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

$stmt = $con->prepare("SELECT id_postulante FROM postulantes WHERE id_usuario = ? LIMIT 1");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!$res) {
    echo json_encode(["debug" => "no_postulante"]);
    exit;
}

$id_postulante = (int)$res["id_postulante"];
$stmt->close();


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
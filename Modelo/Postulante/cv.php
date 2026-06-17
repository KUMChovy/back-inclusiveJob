<?php

require_once("../../Conexion.php");

$con = conectarbd();

/* luego sesión */
$id_postulante = 2;


$sql = "

SELECT cv

FROM postulantes

WHERE id_postulante = ?

LIMIT 1

";


$stmt =
$con->prepare($sql);


$stmt->bind_param(
"i",
$id_postulante
);


$stmt->execute();

$resultado =
$stmt->get_result();

$fila =
$resultado->fetch_assoc();


if(
$fila &&
!empty($fila["cv"])
){

header(
"Content-Type: application/pdf"
);


header(
"Content-Disposition: inline; filename=cv.pdf"
);

echo $fila["cv"];

}
else{

http_response_code(
404
);

echo "No hay CV";

}


$stmt->close();

mysqli_close(
$con
);

?>
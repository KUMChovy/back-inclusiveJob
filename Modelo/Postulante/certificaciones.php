<?php

require_once("../../Conexion.php");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$con = conectarbd();

// reemplazar sesión real
$id_postulante = 10;

/*
──────────────────────────────
VER PDF
GET ?archivo=1&id=#
──────────────────────────────
*/

if (
    isset($_GET['archivo']) &&
    isset($_GET['id'])
) {

    $id = intval($_GET['id']);

    $sql = "
        SELECT pdf
        FROM certificaciones
        WHERE id_certificaciones=?
        AND id_postulante=?
        LIMIT 1
    ";

    $stmt = $con->prepare($sql);

    $stmt->bind_param(
        "ii",
        $id,
        $id_postulante
    );

    $stmt->execute();

    $res = $stmt->get_result();

    $fila = $res->fetch_assoc();

    if ($fila && !empty($fila["pdf"])) {

        header("Content-Type: application/pdf");

        header(
            "Content-Disposition:inline; filename=certificacion.pdf"
        );

        header(
            "Cache-Control:no-store,no-cache,must-revalidate"
        );

        echo $fila["pdf"];

    } else {

        http_response_code(404);

        echo "PDF no encontrado";
    }

    exit;
}

/*
──────────────────────────────
LISTAR CERTIFICACIONES
GET
──────────────────────────────
*/

header("Content-Type: application/json");

$sql = "

SELECT
id_certificaciones,
nombre_certificacion,
institucion_dada,
fecha_emitido

FROM certificaciones

WHERE id_postulante=?

ORDER BY fecha_emitido DESC

";

$stmt = $con->prepare($sql);

$stmt->bind_param(
    "i",
    $id_postulante
);

$stmt->execute();

$res = $stmt->get_result();

$data = [];

while ($fila = $res->fetch_assoc()) {

    $data[] = [
        "id"=>$fila["id_certificaciones"],
        "nombre"=>$fila["nombre_certificacion"],
        "emisor"=>$fila["institucion_dada"],
        "fecha"=>$fila["fecha_emitido"]
    ];
}

echo json_encode([
    "ok"=>true,
    "certificaciones"=>$data
]);

$stmt->close();

mysqli_close($con);

?>

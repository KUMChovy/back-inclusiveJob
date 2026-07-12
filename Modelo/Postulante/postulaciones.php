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
v.descripcion_puesto,
v.requisitos,
v.modalidad,
v.salario_min,
v.salario_max,
v.fecha_publicacion,
v.fecha_cierre,

e.nombre_empresas

FROM postulaciones p

INNER JOIN vacantes v
ON p.id_vacante = v.id_vacante

INNER JOIN reclutadores r
ON v.id_reclutador = r.id_reclutador

INNER JOIN empresas e
ON r.id_empresas = e.id_empresas

WHERE p.id_postulante = ?
AND COALESCE(e.estado_validacion, 0) = 1

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

"vacante_id" =>
$fila["id_vacante"],

"vacante" =>
$fila["titulo_puesto"],

"empresa" =>
$fila["nombre_empresas"],

"descripcion_puesto" =>
$fila["descripcion_puesto"],

"requisitos" =>
$fila["requisitos"],

"modalidad" =>
$fila["modalidad"],

"salario_min" =>
$fila["salario_min"],

"salario_max" =>
$fila["salario_max"],

"estado" =>
strtolower(
$fila["estado"]
),

"fecha_publicacion" =>
$fila["fecha_publicacion"],

"fecha_cierre" =>
$fila["fecha_cierre"],

"fecha" =>
$fila["fecha_postulacion"]

];

}

// ───────────────────────────────────────────
// ELIMINAR POSTULACIÓN
// ───────────────────────────────────────────

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {

$body =
json_decode(
file_get_contents("php://input"),
true
);

$id_postulacion =
(int)($body["id_postulacion"] ?? 0);

if (!$id_postulacion) {

echo json_encode([
"ok"=>false,
"msg"=>"ID inválido"
]);

exit;

}


// validar que la postulación sea del usuario

$stmt =
$con->prepare("

DELETE FROM postulaciones

WHERE
id_postulacion = ?
AND id_postulante = ?

LIMIT 1

");

$stmt->bind_param(
"ii",
$id_postulacion,
$id_postulante
);

$stmt->execute();


if (
$stmt->affected_rows <= 0
) {

echo json_encode([
"ok"=>false,
"msg"=>"No encontrada"
]);

$stmt->close();
mysqli_close($con);
exit;

}

$stmt->close();

echo json_encode([
"ok"=>true,
"msg"=>"Postulación eliminada"
]);

mysqli_close($con);

exit;

}

// CREAR POSTULACIÓN
// ───────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $body       = json_decode(file_get_contents("php://input"), true);
    $id_vacante = isset($body["id_vacante"]) ? (int)$body["id_vacante"] : 0;

    if (!$id_vacante) {
        echo json_encode(["ok" => false, "msg" => "ID de vacante requerido."]);
        exit;
    }

    // Solo permitir postulaciones a vacantes activas de empresas aprobadas
    $stmtVac = $con->prepare("
        SELECT v.id_vacante
        FROM vacantes v
        INNER JOIN reclutadores r ON r.id_reclutador = v.id_reclutador
        INNER JOIN empresas e ON e.id_empresas = r.id_empresas
        WHERE v.id_vacante = ?
          AND v.estado = 'activa'
          AND COALESCE(e.estado_validacion, 0) = 1
        LIMIT 1
    ");
    $stmtVac->bind_param("i", $id_vacante);
    $stmtVac->execute();
    $stmtVac->store_result();
    if ($stmtVac->num_rows === 0) {
        echo json_encode(["ok" => false, "msg" => "La vacante no esta disponible para postulaciones."]);
        $stmtVac->close();
        exit;
    }
    $stmtVac->close();

    // Verificar que no esté ya postulado
    $stmtDup = $con->prepare("SELECT id_postulacion FROM postulaciones WHERE id_postulante = ? AND id_vacante = ? LIMIT 1");
    $stmtDup->bind_param("ii", $id_postulante, $id_vacante);
    $stmtDup->execute();
    $stmtDup->store_result();

    if ($stmtDup->num_rows > 0) {
        echo json_encode(["ok" => false, "msg" => "Ya te postulaste a esta vacante."]);
        $stmtDup->close();
        exit;
    }
    $stmtDup->close();

    $stmtIns = $con->prepare("INSERT INTO postulaciones (id_postulante, id_vacante, estado) VALUES (?, ?, 'pendiente')");
    $stmtIns->bind_param("ii", $id_postulante, $id_vacante);
    $ok = $stmtIns->execute();
    $stmtIns->close();

    echo json_encode(["ok" => $ok, "msg" => $ok ? "Postulación enviada." : "Error al postularse."]);
    mysqli_close($con);
    exit;
}

echo json_encode([

"ok" => true,

"data" => $postulaciones

]);


$stmt->close();

mysqli_close($con);

?>

<?php

require_once("../../Conexion.php");

session_set_cookie_params([
    "httponly"  => true,
    "secure"    => false,
    "samesite"  => "Strict"
]);

session_start();

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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

/* VER PDF - GET ?archivo=1&id=# */
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['archivo'])
    && isset($_GET['id'])
) {
    $id = intval($_GET['id']);

    $sql = "SELECT pdf FROM certificaciones WHERE id_certificaciones=? AND id_postulante=? LIMIT 1";
    $stmt = $con->prepare($sql);
    $stmt->bind_param("ii", $id, $id_postulante);
    $stmt->execute();
    $res  = $stmt->get_result();
    $fila = $res->fetch_assoc();

    if ($fila && !empty($fila["pdf"])) {
        header("Content-Type: application/pdf");
        header("Content-Disposition: inline; filename=certificacion.pdf");
        header("Cache-Control: no-store,no-cache,must-revalidate");
        echo $fila["pdf"];
    } else {
        http_response_code(404);
        echo "PDF no encontrado";
    }

    $stmt->close();
    mysqli_close($con);
    exit;
}

/* ELIMINAR - DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    header("Content-Type: application/json");

    $body = json_decode(file_get_contents("php://input"), true);
    $id   = intval($body['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(["ok" => false, "msg" => "ID invalido"]);
        exit;
    }

    $sql  = "DELETE FROM certificaciones WHERE id_certificaciones=? AND id_postulante=? LIMIT 1";
    $stmt = $con->prepare($sql);
    $stmt->bind_param("ii", $id, $id_postulante);
    $ok   = $stmt->execute();

    echo json_encode([
        "ok"  => $ok,
        "msg" => $ok ? "Certificacion eliminada" : $stmt->error
    ]);

    $stmt->close();
    mysqli_close($con);
    exit;
}

/* CREAR / EDITAR - POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");

    $nombre      = trim($_POST['nombre_certificacion'] ?? '');
    $institucion = trim($_POST['institucion_dada']     ?? '');
    $fecha       = trim($_POST['fecha_emitido']        ?? '');
    $id          = isset($_POST['id']) ? intval($_POST['id']) : null;

    if (!$nombre || !$institucion || !$fecha) {
        http_response_code(400);
        echo json_encode(["ok" => false, "msg" => "Campos incompletos"]);
        exit;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha) {
        http_response_code(400);
        echo json_encode(["ok" => false, "msg" => "Fecha invalida"]);
        exit;
    }

    if ($dt > new DateTime()) {
        http_response_code(400);
        echo json_encode(["ok" => false, "msg" => "La fecha no puede ser futura"]);
        exit;
    }

    if ($id) {
        if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
            $mime = mime_content_type($_FILES['pdf']['tmp_name']);
            if ($mime !== 'application/pdf') {
                http_response_code(415);
                echo json_encode(["ok" => false, "msg" => "Solo PDF"]);
                exit;
            }

            $pdf = file_get_contents($_FILES['pdf']['tmp_name']);

            $sql  = "UPDATE certificaciones SET nombre_certificacion=?, institucion_dada=?, pdf=?, fecha_emitido=? WHERE id_certificaciones=? AND id_postulante=?";
            $stmt = $con->prepare($sql);
            $null = null;
            $stmt->bind_param("ssbsii", $nombre, $institucion, $null, $fecha, $id, $id_postulante);
            $stmt->send_long_data(2, $pdf);
        } else {
            $sql  = "UPDATE certificaciones SET nombre_certificacion=?, institucion_dada=?, fecha_emitido=? WHERE id_certificaciones=? AND id_postulante=?";
            $stmt = $con->prepare($sql);
            $stmt->bind_param("sssii", $nombre, $institucion, $fecha, $id, $id_postulante);
        }
    } else {
        if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(["ok" => false, "msg" => "Selecciona PDF"]);
            exit;
        }

        $mime = mime_content_type($_FILES['pdf']['tmp_name']);
        if ($mime !== 'application/pdf') {
            http_response_code(415);
            echo json_encode(["ok" => false, "msg" => "Solo PDF"]);
            exit;
        }

        $pdf = file_get_contents($_FILES['pdf']['tmp_name']);

        $sql  = "INSERT INTO certificaciones (nombre_certificacion, institucion_dada, pdf, fecha_emitido, id_postulante) VALUES (?, ?, ?, ?, ?)";
        $stmt = $con->prepare($sql);
        $null = null;
        $stmt->bind_param("ssbsi", $nombre, $institucion, $null, $fecha, $id_postulante);
        $stmt->send_long_data(2, $pdf);
    }

    $ok = $stmt->execute();

    echo json_encode([
        "ok"  => $ok,
        "msg" => $ok ? "Guardado correctamente" : $stmt->error
    ]);

    $stmt->close();
    mysqli_close($con);
    exit;
}

/* LISTAR - GET */
header("Content-Type: application/json");

$sql  = "SELECT id_certificaciones, nombre_certificacion, institucion_dada, fecha_emitido FROM certificaciones WHERE id_postulante=? ORDER BY fecha_emitido DESC";
$stmt = $con->prepare($sql);
$stmt->bind_param("i", $id_postulante);
$stmt->execute();
$res  = $stmt->get_result();

$data = [];
while ($fila = $res->fetch_assoc()) {
    $data[] = [
        "id"     => $fila["id_certificaciones"],
        "nombre" => $fila["nombre_certificacion"],
        "emisor" => $fila["institucion_dada"],
        "fecha"  => $fila["fecha_emitido"],
    ];
}

echo json_encode(["ok" => true, "certificaciones" => $data]);

$stmt->close();
mysqli_close($con);
?>
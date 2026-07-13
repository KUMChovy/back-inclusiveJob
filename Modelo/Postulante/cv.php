<?php


require_once __DIR__ . '/../../config/cors.php';
require_once("../../Conexion.php");

inclusijob_session_cookie_params();

session_start();

// Cabeceras CORS para evitar errores desde el frontend
header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Responder preflight OPTIONS
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

/* ─────────────────────────────
   MOSTRAR CV  (GET)
───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $sql = "
        SELECT cv
        FROM postulantes
        WHERE id_postulante = ?
        LIMIT 1
    ";

    $stmt = $con->prepare($sql);
    $stmt->bind_param("i", $id_postulante);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $fila = $resultado->fetch_assoc();

    if ($fila && !empty($fila["cv"])) {

        header("Content-Type: application/pdf");
        header("Content-Disposition: inline; filename=cv.pdf");
        // Evitar que el navegador cachee el PDF viejo
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Pragma: no-cache");

        echo $fila["cv"];

    } else {
        http_response_code(404);
        echo "No hay CV registrado";
    }

    $stmt->close();
    mysqli_close($con);
    exit;
}

/* ─────────────────────────────
   SUBIR / ACTUALIZAR CV  (POST)
───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header("Content-Type: application/json");

    // Validar que se envió el archivo
    if (!isset($_FILES['cv']) || $_FILES['cv']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = isset($_FILES['cv'])
            ? "Error al subir archivo (código " . $_FILES['cv']['error'] . ")"
            : "No se envió ningún archivo";

        http_response_code(400);
        echo json_encode(["ok" => false, "msg" => $errorMsg]);
        exit;
    }

    // Validar que sea PDF
    $mimeType = mime_content_type($_FILES['cv']['tmp_name']);
    if ($mimeType !== 'application/pdf') {
        http_response_code(415);
        echo json_encode(["ok" => false, "msg" => "Solo se aceptan archivos PDF"]);
        exit;
    }

    // Leer el binario del PDF
    $cv = file_get_contents($_FILES['cv']['tmp_name']);

    if ($cv === false) {
        http_response_code(500);
        echo json_encode(["ok" => false, "msg" => "No se pudo leer el archivo"]);
        exit;
    }

    $sql = "
        UPDATE postulantes
        SET cv = ?
        WHERE id_postulante = ?
    ";

    $stmt = $con->prepare($sql);

    // ✅ CORRECCIÓN CLAVE: para BLOB se usa null en bind_param
    //    y send_long_data() para enviar el binario
    $null = null;
    $stmt->bind_param("bi", $null, $id_postulante);
    $stmt->send_long_data(0, $cv);   // 0 = índice del parámetro BLOB

    $ok = $stmt->execute();

    if ($ok) {
        echo json_encode(["ok" => true, "msg" => "CV actualizado correctamente"]);
    } else {
        http_response_code(500);
        echo json_encode(["ok" => false, "msg" => "Error al guardar en base de datos: " . $stmt->error]);
    }

    $stmt->close();
    mysqli_close($con);
    exit;
}

// Método no permitido
http_response_code(405);
echo json_encode(["ok" => false, "msg" => "Método no permitido"]);
mysqli_close($con);
?>
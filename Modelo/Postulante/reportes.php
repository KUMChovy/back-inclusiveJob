<?php


require_once __DIR__ . '/../../config/cors.php';
inclusijob_session_cookie_params();

session_start();


header("Content-Type: application/json");
header("Access-Control-Allow-Origin: " . INCLUSIJOB_FRONTEND_ORIGIN);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once("../../Conexion.php");

$con = conectarbd();

if (!isset($_SESSION["user"]["id"])) {
    echo json_encode(["debug" => "no_session"]);
    exit;
}

$id_usuario = (int)$_SESSION["user"]["id"];

/* ─────────────────────────────────────
  ACCIÓN (LISTAR O ELIMINAR)
───────────────────────────────────── */

$method = $_SERVER['REQUEST_METHOD'];

/* ─────────────────────────────────────
  LISTAR REPORTES (GET)
───────────────────────────────────── */
if ($method === 'GET') {

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
    AND COALESCE(e.estado_validacion, 0) = 1

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
    "ok" => true,
    "data" => $reportes
  ]);

  $stmt->close();
}

/* ─────────────────────────────────────
   ELIMINAR REPORTE (DELETE)
───────────────────────────────────── */
if ($method === 'DELETE') {

  $data = json_decode(file_get_contents("php://input"), true);

  $id_reporte = $data["id_reporte"] ?? null;

  if (!$id_reporte) {
    echo json_encode([
      "ok" => false,
      "msg" => "ID de reporte requerido"
    ]);
    exit;
  }

  $sql = "DELETE FROM reporte_vacante WHERE id_reporte = ? AND id_usuario = ?";

  $stmt = $con->prepare($sql);
  $stmt->bind_param("ii", $id_reporte, $id_usuario);

  $ok = $stmt->execute();

  echo json_encode([
    "ok" => $ok,
    "msg" => $ok ? "Reporte eliminado" : "Error al eliminar"
  ]);

  $stmt->close();
}

/* ─────────────────────────────────────
  EDITAR REPORTE (PUT)
───────────────────────────────────── */
if ($method === 'PUT') {

  $data = json_decode(file_get_contents("php://input"), true);

  $id_reporte = $data["id_reporte"] ?? null;
  $motivo = $data["motivo"] ?? null;

  if (!$id_reporte || !$motivo) {
    echo json_encode([
      "ok" => false,
      "msg" => "Faltan datos"
    ]);
    exit;
  }

  $sql = "UPDATE reporte_vacante 
          SET motivo = ? 
          WHERE id_reporte = ? AND id_usuario = ?";

  $stmt = $con->prepare($sql);
  $stmt->bind_param("sii", $motivo, $id_reporte, $id_usuario);

  $ok = $stmt->execute();

  echo json_encode([
    "ok" => $ok,
    "msg" => $ok ? "Motivo actualizado" : "Error al actualizar"
  ]);

  $stmt->close();
}

/* ─────────────────────────────────────────
   CREAR REPORTE (POST)
───────────────────────────────────────── */
if ($method === 'POST') {
    $data       = json_decode(file_get_contents("php://input"), true);
    $id_vacante = isset($data["id_vacante"]) ? (int)$data["id_vacante"] : 0;
    $motivo     = isset($data["motivo"])     ? trim($data["motivo"])     : "";

    if (!$id_vacante || $motivo === "") {
        echo json_encode(["ok" => false, "msg" => "Faltan datos requeridos."]);
        exit;
    }

    // Solo permitir reportes sobre vacantes visibles para postulantes
    $stmtVac = $con->prepare("
        SELECT v.id_vacante
        FROM vacantes v
        INNER JOIN reclutadores rec ON rec.id_reclutador = v.id_reclutador
        INNER JOIN empresas e ON e.id_empresas = rec.id_empresas
        WHERE v.id_vacante = ?
          AND v.estado = 'activa'
          AND COALESCE(e.estado_validacion, 0) = 1
        LIMIT 1
    ");
    $stmtVac->bind_param("i", $id_vacante);
    $stmtVac->execute();
    $stmtVac->store_result();
    if ($stmtVac->num_rows === 0) {
        echo json_encode(["ok" => false, "msg" => "La vacante no esta disponible para reportarse."]);
        $stmtVac->close();
        exit;
    }
    $stmtVac->close();

    // Evitar reporte duplicado
    $stmtDup = $con->prepare("SELECT id_reporte FROM reporte_vacante WHERE id_vacante = ? AND id_usuario = ? LIMIT 1");
    $stmtDup->bind_param("ii", $id_vacante, $id_usuario);
    $stmtDup->execute();
    $stmtDup->store_result();
    if ($stmtDup->num_rows > 0) {
        echo json_encode(["ok" => false, "msg" => "Ya reportaste esta vacante."]);
        $stmtDup->close();
        exit;
    }
    $stmtDup->close();

    $stmtIns = $con->prepare("INSERT INTO reporte_vacante (id_vacante, id_usuario, motivo) VALUES (?, ?, ?)");
    $stmtIns->bind_param("iis", $id_vacante, $id_usuario, $motivo);
    $ok = $stmtIns->execute();
    $stmtIns->close();

    echo json_encode(["ok" => $ok, "msg" => $ok ? "Reporte enviado." : "Error al guardar."]);
    exit;
}

mysqli_close($con);

?>

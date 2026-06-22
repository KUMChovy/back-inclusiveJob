<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

require_once("../../Conexion.php");

$con = conectarbd();

/* Cambiar luego con sesión */
$id_usuario = 4;

/* ─────────────────────────────────────
   🧠 ACCIÓN (LISTAR O ELIMINAR)
───────────────────────────────────── */

$method = $_SERVER['REQUEST_METHOD'];

/* ─────────────────────────────────────
   🟢 LISTAR REPORTES (GET)
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
   🔴 ELIMINAR REPORTE (DELETE)
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

mysqli_close($con);

?>
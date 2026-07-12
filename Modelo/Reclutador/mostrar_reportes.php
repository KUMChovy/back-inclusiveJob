<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers("GET, POST, DELETE, PUT, OPTIONS");

$con = conectarbd();
$id_usuario = reclutador_usuario_id();
$id_reclutador = reclutador_id_desde_usuario($con, $id_usuario);
$accion = $_GET["accion"] ?? "listar";

if ($_SERVER["REQUEST_METHOD"] === "POST" && $accion === "marcar_aviso_atendido") {
    $data = reclutador_json_body();
    $idAviso = (int)($_GET["id"] ?? ($data["id_aviso"] ?? $data["id"] ?? 0));

    if (!$idAviso) {
        reclutador_responder(false, "ID de aviso requerido.", null, 400);
    }

    $sqlAtender = "UPDATE chabots
                   SET resultado = CONCAT(COALESCE(resultado, 'reporte_aviso'), '_atendido'),
                       tipo_interaccion = 'reporte_atendido'
                   WHERE id_chatbot = ?
                     AND id_usuario = ?
                     AND resultado NOT LIKE '%_atendido'
                     AND (
                        resultado IN ('reporte_ignorado_admin', 'reporte_vacante_eliminada_admin')
                        OR tipo_interaccion IN ('reporte_resuelto', 'reporte_reprimenda')
                     )";
    $stmtAtender = mysqli_prepare($con, $sqlAtender);
    mysqli_stmt_bind_param($stmtAtender, "ii", $idAviso, $id_usuario);
    mysqli_stmt_execute($stmtAtender);

    if (mysqli_stmt_affected_rows($stmtAtender) === 0) {
        reclutador_responder(false, "Aviso no encontrado o ya atendido.", null, 404);
    }

    reclutador_responder(true, "Aviso marcado como atendido.", ["id_aviso" => $idAviso]);
}

$sql = "SELECT
            rv.id_reporte,
            rv.motivo,
            rv.fecha_reporte,
            v.id_vacante,
            v.titulo_puesto,
            v.fecha_publicacion,
            u.nombres,
            u.apellidos,
            u.correo
        FROM reporte_vacante rv
        INNER JOIN vacantes v ON rv.id_vacante = v.id_vacante
        INNER JOIN usuarios u ON rv.id_usuario = u.id_usuario
        WHERE v.id_reclutador = ?
        ORDER BY rv.fecha_reporte DESC";

$stmt = mysqli_prepare($con, $sql);
mysqli_stmt_bind_param($stmt, "i", $id_reclutador);
mysqli_stmt_execute($stmt);
$resultado = mysqli_stmt_get_result($stmt);

$reportes = [];
while ($fila = mysqli_fetch_assoc($resultado)) {
    $reportes[] = [
        "id" => (int)$fila["id_reporte"],
        "motivo" => $fila["motivo"],
        "fecha_reporte" => $fila["fecha_reporte"],
        "id_vacante" => (int)$fila["id_vacante"],
        "vacante" => $fila["titulo_puesto"],
        "fecha_publicacion" => $fila["fecha_publicacion"],
        "usuario_nombre" => trim(($fila["nombres"] ?? "") . " " . ($fila["apellidos"] ?? "")),
        "correo" => $fila["correo"]
    ];
}

$avisos = [];
$sqlAvisos = "SELECT
                  id_chatbot,
                  tipo_interaccion,
                  mensaje_usuario,
                  respuesta_ia,
                  resultado,
                  fecha
              FROM chabots
              WHERE id_usuario = ?
                AND resultado NOT LIKE '%_atendido'
                AND (
                    resultado IN ('reporte_ignorado_admin', 'reporte_vacante_eliminada_admin')
                    OR tipo_interaccion IN ('reporte_resuelto', 'reporte_reprimenda')
                )
              ORDER BY fecha DESC, id_chatbot DESC
              LIMIT 20";

$stmtAvisos = mysqli_prepare($con, $sqlAvisos);
mysqli_stmt_bind_param($stmtAvisos, "i", $id_usuario);
mysqli_stmt_execute($stmtAvisos);
$resAvisos = mysqli_stmt_get_result($stmtAvisos);

while ($fila = mysqli_fetch_assoc($resAvisos)) {
    $payload = json_decode((string)($fila["respuesta_ia"] ?? ""), true);
    if (!is_array($payload)) $payload = [];

    $reportePayload = is_array($payload["reporte"] ?? null) ? $payload["reporte"] : [];
    $vacantePayload = is_array($payload["vacante"] ?? null) ? $payload["vacante"] : [];

    $avisos[] = [
        "id" => (int)$fila["id_chatbot"],
        "tipo_interaccion" => $fila["tipo_interaccion"],
        "resultado" => $fila["resultado"],
        "accion" => $payload["accion"] ?? null,
        "texto" => $payload["text"] ?? $fila["mensaje_usuario"],
        "fecha" => $fila["fecha"],
        "id_vacante" => isset($vacantePayload["id_vacante"]) ? (int)$vacantePayload["id_vacante"] : null,
        "vacante" => $vacantePayload["titulo_puesto"] ?? "Vacante",
        "motivo" => $reportePayload["motivo"] ?? "",
        "comentario_admin" => $reportePayload["comentario_admin"] ?? "",
        "usuario_reportante" => $reportePayload["usuario_reportante"] ?? "",
        "fecha_reporte" => $reportePayload["fecha_reporte"] ?? null,
    ];
}

reclutador_responder(true, "Reportes obtenidos", [
    "reportes" => $reportes,
    "avisos" => $avisos,
]);
?>

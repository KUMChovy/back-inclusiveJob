<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../Conexion.php';

reclutador_headers('GET, OPTIONS');

$con = conectarbd();
reclutador_usuario_id();
$accion = $_GET['accion'] ?? 'discapacidades';

if ($accion === 'discapacidades' || $accion === 'listar_discapacidades') {
    $sql = "SELECT
                id_tipo_discapacidad,
                nombre_discapacidad,
                COALESCE(descripcion, '') AS descripcion
            FROM tipo_discapacidad
            ORDER BY nombre_discapacidad ASC";
    $res = mysqli_query($con, $sql);

    if (!$res) {
        reclutador_responder(false, 'No se pudieron obtener las discapacidades.', null, 500);
    }

    $discapacidades = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $discapacidades[] = [
            'id_tipo_discapacidad' => (int)$row['id_tipo_discapacidad'],
            'nombre_discapacidad' => $row['nombre_discapacidad'],
            'descripcion' => $row['descripcion'],
        ];
    }

    reclutador_responder(true, 'Discapacidades obtenidas', [
        'discapacidades' => $discapacidades,
        'total' => count($discapacidades),
    ]);
}

reclutador_responder(false, 'Accion no valida.', null, 400);
?>

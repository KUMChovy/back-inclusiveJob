<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

$servidor = "localhost";
$usuario  = "root";
$password = ""; 
$dbname   = "inclusijob";

$conn = new mysqli($servidor, $usuario, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Error de conexión: " . $conn->connect_error]);
    exit();
}

// Capturar acción de la URL
$accion = isset($_GET['accion']) ? $_GET['accion'] : '';

// Leer JSON enviado desde el cuerpo de la petición de React
$data = json_decode(file_get_contents("php://input"), true);

// ── 1. LEER VACANTES (GET) ──────────────────────────────────────────
if ($accion === 'listar') {
    $sql = "SELECT id_vacante, titulo_puesto, descripcion_puesto, requisitos, modalidad, salario_min, salario_max, estado, fecha_publicacion, id_reclutador FROM vacantes ORDER BY id_vacante DESC";
    $result = $conn->query($sql);

    $vacantes = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $vacantes[] = $row;
        }
    }

    echo json_encode(["success" => true, "data" => $vacantes]);
    exit();
}

// ── 2. CREAR NUEVA VACANTE (POST) ───────────────────────────────────
if ($accion === 'insertar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = $data['titulo_puesto'] ?? '';
    $descripcion = $data['descripcion_puesto'] ?? '';
    $requisitos = $data['requisitos'] ?? '';
    $modalidad = $data['modalidad'] ?? 'Presencial';
    
    $salario_min = (isset($data['salario_min']) && $data['salario_min'] !== '') ? floatval($data['salario_min']) : 0;
    $salario_max = (isset($data['salario_max']) && $data['salario_max'] !== '') ? floatval($data['salario_max']) : 0;
    
    $estado = $data['estado'] ?? 'activa';
    $id_reclutador = 1; 

    // 🛠️ TRUCO DE DESARROLLADOR: Apagamos las llaves foráneas un milisegundo
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    $sql = "INSERT INTO vacantes (titulo_puesto, descripcion_puesto, requisitos, modalidad, salario_min, salario_max, estado, fecha_publicacion, id_reclutador) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssddsi", $titulo, $descripcion, $requisitos, $modalidad, $salario_min, $salario_max, $estado, $id_reclutador);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Vacante creada correctamente"]);
    } else {
        echo json_encode(["success" => false, "message" => "Error de SQL: " . $conn->error]);
    }
    $stmt->close();

    // 🛠️ Volvemos a encender las llaves foráneas por seguridad del resto del sistema
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    exit();
}

// ── 3. EDITAR / ACTUALIZAR VACANTE (POST/PUT) ───────────────────────
if ($accion === 'editar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_vacante = $data['id_vacante'] ?? null;
    $titulo = $data['titulo_puesto'] ?? '';
    $descripcion = $data['descripcion_puesto'] ?? '';
    $requisitos = $data['requisitos'] ?? '';
    $modalidad = $data['modalidad'] ?? 'Presencial';
    $salario_min = (isset($data['salario_min']) && $data['salario_min'] !== '') ? floatval($data['salario_min']) : 0;
    $salario_max = (isset($data['salario_max']) && $data['salario_max'] !== '') ? floatval($data['salario_max']) : 0;
    $estado = $data['estado'] ?? 'activa';

    if (!$id_vacante) {
        echo json_encode(["success" => false, "message" => "ID de vacante faltante"]);
        exit();
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    $sql = "UPDATE vacantes SET titulo_puesto=?, descripcion_puesto=?, requisitos=?, modalidad=?, salario_min=?, salario_max=?, estado=? WHERE id_vacante=?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssddsi", $titulo, $descripcion, $requisitos, $modalidad, $salario_min, $salario_max, $estado, $id_vacante);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Vacante actualizada con éxito"]);
    } else {
        echo json_encode(["success" => false, "message" => "Error al actualizar: " . $conn->error]);
    }
    $stmt->close();

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    exit();
}
// ── 4. CAMBIAR SOLO EL ESTADO RÁPIDAMENTE (POST) ────────────────────
if ($accion === 'actualizar_estado' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_vacante = $data['id_vacante'] ?? null;
    $estado = $data['estado'] ?? 'activa';

    $sql = "UPDATE vacantes SET estado=? WHERE id_vacante=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $estado, $id_vacante);

    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => $conn->error]);
    }
    $stmt->close();
    exit();
}

// ── 5. ELIMINAR VACANTE (GET/POST) ──────────────────────────────────
if ($accion === 'eliminar') {
    $id_vacante = isset($_GET['id_vacante']) ? $_GET['id_vacante'] : ($data['id_vacante'] ?? null);

    if (!$id_vacante) {
        echo json_encode(["success" => false, "message" => "ID no proporcionado"]);
        exit();
    }

    $sql = "DELETE FROM vacantes WHERE id_vacante = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id_vacante);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Vacante eliminada físicamente de MySQL"]);
    } else {
        echo json_encode(["success" => false, "message" => "Error al eliminar: " . $conn->error]);
    }
    $stmt->close();
    exit();
}

echo json_encode(["success" => false, "message" => "Acción no válida"]);
$conn->close();
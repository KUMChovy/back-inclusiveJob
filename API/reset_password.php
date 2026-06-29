<?php

require_once __DIR__ . '/../Conexion.php';

$conn = conectarbd();

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die("Token no proporcionado.");
}

// Buscar token
$stmt = mysqli_prepare(
    $conn,
    "SELECT id_usuario, tiempo_expira, veces_usado
     FROM tokens
     WHERE token = ?
     AND tipo = 'password_reset'"
);

mysqli_stmt_bind_param($stmt, "s", $token);
mysqli_stmt_execute($stmt);

$resultado = mysqli_stmt_get_result($stmt);
$tokenData = mysqli_fetch_assoc($resultado);

// Validar token
if (
    !$tokenData ||
    $tokenData['veces_usado'] > 0 ||
    strtotime($tokenData['tiempo_expira']) < time()
) {
    die("El enlace es inválido o ya expiró.");
}

$error = '';
$exito = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nuevaPass = trim($_POST['password'] ?? '');
    $confirmPass = trim($_POST['confirm_password'] ?? '');

    if (strlen($nuevaPass) < 6) {

        $error = "La contraseña debe tener al menos 6 caracteres.";

    } elseif ($nuevaPass !== $confirmPass) {

        $error = "Las contraseñas no coinciden.";

    } else {

        $hash = password_hash(
            $nuevaPass,
            PASSWORD_DEFAULT
        );

        // Actualizar contraseña
        $update = mysqli_prepare(
    $conn,
    "UPDATE usuarios
     SET contraseña = ?
     WHERE id_usuario = ?"
);

if (!$update) {
    die("Error al preparar UPDATE: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $update,
    "si",
    $hash,
    $tokenData['id_usuario']
);

if (!mysqli_stmt_execute($update)) {
    die("Error al ejecutar UPDATE: " . mysqli_stmt_error($update));
}

// Verificar si realmente se actualizó
// $filas = mysqli_affected_rows($conn);

// echo "<pre>";
// echo "ID Usuario: " . $tokenData['id_usuario'] . "\n";
// echo "Filas afectadas: " . $filas . "\n";
// echo "Hash generado: " . $hash . "\n";
// echo "</pre>";
// exit;

// Marcar token como usado

$updateToken = mysqli_prepare(
    $conn,
    "UPDATE tokens
     SET veces_usado = veces_usado + 1
     WHERE token = ?"
);

mysqli_stmt_bind_param(
    $updateToken,
    "s",
    $token
);

mysqli_stmt_execute($updateToken);

$exito = true;
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña - InclusiJob</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen">

<div class="bg-white p-8 rounded-2xl shadow-xl w-full max-w-md">

<?php if ($exito): ?>

    <div class="text-center">

        <div class="text-green-600 text-5xl mb-4">
            ✅
        </div>

        <h2 class="text-2xl font-bold text-gray-800">
            ¡Contraseña actualizada!
        </h2>

        <p class="mt-2 text-gray-600">
            Ya puedes iniciar sesión con tu nueva contraseña.
        </p>

        <a
            href="http://localhost:5173/login"
            class="mt-6 inline-block bg-blue-600 text-white px-6 py-2 rounded-xl hover:bg-blue-700 transition"
        >
            Ir al login
        </a>

    </div>

<?php else: ?>

    <h2 class="text-2xl font-bold text-center text-gray-800 mb-6">
        Nueva contraseña
    </h2>

    <?php if ($error): ?>

        <div class="bg-red-100 text-red-700 p-3 rounded-xl mb-4">
            <?php echo htmlspecialchars($error); ?>
        </div>

    <?php endif; ?>

    <form method="POST">

        <div class="mb-4">

            <label class="block text-sm font-medium text-gray-700 mb-1">
                Nueva contraseña
            </label>

            <input
                type="password"
                name="password"
                required
                minlength="6"
                class="w-full px-4 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >

        </div>

        <div class="mb-6">

            <label class="block text-sm font-medium text-gray-700 mb-1">
                Confirmar contraseña
            </label>

            <input
                type="password"
                name="confirm_password"
                required
                minlength="6"
                class="w-full px-4 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >

        </div>

        <button
            type="submit"
            class="w-full bg-blue-600 text-white py-2 rounded-xl font-medium hover:bg-blue-700 transition"
        >
            Restablecer contraseña
        </button>

    </form>

<?php endif; ?>

</div>

</body>
</html>

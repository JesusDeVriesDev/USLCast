<?php
session_start();

// --- conexión simple a PostgreSQL ---
$host = "localhost";
$dbname = "uslcast";
$user = "postgres";
$pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);

// --- Verificar si el usuario está logueado ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Acceso denegado</h1><p style='text-align:center;'>Debes iniciar sesión para acceder a esta página.</p>");
}

// --- Obtener rol del usuario ---
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] != 2) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Acceso restringido</h1><p style='text-align:center;'>Esta página es solo para organizadores (rol 2).</p>");
}

$error = "";
$success = "";

// --- Crear nuevo meet ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $date_format = $_POST['date_format'];
    $meet_date = $_POST['meet_date'];
    $units = $_POST['units'];
    $federation = trim($_POST['federation']);
    $contact_email = trim($_POST['contact_email']);
    $organizer_id = $_SESSION['user_id'];

    // Evitar que el formulario se procese vacío
    if ($name === "" || $meet_date === "") {
        $error = "Por favor completa todos los campos obligatorios.";
    } else {
        // Generar slug limpio y único
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
        $slug = trim($slug, '-');

        // Verificar si el slug ya existe
        $check = $pdo->prepare("SELECT id FROM meets WHERE slug = :slug");
        $check->execute(['slug' => $slug]);
        if ($check->fetch()) {
            $slug .= "-" . uniqid(); // Añadir un sufijo único
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO meets (organizer_id, name, slug, federation, meet_date, units, contact_email)
                VALUES (:organizer_id, :name, :slug, :federation, :meet_date, :units, :contact_email)
            ");
            $stmt->execute([
                'organizer_id' => $organizer_id,
                'name' => $name,
                'slug' => $slug,
                'federation' => $federation,
                'meet_date' => $meet_date,
                'units' => $units,
                'contact_email' => $contact_email
            ]);

            // Redirigir para evitar reenvío de formulario
            header("Location: create_meet.php?success=1");
            exit;

        } catch (PDOException $e) {
            $error = "Ocurrió un error al crear la competencia. Inténtalo nuevamente.";
        }
    }
}

// Mostrar mensaje si viene de redirección
if (isset($_GET['success'])) {
    $success = "✅ Competencia creada exitosamente.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Competencia | USLCast</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Estilos -->
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">USLCast</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
      aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="../index.php">Inicio</a></li>
        <li class="nav-item"><a class="nav-link" href="dashboard.php">Panel</a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php">Salir</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- FORMULARIO DE CREACIÓN -->
<div class="register-container">
    <div class="register-card text-center">
        <h3 class="mb-4 text-danger fw-bold">Crear Nueva Competencia</h3>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3 text-start">
                <label for="name" class="form-label">Nombre de la competencia</label>
                <input type="text" id="name" name="name" class="form-control bg-dark text-light" required>
            </div>

            <div class="mb-3 text-start">
                <label for="date_format" class="form-label">Formato de fecha</label>
                <select id="date_format" name="date_format" class="form-select bg-dark text-light">
                    <option value="YYYY-MM-DD">YYYY-MM-DD</option>
                    <option value="DD/MM/YYYY">DD/MM/YYYY</option>
                    <option value="MM-DD-YYYY">MM-DD-YYYY</option>
                </select>
            </div>

            <div class="mb-3 text-start">
                <label for="meet_date" class="form-label">Fecha de la competencia</label>
                <input type="date" id="meet_date" name="meet_date" class="form-control bg-dark text-light" required>
            </div>

            <div class="mb-3 text-start">
                <label for="units" class="form-label">Unidades</label>
                <select id="units" name="units" class="form-select bg-dark text-light">
                    <option value="kg">Kilos</option>
                    <option value="lb">Libras</option>
                </select>
            </div>

            <div class="mb-3 text-start">
                <label for="federation" class="form-label">Federación</label>
                <input type="text" id="federation" name="federation" class="form-control bg-dark text-light" placeholder="Si no tiene, ponga Ninguno">
            </div>

            <div class="mb-4 text-start">
                <label for="contact_email" class="form-label">Email de contacto</label>
                <input type="email" id="contact_email" name="contact_email" class="form-control bg-dark text-light">
            </div>

            <div class="d-flex justify-content-between">
                <a href="dashboard.php" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-red">Crear Meet</button>
            </div>
        </form>
    </div>
</div>

<!-- FOOTER -->
<footer>
    © 2025 USLCast
</footer>

</body>
</html>

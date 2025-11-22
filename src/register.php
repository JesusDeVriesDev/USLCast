<?php
// --- conexión a PostgreSQL ---
require_once 'database.php';
try { $pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch(Exception $e){ die("DB error: ".$e->getMessage()); }

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $locale = $_POST['locale'];

    // Verificar si el correo ya existe
    $check = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $check->execute(['email' => $email]);
    if ($check->fetch()) {
        $error = "El correo ya está registrado.";
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, locale, role)
                               VALUES (:name, :email, :password_hash, :locale, 2)");

        if ($stmt->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => $password_hash,
            'locale' => $locale
        ])) {
            header("Location: login.php?registered=1");
            exit;
        } else {
            $error = "Error al registrar el usuario.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro | USLCast</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Estilos externos -->
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
        <li class="nav-item"><a class="nav-link" href="#">Demo</a></li>
        <li class="nav-item"><a class="nav-link" href="#">Docs</a></li>
        <li class="nav-item"><a class="nav-link" href="create_meet.php">Create Meet</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- FORMULARIO DE REGISTRO -->
<div class="register-container">
    <div class="register-card text-center">
        <img src="img/logo.png" alt="USLCast Logo" class="img-fluid mb-3" style="max-width:150px;">
        <h3 class="mb-4 text-danger fw-bold">Crear cuenta</h3>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3 text-start">
                <label for="name" class="form-label">Nombre completo</label>
                <input type="text" class="form-control bg-dark text-light" id="name" name="name" required>
            </div>
            <div class="mb-3 text-start">
                <label for="email" class="form-label">Correo electrónico</label>
                <input type="email" class="form-control bg-dark text-light" id="email" name="email" required>
            </div>
            <div class="mb-3 text-start">
                <label for="password" class="form-label">Contraseña</label>
                <input type="password" class="form-control bg-dark text-light" id="password" name="password" required>
            </div>
            <div class="mb-3 text-start">
                <label for="locale" class="form-label">Idioma (locale)</label>
                <select id="locale" name="locale" class="form-select bg-dark text-light">
                    <option value="es">Español</option>
                    <option value="en">Inglés</option>
                </select>
            </div>
            <button type="submit" class="btn btn-red w-100 mb-3">Registrar</button>
        </form>

        <p>¿Ya tienes cuenta? <a href="login.php" class="text-danger fw-bold">Inicia sesión aquí</a></p>
    </div>
</div>

<!-- FOOTER -->
<footer>
    © 2025 USLCast
</footer>

</body>
</html>

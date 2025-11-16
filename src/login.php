<?php
// --- conexión simple a PostgreSQL ---
$host = "localhost";
$dbname = "uslcast";
$user = "postgres";
$pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        session_start();
        $_SESSION['user_id'] = $user['id'];
        header("Location: ../src/dashboard.php");
        exit;
    } else {
        $error = "Correo o contraseña incorrectos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | USLCast</title>

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
        <li class="nav-item"><a class="nav-link" href="">Demo</a></li>
        <li class="nav-item"><a class="nav-link" href="#">Docs</a></li>
        <li class="nav-item"><a class="nav-link" href="create_meet.php">Create Meet</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- FORMULARIO LOGIN -->
<div class="login-container">
    <div class="login-card text-center">
        <img src="img/logo.png" alt="USLCast Logo" class="img-fluid mb-3" style="max-width:150px;">
        <h3 class="mb-4 text-danger fw-bold">Iniciar Sesión</h3>

        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success py-2">✅ Registro exitoso. Ahora puedes iniciar sesión.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3 text-start">
                <label for="email" class="form-label">Correo electrónico</label>
                <input type="email" class="form-control bg-dark text-light" id="email" name="email" required>
            </div>
            <div class="mb-3 text-start">
                <label for="password" class="form-label">Contraseña</label>
                <input type="password" class="form-control bg-dark text-light" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-red w-100 mb-3">Entrar</button>
        </form>

        <p>¿No tienes cuenta? <a href="register.php" class="text-danger fw-bold">Regístrate aquí</a></p>
    </div>
</div>

<!-- FOOTER -->
<footer>
    © 2025 USLCast
</footer>

</body>
</html>

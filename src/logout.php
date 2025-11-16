<?php
session_start();

// Si la sesión está activa, la cerramos
if (isset($_SESSION['user_id'])) {
    session_unset();
    session_destroy();
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cerrar Sesión | USLCast</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Estilos globales -->
    <link rel="stylesheet" href="src/assets/styles.css">

    <!-- Redirección automática -->
    <meta http-equiv="refresh" content="3;url=login.php">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">USLCast</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
      aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="index.php">Inicio</a></li>
        <li class="nav-item"><a class="nav-link" href="login.php">Iniciar sesión</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- MENSAJE DE LOGOUT -->
<div class="logout-container text-center d-flex align-items-center justify-content-center" style="min-height: 80vh;">
    <div class="logout-card p-4 rounded shadow">
        <div class="logo mb-3"></div>
        <h3 class="text-danger fw-bold mb-3">Has cerrado sesión</h3>
        <p class="text-light">Serás redirigido al inicio de sesión en unos segundos...</p>
        <a href="login.php" class="btn btn-red mt-3">Ir ahora</a>
    </div>
</div>

<!-- FOOTER -->
<footer>
    © 2025 USLCast
</footer>

</body>
</html>
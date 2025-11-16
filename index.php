<?php
// --- conexión simple a PostgreSQL (por si luego quieres mostrar datos reales) ---
$host = "localhost";
$dbname = "uslcast";
$user = "postgres";
$pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>USLCast | Powerlifting Competitions</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Estilos -->
    <link rel="stylesheet" href="src/assets/styles.css">
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
        <li class="nav-item"><a class="nav-link" href="src/login.php">Login</a></li>
        <li class="nav-item"><a class="nav-link" href="src/register.php">Registro</a></li>
        <li class="nav-item"><a class="nav-link" href="#">Docs</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- HERO -->
<header class="hero text-center text-light py-5">
    <div class="container">
        <h1 class="display-5 fw-bold text-danger">USLCast — Página para competencias de powerlifting</h1>
        <p class="lead mt-3">
            Gestiona inscripciones, programación, paneles en vivo y emisión pública de tus competencias.
        </p>
        <p class="text-secondary mb-0">Disponibilidad robusta — multi-región, CDN y monitorización.</p>
        <p class="text-secondary">Localización — soporte multi-idioma (ES/EN).</p>
    </div>
</header>

<!-- PANEL DE EJEMPLO -->
<section class="py-5">
    <div class="container">
        <h2 class="text-center text-danger mb-4 fw-bold">Panel rápido</h2>

        <div class="row justify-content-center">
            <div class="col-md-8">  
                <div class="card example-card shadow-lg">
                    <div class="card-body">
                        <h4 class="card-title text-danger fw-bold mb-3">Competición</h4>
                        <p><strong>Nombre:</strong> Felix Power 2025</p>
                        <p><strong>Plataformas:</strong> 2</p>
                        <p><strong>Competidores:</strong> 24</p>
                        <p><strong>Fecha:</strong> 22/11/2025</p>
                        <p><strong>Hora de inicio:</strong> 12:00PM</p>
                        <p><strong>Estado:</strong> <span class="badge bg-success">En marcha</span></p>

                        <hr class="border-danger opacity-50">

                        <h5 class="mt-4 mb-3 text-danger">Buscar competidor</h5>
                        <form class="d-flex" role="search">
                            <input class="form-control me-2 bg-dark text-light border-danger" type="search" placeholder="Nombre o número">
                            <button class="btn btn-red" type="submit">Buscar</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="text-center py-5 bg-dark text-light">
    <div class="container">
        <h3 class="fw-bold text-danger mb-3">Empieza tu próxima competencia</h3>
        <p class="mb-4">Crea tu cuenta, organiza tus plataformas y lleva el control completo del evento.</p>
        <a href="src/register.php" class="btn btn-red btn-lg">Comenzar ahora</a>
    </div>
</section>

<!-- FOOTER -->
<footer>
    © 2025 USLCast
</footer>

</body>
</html>
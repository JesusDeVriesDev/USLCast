<?php
session_start();

// --- conexión simple a PostgreSQL ---
$host = "localhost";
$dbname = "uslcast";
$user = "postgres";
$pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);

// --- Verificar sesión ---
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// --- Obtener rol ---
$stmt = $pdo->prepare("SELECT role, name FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] != 2) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Solo organizadores (rol 2)</h1>");
}

// --- Obtener meets del usuario ---
$stmt = $pdo->prepare("SELECT * FROM meets WHERE organizer_id = :id ORDER BY created_at DESC");
$stmt->execute(['id' => $_SESSION['user_id']]);
$meets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | USLCast</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="assets/styles.css">
  <link rel="stylesheet2" href="assets/colors.scss">
</head>

<body>
<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">USLCast</a>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="../index.php">Inicio</a></li>
        <li class="nav-item"><a class="nav-link" href="create_meet.php">Crear Meet</a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php">Salir</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container py-5 text-light">
  <h2 class="text-danger mb-4 fw-bold">Bienvenido, <?= htmlspecialchars($user['name']) ?></h2>
  <h4 class="mb-4">Tus competencias:</h4>

  <?php if (count($meets) === 0): ?>
      <div class="alert alert-secondary text-center">Aún no has creado ninguna competencia.</div>
  <?php else: ?>
      <div class="table-responsive">
          <table class="table table-dark table-bordered align-middle">
              <thead class="table-light text-black">
                  <tr>
                      <th>Nombre</th>
                      <th>Fecha</th>
                      <th>Federación</th>
                      <th>Estado</th>
                      <th>Acciones</th>
                  </tr>
              </thead>
              <tbody>
                  <?php foreach ($meets as $m): ?>
                      <tr>
                          <td><?= htmlspecialchars($m['name']) ?></td>
                          <td><?= htmlspecialchars($m['meet_date']) ?></td>
                          <td><?= htmlspecialchars($m['federation'] ?: 'N/A') ?></td>
                          <td>
                              <?= $m['registration_open'] ? 
                                  '<span class="badge bg-success">Abierto</span>' : 
                                  '<span class="badge bg-secondary">Cerrado</span>' ?>
                          </td>
                          <td>
                              <a href="setup.php?id=<?= $m['id'] ?>" class="btn btn-red btn-sm">Configurar</a>
                          </td>
                      </tr>
                  <?php endforeach; ?>
              </tbody>
          </table>
      </div>
  <?php endif; ?>
</div>

<footer>
    © 2025 USLCast
</footer>
</body>
</html>

<?php
session_start();

// --- conexión simple a PostgreSQL ---
$host = "localhost";
$dbname = "uslcast";
$user = "postgres";
$pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);

// --- Verificar sesión y rol ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Acceso denegado</h1><p style='text-align:center;'>Debes iniciar sesión para acceder a esta página.</p>");
}

$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] != 2) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Solo organizadores (rol 2)</h1>");
}

// --- Verificar parámetro de meet ---
if (!isset($_GET['id'])) {
    die("<h3 style='color:red;text-align:center;'>ID de competencia no especificado.</h3>");
}

$meet_id = (int) $_GET['id'];

// --- Obtener datos del meet ---
$stmt = $pdo->prepare("SELECT * FROM meets WHERE id = :id AND organizer_id = :org");
$stmt->execute(['id' => $meet_id, 'org' => $_SESSION['user_id']]);
$meet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meet) {
    die("<h3 style='color:red;text-align:center;'>Competencia no encontrada o no te pertenece.</h3>");
}

$error = "";
$success = "";

// --- Guardar cambios ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $meet_date = $_POST['meet_date'];
    $contact_email = trim($_POST['contact_email']);
    $max_entries = $_POST['max_entries'];
    $entry_fee = $_POST['entry_fee'];
    $additional_division_fee = $_POST['additional_division_fee'];
    $description = trim($_POST['description']);
    $disclaimer = trim($_POST['disclaimer']);
    $registration_open = isset($_POST['registration_open']) ? 'true' : 'false';
    $show_link = isset($_POST['show_link']) ? 'true' : 'false';

    try {
        $stmt = $pdo->prepare("
            UPDATE meets
            SET name = :name,
                meet_date = :meet_date,
                contact_email = :contact_email,
                max_entries = :max_entries,
                entry_fee = :entry_fee,
                additional_division_fee = :additional_division_fee,
                description = :description,
                disclaimer = :disclaimer,
                registration_open = :registration_open,
                settings = jsonb_set(settings, '{show_link}', to_jsonb(:show_link::text), true)
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $meet_id,
            'name' => $name,
            'meet_date' => $meet_date,
            'contact_email' => $contact_email,
            'max_entries' => $max_entries ?: null,
            'entry_fee' => $entry_fee ?: null,
            'additional_division_fee' => $additional_division_fee ?: null,
            'description' => $description,
            'disclaimer' => $disclaimer,
            'registration_open' => $registration_open,
            'show_link' => $show_link
        ]);

        $success = "✅ Configuración actualizada correctamente.";
    } catch (PDOException $e) {
        $error = "Ocurrió un error al guardar los cambios. Intenta nuevamente.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Configuración del Meet | USLCast</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="assets/styles.css">
</head>

<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">USLCast</a>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="../index.php">Inicio</a></li>
        <li class="nav-item"><a class="nav-link" href="dashboard.php">Panel</a></li>
        <li class="nav-item"><a class="nav-link text-danger fw-bold" href="setup.php?id=<?= $meet_id ?>">Configuración</a></li>
        <li class="nav-item"><a class="nav-link" href="registration.php?meet=<?= $meet_id ?>">Registro</a></li>
        <li class="nav-item"><a class="nav-link" href="divisions.php?id=<?= $meet_id ?>">Divisiones</a></li>
        <li class="nav-item"><a class="nav-link" href="lifters.php?id=<?= $meet_id ?>">Lifters</a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php">Salir</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container py-5 text-light">
  <h2 class="text-danger mb-4 fw-bold">Configuración del Meet — <?= htmlspecialchars($meet['name']) ?></h2>

  <?php if ($success): ?>
      <div class="alert alert-success"><?= $success ?></div>
  <?php elseif ($error): ?>
      <div class="alert alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <form method="POST" class="bg-dark p-4 rounded border border-danger">

      <h5 class="text-danger mb-3">Información del Meet</h5>

      <div class="mb-3">
          <label class="form-label">Nombre del Meet</label>
          <input type="text" name="name" value="<?= htmlspecialchars($meet['name']) ?>" class="form-control bg-dark text-light">
      </div>

      <div class="mb-3">
          <label class="form-label">Fecha</label>
          <input type="date" name="meet_date" value="<?= htmlspecialchars($meet['meet_date']) ?>" class="form-control bg-dark text-light">
      </div>

      <div class="mb-3">
          <label class="form-label">Correo de contacto</label>
          <input type="email" name="contact_email" value="<?= htmlspecialchars($meet['contact_email']) ?>" class="form-control bg-dark text-light">
      </div>

      <div class="mb-3">
          <label class="form-label">Unidades, Federación y Formato</label>
          <input type="text" class="form-control bg-secondary text-light" disabled
                 value="Unidades: <?= $meet['units'] ?> | Federación: <?= $meet['federation'] ?> | Formato: YYYY-MM-DD">
          <small class="text-warning">Estos valores no se pueden cambiar. Si deben modificarse, recrea el meet.</small>
      </div>

      <hr class="border-danger">

      <div class="form-check text-start mb-4">
          <input class="form-check-input" type="checkbox" name="show_link" id="show_link">
          <label for="show_link" class="form-check-label">Mostrar enlace en la página principal</label>
      </div>

      <h5 class="text-danger mb-3">Configuración de Datos</h5>

      <div class="row g-3">
          <div class="col-md-4">
              <label class="form-label">Entradas máximas</label>
              <input type="number" name="max_entries" value="<?= $meet['max_entries'] ?>" class="form-control bg-dark text-light">
          </div>
          <div class="col-md-4">
              <label class="form-label">Costo de inscripción (Full Power)</label>
              <input type="number" step="0.01" name="entry_fee" value="<?= $meet['entry_fee'] ?>" class="form-control bg-dark text-light">
          </div>
          <div class="col-md-4">
              <label class="form-label">Costo por división adicional</label>
              <input type="number" step="0.01" name="additional_division_fee" value="<?= $meet['additional_division_fee'] ?>" class="form-control bg-dark text-light">
          </div>
      </div>

      <div class="mt-3">
          <label class="form-label">Descripción</label>
          <textarea name="description" class="form-control bg-dark text-light" rows="3"><?= htmlspecialchars($meet['description']) ?></textarea>
      </div>

      <div class="mt-3 mb-3">
          <label class="form-label">Aviso o descargo de responsabilidad</label>
          <textarea name="disclaimer" class="form-control bg-dark text-light" rows="3"><?= htmlspecialchars($meet['disclaimer']) ?></textarea>
      </div>

      <div class="form-check text-start mb-4">
          <input class="form-check-input" type="checkbox" name="registration_open" id="registration_open" <?= $meet['registration_open'] ? 'checked' : '' ?>>
          <label for="registration_open" class="form-check-label text-warning">Registro abierto</label>
      </div>

      <button type="submit" class="btn btn-red w-100">Guardar Cambios</button>
  </form>

<hr class="border-danger my-5">

<h4 class="text-danger mb-3">Configuración de Plataformas</h4>
<p class="text-light">Agrega o elimina plataformas, y configura los discos de cada una.</p>

<div id="platforms-section">
    <div id="platforms-list" class="mb-4"></div>

    <div class="d-flex justify-content-between mb-4">
        <button id="add-platform" class="btn btn-red">+ Añadir Plataforma</button>
        <button id="save-platforms" class="btn btn-secondary">Guardar Cambios</button>
    </div>
</div>

<!-- Modal de configuración de discos -->
<div class="modal fade" id="platesModal" tabindex="-1" aria-labelledby="platesModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content bg-dark text-light border-danger">
      <div class="modal-header border-danger">
        <h5 class="modal-title text-danger" id="platesModalLabel">Configuración de Discos</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="plates-table-container"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button id="save-plates" type="button" class="btn btn-red">Guardar Cambios</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const meetId = <?= $meet_id ?>;
    const listDiv = document.getElementById("platforms-list");

document.getElementById("save-platforms").addEventListener("click", () => {
    const meetId = <?= $meet_id ?>;
    const platforms = Array.from(document.querySelectorAll(".platform-name")).map(el => ({
        id: el.dataset.id,
        name: el.value
    }));
    fetch("platforms_api.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ meet_id: meetId, platforms })
    })
    .then(res => res.json())
    .then(r => {
        alert(r.message);
        loadPlatforms();
    });
});

    // === Cargar plataformas ===
    function loadPlatforms() {
        fetch("platforms_api.php?action=list&meet_id=" + meetId)
            .then(res => res.json())
            .then(data => renderPlatforms(data));
    }

    function renderPlatforms(platforms) {
        listDiv.innerHTML = "";
        platforms.forEach(p => {
            const div = document.createElement("div");
            div.classList.add("d-flex", "align-items-center", "mb-2");
            div.innerHTML = `
                <input type="text" class="form-control bg-dark text-light me-2 platform-name" data-id="${p.id}" value="${p.name}">
                <button class="btn btn-sm btn-outline-light me-2 config-platform" data-id="${p.id}">⚙️ Configurar discos</button>
                <button class="btn btn-danger btn-sm delete-platform" data-id="${p.id}">🗑</button>
            `;
            listDiv.appendChild(div);
        });
    }

    document.getElementById("add-platform").addEventListener("click", () => {
        const div = document.createElement("div");
        div.classList.add("d-flex", "align-items-center", "mb-2");
        div.innerHTML = `
            <input type="text" class="form-control bg-dark text-light me-2 platform-name" data-id="new" placeholder="Nombre de la plataforma">
            <button class="btn btn-danger btn-sm delete-platform">🗑</button>
        `;
        listDiv.appendChild(div);
    });

    listDiv.addEventListener("click", e => {
        // Eliminar
        if (e.target.classList.contains("delete-platform")) {
            const div = e.target.closest("div");
            const id = e.target.dataset.id;
            if (!id || id === "new") {
                div.remove();
                return;
            }
            if (!confirm("¿Seguro que deseas eliminar esta plataforma?")) return;
            fetch("platforms_api.php?action=delete&id=" + id)
                .then(res => res.json())
                .then(r => {
                    alert(r.message);
                    if (r.success) loadPlatforms();
                });
        }

        // Configurar discos
        if (e.target.classList.contains("config-platform")) {
            const id = e.target.dataset.id;
            fetch("platforms_api.php?action=get_plates&platform_id=" + id)
                .then(res => res.text())
                .then(html => {
                    document.getElementById("plates-table-container").innerHTML = html;
                    const modal = new bootstrap.Modal(document.getElementById('platesModal'));
                    modal.show();
                    document.getElementById("save-plates").onclick = () => savePlates(id, modal);
                });
        }
    });

    function savePlates(platformId, modal) {
        const rows = document.querySelectorAll("#plates-table tbody tr");
        const data = {};
        rows.forEach(r => {
            const w = r.querySelector(".plate-weight").textContent;
            const pairs = r.querySelector(".plate-pairs").value;
            const color = r.querySelector(".plate-color").value;
            data[w] = { pairs, color };
        });
        fetch("platforms_api.php", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({ platform_id: platformId, plate_colors: data })
        })
        .then(res => res.json())
        .then(r => {
            alert(r.message);
            modal.hide();
        });
    }

    loadPlatforms();
});
</script>

<footer class="mt-5 text-center text-secondary">© 2025 USLCast</footer>

</body>
</html>
    
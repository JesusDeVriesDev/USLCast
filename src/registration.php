<?php
session_start();

// --- Conexión ---
$host = "localhost";
$dbname = "uslcast";
$user = "postgres";
$pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- Verificar sesión ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Acceso denegado</h1><p style='text-align:center;'>Debes iniciar sesión para acceder a esta página.</p>");
}

// --- Verificar rol ---
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] != 2) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Solo organizadores (rol 2)</h1>");
}

// --- Verificar meet_id ---
$meet_id = isset($_GET['meet']) ? (int)$_GET['meet'] : null;

if (!$meet_id) {
    die("<h3 style='color:red;text-align:center;'>ID de competencia no especificado.</h3>");
}

// --- Validar que el meet existe y pertenece al organizador ---
$stmt = $pdo->prepare("SELECT * FROM meets WHERE id = :id AND organizer_id = :org");
$stmt->execute(['id' => $meet_id, 'org' => $_SESSION['user_id']]);
$meet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meet) {
    die("<h3 style='color:red;text-align:center;'>Competencia no encontrada o no te pertenece.</h3>");
}

// --- Estado del registro ---
$not_open = !$meet['registration_open'];

// --- Obtener divisiones --- 
$stmt = $pdo->prepare("SELECT id, name, gender, type FROM divisions WHERE meet_id = :mid ORDER BY id");
$stmt->execute(['mid' => $meet_id]);
$divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Obtener clases de peso para cada división ---
$stmt = $pdo->prepare("
    SELECT wc.id, wc.division_id, wc.name, wc.min_weight, wc.max_weight 
    FROM weight_classes wc
    INNER JOIN divisions d ON wc.division_id = d.id
    WHERE d.meet_id = :mid
    ORDER BY wc.division_id, wc.min_weight
");
$stmt->execute(['mid' => $meet_id]);
$weight_classes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organizar clases de peso por división
$weight_classes = [];
foreach ($weight_classes_raw as $wc) {
    if (!isset($weight_classes[$wc['division_id']])) {
        $weight_classes[$wc['division_id']] = [];
    }
    $weight_classes[$wc['division_id']][] = $wc;
}

// --- Mensajes ---
$errors = [];
$success = "";

// --- Helper: convertir fecha si viene en MM/DD/YYYY -> YYYY-MM-DD ---
function parse_birthdate($s) {
    $s = trim($s);
    if (!$s) return null;
    // si contiene '/', interpretamos MM/DD/YYYY
    if (strpos($s, '/') !== false) {
        $parts = explode('/', $s);
        if (count($parts) === 3) {
            [$m,$d,$y] = $parts;
            if (strlen($y) === 2) $y = '19'.$y; // fallback
            return sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
        }
    }
    // si viene en formato YYYY-MM-DD (input date) ya está OK
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    return null;
}

// --- Manejo de POST (registro) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$not_open) {
    // Campos obligatorios (según spec)
    $full_name = trim($_POST['full_name'] ?? '');
    $membership_number = trim($_POST['membership_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $zip = trim($_POST['zip'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $email_confirm = trim($_POST['email_confirm'] ?? '');
    $birthdate_raw = trim($_POST['birthdate'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $emergency_name = trim($_POST['emergency_name'] ?? '');
    $emergency_phone = trim($_POST['emergency_phone'] ?? '');
    $team = trim($_POST['team'] ?? '');
    $body_weight = trim($_POST['body_weight'] ?? ''); // NUEVO: peso corporal

    // Divisiones (JSON string from JS)
    $divisions_input = isset($_POST['divisions']) ? json_decode($_POST['divisions'], true) : [];

    // Validaciones básicas
    if ($full_name === '') $errors[] = "El nombre completo es obligatorio.";
    if ($phone === '') $errors[] = "El número de teléfono es obligatorio.";
    if ($street === '') $errors[] = "La dirección (calle) es obligatoria.";
    if ($city === '') $errors[] = "La ciudad es obligatoria.";
    if ($state === '') $errors[] = "El estado/provincia es obligatorio.";
    if ($zip === '') $errors[] = "El código postal es obligatorio.";
    if ($email === '' || $email_confirm === '') $errors[] = "El correo y su confirmación son obligatorios.";
    if ($email !== $email_confirm) $errors[] = "El correo y la confirmación no coinciden.";
    if ($birthdate_raw === '') $errors[] = "La fecha de nacimiento es obligatoria.";
    $birthdate = parse_birthdate($birthdate_raw);
    if (!$birthdate) $errors[] = "La fecha de nacimiento debe estar en formato MM/DD/YYYY o YYYY-MM-DD.";
    if (!in_array($gender, ['M','F'])) $errors[] = "El género es obligatorio (M o F).";
    if ($emergency_name === '' || $emergency_phone === '') $errors[] = "Contacto de emergencia (nombre y teléfono) son obligatorios.";
    if ($body_weight === '') $errors[] = "El peso corporal es obligatorio.";

    // Divisiones: al menos una
    if (!is_array($divisions_input) || count($divisions_input) === 0) {
        $errors[] = "Debes añadir al menos una división.";
    } else {
        // validar que las division_id existan para este meet y obtener sus tipos (Raw/Equipped)
        $division_ids = array_column($divisions_input, 'division_id');
        $placeholders = implode(',', array_fill(0, count($division_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, type FROM divisions WHERE meet_id = ? AND id IN ($placeholders)");
        $params = array_merge([$meet_id], $division_ids);
        $stmt->execute($params);
        $found_divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($found_divisions) !== count($division_ids)) {
            $errors[] = "Una o más divisiones seleccionadas no existen para esta competencia.";
        }
        
        // Crear mapa de division_id => type para luego asignar raw_or_equipped
        $division_types = [];
        foreach ($found_divisions as $div) {
            $division_types[$div['id']] = $div['type'];
        }
        
        foreach ($divisions_input as $i => $d) {
            if (empty($d['declared_weight_class'])) {
                $errors[] = "La división #".($i+1)." debe indicar la categoría de peso declarada.";
            }
        }
    }

    // Comprobar max_entries (si está definido)
    if ($meet['max_entries']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM competitors WHERE meet_id = :mid");
        $stmt->execute(['mid' => $meet_id]);
        $count = (int)$stmt->fetchColumn();
        if ($count >= (int)$meet['max_entries']) {
            $errors[] = "La competencia ha alcanzado el máximo de inscripciones.";
        }
    }

    // Si no hay errores, insertamos en transacción
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $address = [
                'street' => $street,
                'city' => $city,
                'state' => $state,
                'zip' => $zip
            ];
            $emergency = [
                'name' => $emergency_name,
                'phone' => $emergency_phone
            ];

            $stmt = $pdo->prepare("INSERT INTO competitors
                (meet_id, name, email, dob, gender, team, membership_number, phone, address, emergency_contact, body_weight, created_at)
                VALUES (:meet_id, :name, :email, :dob, :gender, :team, :membership_number, :phone, :address::jsonb, :emergency::jsonb, :body_weight, now())
                RETURNING id");

            $stmt->execute([
                'meet_id' => $meet_id,
                'name' => $full_name,
                'email' => $email,
                'dob' => $birthdate,
                'gender' => $gender,
                'team' => $team ?: null,
                'membership_number' => $membership_number ?: null,
                'phone' => $phone,
                'address' => json_encode($address),
                'emergency' => json_encode($emergency),
                'body_weight' => $body_weight ?: null
            ]);
            $competitor_id = $stmt->fetchColumn();

            // Insertar cada división en competitor_divisions, usando el tipo de la división
            $stmtCd = $pdo->prepare("INSERT INTO competitor_divisions (competitor_id, division_id, raw_or_equipped, declared_weight_class) VALUES (:cid, :did, :roe, :dwc)");
            foreach ($divisions_input as $d) {
                $raw_or_equipped = $division_types[$d['division_id']]; // Obtener tipo de la división
                $stmtCd->execute([
                    'cid' => $competitor_id,
                    'did' => $d['division_id'],
                    'roe' => $raw_or_equipped,
                    'dwc' => $d['declared_weight_class']
                ]);
            }

            $pdo->commit();

            // Opcional: calcular monto a pagar (entry_fee + extra divisions * additional_division_fee)
            $num_divs = count($divisions_input);
            $base = (float)($meet['entry_fee'] ?? 0);
            $extra = max(0, $num_divs - 1) * (float)($meet['additional_division_fee'] ?? 0);
            $total_cost = $base + $extra;

            $success = "Registro completado. ID Competidor: $competitor_id. Total a pagar: $total_cost";
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Ocurrió un error guardando la inscripción: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Registro - <?= htmlspecialchars($meet['name']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="assets/styles.css">
  <style>
    .form-section { background: #0b0b0b; border: 1px solid #e60000; padding: 1rem; border-radius: .5rem; }
    .btn-usl { background: #e60000; color: white; border: none; }
  </style>
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
        <li class="nav-item"><a class="nav-link" href="setup.php?id=<?= $meet_id ?>">Configuración</a></li>
        <li class="nav-item"><a class="nav-link text-danger fw-bold" href="registration.php?meet=<?= $meet_id ?>">Registro</a></li>
        <li class="nav-item"><a class="nav-link" href="divisions.php?id=<?= $meet_id ?>">Divisiones</a></li>
        <li class="nav-item"><a class="nav-link" href="lifters.php?id=<?= $meet_id ?>">Lifters</a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php">Salir</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container py-5 text-light">
  <h2 class="text-danger mb-4 fw-bold">Registro en línea — <?= htmlspecialchars($meet['name']) ?></h2>

  <?php if ($not_open): ?>
    <div class="alert alert-warning">Las inscripciones para esta competencia no están abiertas.</div>
    <p><a href="../index.php" class="btn btn-secondary">Volver</a></p>
    <?php exit; ?>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $err) echo "<li>".htmlspecialchars($err)."</li>"; ?>
      </ul>
    </div>
  <?php elseif ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <form id="registrationForm" method="POST" novalidate>
    <div class="form-section mb-4">
      <div class="mb-3">
        <label class="form-label">Nombre completo (tal como aparece en la tarjeta de membresía) *</label>
        <input name="full_name" type="text" class="form-control bg-dark text-light" required value="<?= isset($full_name)?htmlspecialchars($full_name):'' ?>">
      </div>

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Número de membresía</label>
          <input name="membership_number" type="text" class="form-control bg-dark text-light" value="<?= isset($membership_number)?htmlspecialchars($membership_number):'' ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Teléfono *</label>
          <input name="phone" type="text" class="form-control bg-dark text-light" required value="<?= isset($phone)?htmlspecialchars($phone):'' ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Equipo (opcional)</label>
          <input name="team" type="text" class="form-control bg-dark text-light" value="<?= isset($team)?htmlspecialchars($team):'' ?>">
        </div>
      </div>

      <hr class="border-danger my-3">

      <div class="mb-3">
        <label class="form-label">Dirección (calle) *</label>
        <input name="street" type="text" class="form-control bg-dark text-light" required value="<?= isset($street)?htmlspecialchars($street):'' ?>">
      </div>

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Ciudad *</label>
          <input name="city" type="text" class="form-control bg-dark text-light" required value="<?= isset($city)?htmlspecialchars($city):'' ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Estado/Provincia *</label>
          <input name="state" type="text" class="form-control bg-dark text-light" required value="<?= isset($state)?htmlspecialchars($state):'' ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Código postal *</label>
          <input name="zip" type="text" class="form-control bg-dark text-light" required value="<?= isset($zip)?htmlspecialchars($zip):'' ?>">
        </div>
      </div>

      <hr class="border-danger my-3">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Correo electrónico *</label>
          <input name="email" type="email" class="form-control bg-dark text-light" required value="<?= isset($email)?htmlspecialchars($email):'' ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Confirmar correo electrónico *</label>
          <input name="email_confirm" type="email" class="form-control bg-dark text-light" required value="<?= isset($email_confirm)?htmlspecialchars($email_confirm):'' ?>">
        </div>
      </div>

      <div class="row g-3 mt-3">
        <div class="col-md-4">
          <label class="form-label">Fecha de nacimiento * (MM/DD/YYYY)</label>
          <input name="birthdate" type="text" class="form-control bg-dark text-light" placeholder="MM/DD/YYYY" value="<?= isset($birthdate_raw)?htmlspecialchars($birthdate_raw):'' ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Género *</label>
          <select name="gender" class="form-select bg-dark text-light" required>
            <option value="">-- Seleccionar --</option>
            <option value="M" <?= (isset($gender) && $gender==='M') ? 'selected':'' ?>>Masculino</option>
            <option value="F" <?= (isset($gender) && $gender==='F') ? 'selected':'' ?>>Femenino</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Peso corporal (<?= htmlspecialchars($meet['units']) ?>) *</label>
          <input name="body_weight" type="number" step="0.01" class="form-control bg-dark text-light" placeholder="Ej: 83" required value="<?= isset($body_weight)?htmlspecialchars($body_weight):'' ?>">
        </div>
      </div>

      <hr class="border-danger my-3">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Nombre contacto de emergencia *</label>
          <input name="emergency_name" type="text" class="form-control bg-dark text-light" required value="<?= isset($emergency_name)?htmlspecialchars($emergency_name):'' ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Teléfono contacto de emergencia *</label>
          <input name="emergency_phone" type="text" class="form-control bg-dark text-light" required value="<?= isset($emergency_phone)?htmlspecialchars($emergency_phone):'' ?>">
        </div>
      </div>
    </div>

    <!-- DIVISIONES -->
    <div class="form-section mb-4">
      <h5 class="text-danger">Divisiones</h5>
      <p class="text-light small">Agrega una o más divisiones para competir. Selecciona la división y la categoría de peso.</p>

      <div id="divisions-list"></div>

      <div class="d-flex gap-2">
        <button id="add-division" type="button" class="btn btn-usl">+ Añadir División</button>
        <button id="remove-last" type="button" class="btn btn-secondary">Eliminar última</button>
      </div>
    </div>

    <input type="hidden" name="divisions" id="divisions-input" value="[]">

    <div class="mb-3">
      <button type="submit" class="btn btn-usl btn-lg">Enviar Registro</button>
    </div>
  </form>
</div>

<script>
// Divisiones disponibles desde PHP (para poblar selects)
const DIVISIONS = <?= json_encode($divisions) ?>;
const WEIGHT_CLASSES = <?= json_encode($weight_classes) ?>;

let divisionsState = []; // array de {division_id, declared_weight_class}

function renderDivisions() {
    const container = document.getElementById('divisions-list');
    container.innerHTML = '';
    divisionsState.forEach((d, idx) => {
        const opts = DIVISIONS.map(x => `<option value="${x.id}" ${x.id==d.division_id?'selected':''}>${x.name} (${x.gender}, ${x.type})</option>`).join('');
        
        // Obtener clases de peso para la división seleccionada
        const weightClassOpts = (WEIGHT_CLASSES[d.division_id] || []).map(wc => {
            const label = wc.max_weight ? `${wc.name} (${wc.min_weight} - ${wc.max_weight} kg)` : `${wc.name} (+${wc.min_weight} kg)`;
            return `<option value="${wc.name}" ${wc.name==d.declared_weight_class?'selected':''}>${label}</option>`;
        }).join('');
        
        const html = `
        <div class="card bg-dark text-light p-3 mb-3">
          <div class="row g-2 align-items-end">
            <div class="col-md-6">
              <label class="form-label">División *</label>
              <select class="form-select bg-dark text-light division-select" data-idx="${idx}">
                <option value="">-- Seleccionar --</option>
                ${opts}
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Categoría de peso *</label>
              <select class="form-select bg-dark text-light weight-class-select" data-idx="${idx}">
                <option value="">-- Seleccionar clase de peso --</option>
                ${weightClassOpts}
              </select>
            </div>
            <div class="col-md-1 text-end">
              <button class="btn btn-danger btn-sm remove-division" data-idx="${idx}">🗑</button>
            </div>
          </div>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
    });
    
    // attach events
    document.querySelectorAll('.division-select').forEach((sel) => {
        sel.addEventListener('change', e => {
            const i = parseInt(e.target.dataset.idx, 10);
            divisionsState[i].division_id = e.target.value;
            divisionsState[i].declared_weight_class = ''; // Reset weight class
            saveState();
            renderDivisions(); // Re-render para actualizar las clases de peso
        });
    });
    
    document.querySelectorAll('.weight-class-select').forEach((sel) => {
        sel.addEventListener('change', e => {
            const i = parseInt(e.target.dataset.idx, 10);
            divisionsState[i].declared_weight_class = e.target.value;
            saveState();
        });
    });
    
    document.querySelectorAll('.remove-division').forEach(btn => {
        btn.addEventListener('click', e => {
            const idx = parseInt(e.target.dataset.idx, 10);
            divisionsState.splice(idx, 1);
            renderDivisions();
        });
    });

    document.getElementById('divisions-input').value = JSON.stringify(divisionsState);
}

function saveState() {
    document.getElementById('divisions-input').value = JSON.stringify(divisionsState);
}

document.getElementById('add-division').addEventListener('click', () => {
    divisionsState.push({ division_id: (DIVISIONS[0]?DIVISIONS[0].id:''), declared_weight_class: '' });
    renderDivisions();
});

document.getElementById('remove-last').addEventListener('click', () => {
    divisionsState.pop();
    renderDivisions();
});

// iniciar con 1 división por defecto (vacía)
if (DIVISIONS.length > 0) {
    divisionsState.push({ division_id: DIVISIONS[0].id, declared_weight_class: '' });
    renderDivisions();
} else {
    document.getElementById('divisions-list').innerHTML = '<div class="alert alert-warning">No hay divisiones definidas para esta competencia. Contacta al organizador.</div>';
    document.getElementById('add-division').disabled = true;
}

// Antes de enviar, recopilar divisiones en el hidden
document.getElementById('registrationForm').addEventListener('submit', (e) => {
    document.getElementById('divisions-input').value = JSON.stringify(divisionsState);
});
</script>

<footer class="mt-5 text-center text-secondary">
    © 2025 USLCast
</footer>
</body>
</html>
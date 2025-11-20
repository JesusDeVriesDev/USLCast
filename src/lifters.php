<?php
session_start();

// --- Obtener usuario autenticado ---
$sessionUserId = $_SESSION['user_id'] ?? $_SESSION['session_user_id'] ?? null;

if (!$sessionUserId) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Acceso denegado</h1><p style='text-align:center;'>Debes iniciar sesión para acceder a esta página.</p>");
}

// --- Conexión ---
$host = "localhost";
$dbname = "uslcast";
$user = "postgres";
$pass = "unicesmag";

$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- Verificar rol ---
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute(['id' => $sessionUserId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u || $u['role'] != 2) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Solo organizadores (rol 2)</h1>");
}

// --- meet_id ---
$meet_id = isset($_GET['meet']) ? (int)$_GET['meet'] :
           (isset($_GET['id']) ? (int)$_GET['id'] : null);

if (!$meet_id) {
    die("<h3 style='color:red;text-align:center;'>ID de competencia no especificado.</h3>");
}

// --- Verificar que el meet pertenece al organizador ---
$stmt = $pdo->prepare("SELECT * FROM meets WHERE id = :id AND organizer_id = :org");
$stmt->execute(['id' => $meet_id, 'org' => $sessionUserId]);
$meet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meet) {
    die("<h3 style='color:red;text-align:center;'>Competencia no encontrada o no te pertenece.</h3>");
}

// --- Cargar plataformas ---
$stmt = $pdo->prepare("SELECT * FROM platforms WHERE meet_id = :mid ORDER BY id");
$stmt->execute(['mid' => $meet_id]);
$platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Cargar divisiones con información completa ---
$stmt = $pdo->prepare("SELECT id, name, gender, type FROM divisions WHERE meet_id = :mid ORDER BY name");
$stmt->execute(['mid' => $meet_id]);
$all_divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Cargar clases de peso por división ---
$stmt = $pdo->prepare("
    SELECT 
        wc.id,
        wc.division_id,
        wc.name,
        wc.min_weight,
        wc.max_weight
    FROM weight_classes wc
    JOIN divisions d ON wc.division_id = d.id
    WHERE d.meet_id = :mid
    ORDER BY wc.division_id, wc.min_weight
");
$stmt->execute(['mid' => $meet_id]);
$all_weight_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organizar clases de peso por división
$weight_classes_by_division = [];
foreach($all_weight_classes as $wc) {
    $weight_classes_by_division[$wc['division_id']][] = $wc;
}

// --- Cargar lifters con divisiones asociadas ---
$stmt = $pdo->prepare("
    SELECT 
        c.*,
        STRING_AGG(DISTINCT d.name, ', ') AS division_names,
        STRING_AGG(DISTINCT cd.declared_weight_class, ', ') AS weight_classes
    FROM competitors c
    LEFT JOIN competitor_divisions cd ON c.id = cd.competitor_id
    LEFT JOIN divisions d ON cd.division_id = d.id
    WHERE c.meet_id = :mid
    GROUP BY c.id
    ORDER BY c.id
");
$stmt->execute(['mid' => $meet_id]);
$lifters = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Levantadores — <?=htmlspecialchars($meet['name'])?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/styles.css">
<style>
body{background:#000;color:#fff}
.lifters-table{font-size:0.85rem}
.lifters-table thead{background:#1b1b1b;position:sticky;top:0;z-index:10}
.lifters-table th{cursor:pointer;user-select:none;padding:.5rem .25rem;vertical-align:middle}
.lifters-table th:hover{background:#2b2b2b}
.lifters-table td{padding:.25rem;vertical-align:middle}
.lifters-table input, .lifters-table select{
  background:#121212;color:#fff;border:1px solid #333;padding:.25rem;
  font-size:0.82rem;width:100%
}
.lifters-table input[type="number"]{width:60px}
.lifters-table input[type="date"]{width:120px}
.btn-usl{background:#e60000;color:#fff;border:none}
.btn-usl:hover{background:#b80000}
.sort-arrow{font-size:0.7rem;margin-left:4px}
.btn-delete-lifter{padding:0.2rem 0.5rem;font-size:0.8rem}
.division-badge{display:inline-block;background:#333;padding:.3rem .6rem;margin:.2rem;border-radius:.25rem;font-size:0.8rem}
</style>
</head>

<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">USLCast</a>
    <div class="collapse navbar-collapse">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="../index.php">Inicio</a></li>
        <li class="nav-item"><a class="nav-link" href="dashboard.php">Panel</a></li>
        <li class="nav-item"><a class="nav-link" href="setup.php?id=<?= $meet_id ?>">Configuración</a></li>
        <li class="nav-item"><a class="nav-link" href="registration.php?meet=<?= $meet_id ?>">Registro</a></li>
        <li class="nav-item"><a class="nav-link" href="divisions.php?id=<?= $meet_id ?>">Divisiones</a></li>
        <li class="nav-item"><a class="nav-link text-danger fw-bold" href="lifters.php?id=<?= $meet_id ?>">Lifters</a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php">Salir</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container-fluid py-4 text-light">
  <h2 class="text-danger mb-3 fw-bold">Lifters — <?=htmlspecialchars($meet['name'])?></h2>

  <div class="mb-3 d-flex gap-2">
    <button class="btn btn-usl" id="btn-export">Exportar Levantadores</button>
    <button class="btn btn-secondary" id="btn-generate-lots">Generar Números de Lote</button>
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Imprimir</button>
  </div>

  <div class="table-responsive">
    <table class="table table-dark table-bordered table-hover lifters-table">
      <thead class="table-light text-black">
        <tr>
          <th data-sort="name">Nombre<span class="sort-arrow">▼</span></th>
          <th data-sort="team">Equipo<span class="sort-arrow"></span></th>
          <th data-sort="lot">Lote<span class="sort-arrow"></span></th>
          <th data-sort="platform_id">Plataforma<span class="sort-arrow"></span></th>
          <th>Sesión</th>
          <th>Vuelo</th>
          <th data-sort="dob">Fecha Nac.<span class="sort-arrow"></span></th>
          <th data-sort="gender">Género<span class="sort-arrow"></span></th>
          <th>Peso Corporal</th>
          <th>Altura Squat</th>
          <th>Altura Bench</th>
          <th>Divisiones</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody id="lifters-tbody">
        <?php foreach($lifters as $l): 
          $rack = json_decode($l['rack_height'] ?? '{}', true);
        ?>
        <tr data-id="<?=$l['id']?>">
          <td class="field-name"><?=htmlspecialchars($l['name'])?></td>
          <td><input type="text" class="field-team" value="<?=htmlspecialchars($l['team']??'')?>"></td>
          <td><input type="number" class="field-lot" value="<?=$l['lot_number']??''?>"></td>
          <td>
            <select class="field-platform">
              <option value="">--</option>
              <?php foreach($platforms as $p): ?>
                <option value="<?=$p['id']?>" <?= $l['platform_id']==$p['id']?'selected':''?>><?=htmlspecialchars($p['name'])?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" class="field-session" value="<?=$l['session']??''?>" placeholder="1"></td>
          <td><input type="text" class="field-flight" value="<?=htmlspecialchars($l['flight']??'')?>" placeholder="A" maxlength="1"></td>
          <td><input type="date" class="field-dob" value="<?=$l['dob']?>"></td>
          <td>
            <select class="field-gender">
              <option value="M" <?= $l['gender']=='M'?'selected':''?>>M</option>
              <option value="F" <?= $l['gender']=='F'?'selected':''?>>F</option>
            </select>
          </td>
          <td><input type="number" step="0.01" class="field-bodyweight" value="<?=$l['body_weight']??''?>" placeholder="kg"></td>
          <td><input type="text" class="field-squat-height" value="<?=$rack['squat']??''?>" placeholder="ej: 5"></td>
          <td><input type="text" class="field-bench-height" value="<?=$rack['bench']??''?>" placeholder="ej: 3"></td>
          <td>
            <button class="btn btn-sm btn-info btn-manage-divisions" data-id="<?=$l['id']?>">⚙️ Gestionar</button>
          </td>
          <td>
            <button class="btn btn-sm btn-success btn-save">💾</button>
            <button class="btn btn-sm btn-danger btn-delete-lifter">🗑</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL GESTIONAR DIVISIONES -->
<div class="modal fade" id="divisionsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content bg-dark text-light border-danger">
      <div class="modal-header border-danger">
        <h5 class="text-danger">Gestionar Divisiones y Clases de Peso</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <h6 class="text-light">Divisiones Asignadas</h6>
          <div id="assigned-divisions-list" class="mb-2"></div>
          
          <h6 class="text-light mt-3">Agregar División</h6>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small">División</label>
              <select id="select-division" class="form-select bg-dark text-light">
                <option value="">-- Seleccionar División --</option>
                <?php foreach($all_divisions as $d): ?>
                  <option value="<?=$d['id']?>" data-type="<?=$d['type']?>">
                    <?=htmlspecialchars($d['name'])?> - <?=$d['gender']?> - <?=$d['type']?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Clase de Peso</label>
              <select id="select-weight-class" class="form-select bg-dark text-light" disabled>
                <option value="">-- Primero selecciona una división --</option>
              </select>
            </div>
          </div>
          <button class="btn btn-sm btn-usl mt-2" id="btn-add-division">+ Agregar</button>
        </div>
      </div>
      <div class="modal-footer border-danger">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>
const meetId = <?= json_encode($meet_id) ?>;
const allDivisions = <?= json_encode($all_divisions) ?>;
const weightClassesByDivision = <?= json_encode($weight_classes_by_division) ?>;
let sortDirection = {};
let currentLifterId = null;

// ----------------------
// SORTING
// ----------------------
document.querySelectorAll('th[data-sort]').forEach(th=>{
  const field = th.dataset.sort;
  sortDirection[field] = 'asc';
  
  th.addEventListener('click', ()=>{
    sortDirection[field] = sortDirection[field] === 'asc' ? 'desc' : 'asc';
    document.querySelectorAll('.sort-arrow').forEach(a=>a.textContent='');
    th.querySelector('.sort-arrow').textContent = sortDirection[field]==='asc'?'▲':'▼';
    
    const tbody = document.getElementById('lifters-tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    rows.sort((a,b)=>{
      let aVal, bVal;
      
      if(field==='name') {
        aVal = a.querySelector('.field-name').textContent.toLowerCase();
        bVal = b.querySelector('.field-name').textContent.toLowerCase();
      } else if(field==='team') {
        aVal = a.querySelector('.field-team').value.toLowerCase();
        bVal = b.querySelector('.field-team').value.toLowerCase();
      } else if(field==='lot') {
        aVal = parseInt(a.querySelector('.field-lot').value) || 0;
        bVal = parseInt(b.querySelector('.field-lot').value) || 0;
      } else if(field==='platform_id') {
        aVal = a.querySelector('.field-platform').value;
        bVal = b.querySelector('.field-platform').value;
      } else if(field==='dob') {
        aVal = a.querySelector('.field-dob').value;
        bVal = b.querySelector('.field-dob').value;
      } else if(field==='gender') {
        aVal = a.querySelector('.field-gender').value;
        bVal = b.querySelector('.field-gender').value;
      }
      
      if(sortDirection[field]==='asc') return aVal>bVal?1:-1;
      else return aVal<bVal?1:-1;
    });
    
    rows.forEach(row=>tbody.appendChild(row));
  });
});

// ----------------------
// CARGAR CLASES DE PESO AL SELECCIONAR DIVISIÓN
// ----------------------
document.getElementById('select-division').addEventListener('change', function() {
  const divisionId = this.value;
  const weightClassSelect = document.getElementById('select-weight-class');
  
  // Limpiar opciones anteriores
  weightClassSelect.innerHTML = '<option value="">-- Seleccionar Clase de Peso --</option>';
  
  if (!divisionId) {
    weightClassSelect.disabled = true;
    return;
  }
  
  // Cargar clases de peso para esta división
  const weightClasses = weightClassesByDivision[divisionId] || [];
  
  if (weightClasses.length === 0) {
    weightClassSelect.innerHTML = '<option value="">-- Sin clases de peso configuradas --</option>';
    weightClassSelect.disabled = true;
    return;
  }
  
  // Agregar opciones
  weightClasses.forEach(wc => {
    const option = document.createElement('option');
    option.value = wc.name;
    option.textContent = wc.name;
    weightClassSelect.appendChild(option);
  });
  
  weightClassSelect.disabled = false;
});

// ----------------------
// SAVE LIFTER
// ----------------------
document.querySelectorAll('.btn-save').forEach(btn=>{
  btn.addEventListener('click', async (e)=>{
    const row = e.target.closest('tr');
    const id = row.dataset.id;
    
    const rack_height = {
      squat: row.querySelector('.field-squat-height').value,
      bench: row.querySelector('.field-bench-height').value
    };
    
    const payload = {
      action: 'update',
      id: id,
      team: row.querySelector('.field-team').value,
      lot_number: row.querySelector('.field-lot').value || null,
      platform_id: row.querySelector('.field-platform').value || null,
      session: row.querySelector('.field-session').value || null,
      flight: row.querySelector('.field-flight').value.toUpperCase() || null,
      dob: row.querySelector('.field-dob').value,
      gender: row.querySelector('.field-gender').value,
      body_weight: row.querySelector('.field-bodyweight').value || null,
      rack_height: rack_height
    };
    
    const res = await fetch('lifters_api.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    
    const j = await res.json();
    if(!j.ok) alert('Error: '+j.error);
    else {
      e.target.textContent = '✓';
      setTimeout(()=>e.target.textContent='💾',800);
    }
  });
});

// ----------------------
// DELETE LIFTER
// ----------------------
document.querySelectorAll('.btn-delete-lifter').forEach(btn=>{
  btn.addEventListener('click', async (e)=>{
    if(!confirm('¿Eliminar levantador?')) return;
    
    const id = e.target.closest('tr').dataset.id;
    
    const res = await fetch('lifters_api.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'delete', id})
    });
    
    const j = await res.json();
    if(!j.ok) alert('Error: '+j.error);
    else e.target.closest('tr').remove();
  });
});

// ----------------------
// GENERATE LOT NUMBERS
// ----------------------
document.getElementById('btn-generate-lots').addEventListener('click', async ()=>{
  if(!confirm('¿Generar números de lote automáticamente? Esto sobrescribirá los existentes.')) return;
  
  const res = await fetch('lifters_api.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'generate_lots', meet_id:meetId})
  });
  
  const j = await res.json();
  if(!j.ok) alert('Error: '+j.error);
  else location.reload();
});

// ----------------------
// EXPORT
// ----------------------
document.getElementById('btn-export').addEventListener('click', ()=>{
  window.location.href = `lifters_export.php?meet_id=${meetId}`;
});

// ----------------------
// GESTIONAR DIVISIONES
// ----------------------
document.querySelectorAll('.btn-manage-divisions').forEach(btn=>{
  btn.addEventListener('click', async (e)=>{
    currentLifterId = e.target.dataset.id;
    await loadLifterDivisions();
    const modal = new bootstrap.Modal(document.getElementById('divisionsModal'));
    modal.show();
  });
});

async function loadLifterDivisions() {
  const res = await fetch('lifters_api.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'get_divisions', lifter_id:currentLifterId})
  });
  
  const j = await res.json();
  if(!j.ok) { alert('Error: '+j.error); return; }
  
  const container = document.getElementById('assigned-divisions-list');
  container.innerHTML = '';
  
  if(j.data.length === 0) {
    container.innerHTML = '<p class="text-muted small">No hay divisiones asignadas</p>';
    return;
  }
  
  j.data.forEach(d=>{
    const badge = document.createElement('span');
    badge.className = 'division-badge';
    badge.innerHTML = `
      ${d.division_name} - ${d.division_gender} - ${d.raw_or_equipped} (${d.declared_weight_class})
      <button class="btn btn-sm btn-link text-danger p-0 ms-2 remove-division" data-cd-id="${d.id}" style="text-decoration:none;">×</button>
    `;
    container.appendChild(badge);
  });
  
  // Attach remove handlers
  document.querySelectorAll('.remove-division').forEach(btn=>{
    btn.addEventListener('click', async (e)=>{
      const cd_id = e.target.dataset.cdId;
      await removeDivision(cd_id);
    });
  });
}

async function removeDivision(cd_id) {
  const res = await fetch('lifters_api.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'remove_division', competitor_division_id:cd_id})
  });
  
  const j = await res.json();
  if(!j.ok) alert('Error: '+j.error);
  else await loadLifterDivisions();
}

document.getElementById('btn-add-division').addEventListener('click', async ()=>{
  const divisionSelect = document.getElementById('select-division');
  const weightClassSelect = document.getElementById('select-weight-class');
  const division_id = divisionSelect.value;
  const weight_class = weightClassSelect.value;
  
  if(!division_id) { alert('Selecciona una división'); return; }
  if(!weight_class) { alert('Selecciona una clase de peso'); return; }
  
  // Obtener el tipo (Raw/Equipped) de la división seleccionada
  const selectedOption = divisionSelect.options[divisionSelect.selectedIndex];
  const raw_or_eq = selectedOption.dataset.type;
  
  const res = await fetch('lifters_api.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      action:'add_division',
      lifter_id:currentLifterId,
      division_id,
      raw_or_equipped:raw_or_eq,
      declared_weight_class:weight_class
    })
  });
  
  const j = await res.json();
  if(!j.ok) alert('Error: '+j.error);
  else {
    // Reset selects
    divisionSelect.value = '';
    weightClassSelect.innerHTML = '<option value="">-- Primero selecciona una división --</option>';
    weightClassSelect.disabled = true;
    await loadLifterDivisions();
  }
});
</script>

<footer class="mt-5 text-center text-secondary">© 2025 USLCast</footer>

</body>
</html>
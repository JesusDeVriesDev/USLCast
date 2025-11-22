<?php
// divisions.php — Gestión de divisiones
session_start();

// --- Obtener usuario de sesión (compatibilidad con session_user_id) ---
$sessionUserId = $_SESSION['user_id'] ?? $_SESSION['session_user_id'] ?? null;

if (!$sessionUserId) {
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Acceso denegado</h1><p style='text-align:center;'>Debes iniciar sesión para acceder a esta página.</p>");
}

// --- Conexión ---
require_once 'database.php';
try { $pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch(Exception $e){ die("DB error: ".$e->getMessage()); }

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

// --- Cargar divisiones ---
$stmt = $pdo->prepare("SELECT * FROM divisions WHERE meet_id = :mid ORDER BY id");
$stmt->execute(['mid' => $meet_id]);
$divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Métodos de puntuación disponibles ---
$scoreMethods = [
    "Total",
    "Wilks",
    "Age Points",
    "IPF Points",
    "IPF+Age",
    "DOTS",
    "DOTS+Age",
    "Schwartz/Malone",
    "Schwartz/Malone+Age",
    "Glossbrenner",
    "Glossbrenner+Age",
    "Para DOTS",
    "AH",
    "K-Points"
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Divisiones — <?=htmlspecialchars($meet['name'])?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/styles.css">
<style>
body{background:#000;color:#fff}
.table thead {background:#1b1b1b}
.btn-usl{background:#e60000;color:#fff;border:none}
.btn-usl:hover{background:#b80000}
.content-editable {min-width:120px}
.select-inline {background:#121212;color:#fff;border:1px solid #333;padding:.25rem .5rem;border-radius:.25rem}
.weight-class-row {background:#0a0a0a;border-left:3px solid #e60000;padding:.5rem;margin:.25rem 0}
.weight-input {width:80px;display:inline-block}
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
        <li class="nav-item"><a class="nav-link" href="registration.php?meet=<?= $meet_id ?>">Registro</a></li>
        <li class="nav-item"><a class="nav-link text-danger fw-bold" href="divisions.php?id=<?= $meet_id ?>">Divisiones</a></li>
        <li class="nav-item"><a class="nav-link" href="lifters.php?id=<?= $meet_id ?>">Lifters</a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php">Salir</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container py-5 text-light">
  <h2 class="text-danger mb-4 fw-bold">Divisiones — <?=htmlspecialchars($meet['name'])?></h2>

  <div class="mb-3 d-flex gap-2">
    <button class="btn btn-usl" data-bs-toggle="modal" data-bs-target="#generateModal">Generar divisiones</button>
    <button class="btn btn-secondary" id="btn-add-custom">+ Nueva división personalizada</button>
  </div>

  <div class="table-responsive">
    <table class="table table-dark table-bordered align-middle text-light">
      <thead class="table-light text-black">
        <tr>
          <th>Nombre</th>
          <th>Género</th>
          <th>R/E</th>
          <th>Lifts</th>
          <th>Tipo Comp.</th>
          <th>Puntuación</th>
          <th>Clases de Peso</th>
          <th>Código</th>
          <th>Oculto</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody id="divisions-tbody">
        <?php foreach($divisions as $d): ?>
        <tr data-id="<?=$d['id']?>">
          <td contenteditable="true" class="content-editable field-name"><?=htmlspecialchars($d['name'])?></td>
          <td>
            <select class="select-inline field-gender">
              <option value="M" <?= $d['gender']=='M'?'selected':''?>>M</option>
              <option value="F" <?= $d['gender']=='F'?'selected':''?>>F</option>
            </select>
          </td>
          <td>
            <select class="select-inline field-type">
              <option value="Raw" <?= $d['type']=='Raw'?'selected':''?>>Raw</option>
              <option value="Equipped" <?= $d['type']=='Equipped'?'selected':''?>>Equipped</option>
            </select>
          </td>
          <td>
            <?php 
              $lifts = json_decode($d['lifts'] ?? '{"squat":true,"bench":true,"deadlift":true}', true);
            ?>
            <div style="display:flex;gap:5px;flex-direction:column">
              <label style="margin:0"><input type="checkbox" class="lift-squat" <?= ($lifts['squat']??true)?'checked':''?>> Squat</label>
              <label style="margin:0"><input type="checkbox" class="lift-bench" <?= ($lifts['bench']??true)?'checked':''?>> Bench</label>
              <label style="margin:0"><input type="checkbox" class="lift-deadlift" <?= ($lifts['deadlift']??true)?'checked':''?>> Deadlift</label>
            </div>
          </td>
          <td>
            <select class="select-inline field-comp-type">
              <option value="Powerlifting" <?= ($d['competition_type']??'Powerlifting')=='Powerlifting'?'selected':''?>>Powerlifting</option>
              <option value="Push/Pull" <?= ($d['competition_type']??'')=='Push/Pull'?'selected':''?>>Push/Pull</option>
              <option value="Bench" <?= ($d['competition_type']??'')=='Bench'?'selected':''?>>Bench</option>
              <option value="Deadlift" <?= ($d['competition_type']??'')=='Deadlift'?'selected':''?>>Deadlift</option>
            </select>
          </td>
          <td>
            <select class="select-inline field-score">
              <?php foreach($scoreMethods as $s): ?>
                <option <?= $d['scoring_method']==$s?'selected':''?>><?=$s?></option>
              <?php endforeach;?>
            </select>
          </td>
          <td>
            <div class="weight-classes-container">
              <button class="btn btn-sm btn-outline-light manage-weights" data-id="<?=$d['id']?>">Clases de peso</button>
            </div>
          </td>
          <td><input class="form-control bg-dark text-light field-code" value="<?= htmlspecialchars($d['division_code']) ?>" style="width:100px"></td>
          <td class="text-center">
            <input type="checkbox" class="field-hidden" <?= $d['hidden_on_board']?'checked':''?>>
          </td>
          <td>
            <button class="btn btn-sm btn-success btn-save">Guardar</button>
            <button class="btn btn-sm btn-danger btn-delete">Borrar</button>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL WEIGHT CLASSES -->
<div class="modal fade" id="weightClassesModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content bg-dark text-light border-danger">
      <div class="modal-header border-danger">
        <h5 class="text-danger">Clases de Peso</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="weight-classes-list"></div>
        <button class="btn btn-sm btn-usl mt-2" id="add-weight-class">+ Agregar clase de peso</button>
      </div>
      <div class="modal-footer border-danger">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-usl" id="save-weight-classes">Guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- GENERATE MODAL -->
<div class="modal fade" id="generateModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content bg-dark text-light border-danger">
      <div class="modal-header border-danger">
        <h5 class="text-danger">Generar divisiones</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-3">
            <label class="form-label text-danger">Tipo</label>
            <div>
              <div><input type="radio" name="g_tipo" value="Powerlifting" checked> Powerlifting</div>
              <div><input type="radio" name="g_tipo" value="Push/Pull"> Push/Pull</div>
              <div><input type="radio" name="g_tipo" value="Bench"> Bench Only</div>
              <div><input type="radio" name="g_tipo" value="Deadlift"> Deadlift Only</div>
            </div>
            <hr>
            <label class="form-label text-danger">Género</label>
            <div><input type="checkbox" class="g_gen" value="M" checked> Masculino</div>
            <div><input type="checkbox" class="g_gen" value="F" checked> Femenino</div>
            <hr>
            <label class="form-label text-danger">Raw / Equipped</label>
            <div><input type="checkbox" class="g_ref" value="Raw" checked> Raw</div>
            <div><input type="checkbox" class="g_ref" value="Equipped" checked> Equipped</div>
          </div>

          <div class="col-md-7">
            <label class="form-label text-danger">Grupos de edad</label>
            <div style="height:320px;overflow:auto;border:1px solid #222;padding:.5rem">
<?php
$ages = [
"Youth (8-9)","Youth (10-11)","Youth (12-13)","Teen I (14-15)","Teen II (16-17)","Teen III (18-19)",
"Junior (20-23)","Open","Master I (40-44)","Master II (45-49)","Master III (50-54)","Master IV (55-59)",
"Master V (60-64)","Master VI (65-69)","Master VII (70-74)","Master VIII (75-79)","Master IX (80-84)",
"Master X (85-89)","Master XI (90+)","Collegiate","High School","Guest"
];
foreach($ages as $a)
  echo '<div><input type="checkbox" class="g_age" value="'.htmlspecialchars($a).'"> '.htmlspecialchars($a).'</div>';
?>
            </div>
          </div>

          <div class="col-md-2">
            <label class="form-label text-danger">Puntuación</label>
            <select id="g_score" class="form-select bg-dark text-light">
              <?php foreach($scoreMethods as $s) echo "<option>$s</option>";?>
            </select>
            <div class="mt-3">
              <button class="btn btn-usl w-100" id="btn-generate">Generar</button>
            </div>
          </div>
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
let currentDivisionId = null;
let weightClassesData = {};

// ----------------------
// GUARDAR UNA DIVISIÓN
// ----------------------
document.querySelectorAll('.btn-save').forEach(btn=>{
  btn.addEventListener('click', async (e)=>{
    const row = e.target.closest('tr');
    const id = row.dataset.id;

    const lifts = {
      squat: row.querySelector('.lift-squat').checked,
      bench: row.querySelector('.lift-bench').checked,
      deadlift: row.querySelector('.lift-deadlift').checked
    };

    const payload = {
      action: 'update',
      id: id,
      meet_id: meetId,
      name: row.querySelector('.field-name').innerText.trim(),
      gender: row.querySelector('.field-gender').value,
      type: row.querySelector('.field-type').value,
      scoring_method: row.querySelector('.field-score').value,
      division_code: row.querySelector('.field-code').value,
      hidden_on_board: row.querySelector('.field-hidden').checked ? 1 : 0,
      lifts: lifts,
      competition_type: row.querySelector('.field-comp-type').value
    };

    const res = await fetch('divisions_api_inline.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });

    const j = await res.json();

    if (!j.ok) alert('Error: '+j.error);
    else {
      e.target.textContent = '✓';
      setTimeout(()=>e.target.textContent='Guardar',800);
    }
  });
});

// Auto-ajustar checkboxes cuando cambia el tipo de competencia
document.querySelectorAll('.field-comp-type').forEach(select=>{
  select.addEventListener('change', (e)=>{
    const row = e.target.closest('tr');
    const tipo = e.target.value;
    const squat = row.querySelector('.lift-squat');
    const bench = row.querySelector('.lift-bench');
    const deadlift = row.querySelector('.lift-deadlift');
    
    if (tipo === 'Powerlifting') {
      squat.checked = true;
      bench.checked = true;
      deadlift.checked = true;
    } else if (tipo === 'Push/Pull') {
      squat.checked = false;
      bench.checked = true;
      deadlift.checked = true;
    } else if (tipo === 'Bench') {
      squat.checked = false;
      bench.checked = true;
      deadlift.checked = false;
    } else if (tipo === 'Deadlift') {
      squat.checked = false;
      bench.checked = false;
      deadlift.checked = true;
    }
  });
});

// ----------------------
// BORRAR DIVISIÓN
// ----------------------
document.querySelectorAll('.btn-delete').forEach(btn=>{
  btn.addEventListener('click', async (e)=>{
    if (!confirm('Eliminar división?')) return;

    const id = e.target.closest('tr').dataset.id;

    const res = await fetch('divisions_api_inline.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'delete', id, meet_id:meetId})
    });

    const j = await res.json();
    if (!j.ok) alert('Error: '+j.error);
    else e.target.closest('tr').remove();
  });
});

// ----------------------
// NUEVA DIVISIÓN PERSONALIZADA
// ----------------------
document.getElementById('btn-add-custom').addEventListener('click', async ()=>{
  const name = prompt('Nombre de la división (ej: Open 83kg)');
  if (!name) return;

  const res = await fetch('divisions_api_inline.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      action:'create',
      meet_id:meetId,
      name,
      gender:'M',
      type:'Raw',
      scoring_method:'Total',
      division_code:'',
      hidden_on_board:1,
      competition_type:'Powerlifting',
      lifts:{squat:true,bench:true,deadlift:true}
    })
  });

  const j = await res.json();
  if (!j.ok) alert('Error: '+j.error);
  else location.reload();
});

// ----------------------
// GESTIONAR WEIGHT CLASSES
// ----------------------
document.querySelectorAll('.manage-weights').forEach(btn=>{
  btn.addEventListener('click', async (e)=>{
    currentDivisionId = e.target.dataset.id;
    
    // Cargar weight classes existentes
    const res = await fetch('divisions_api_inline.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_weight_classes', division_id:currentDivisionId})
    });
    
    const j = await res.json();
    weightClassesData = j.ok ? (j.data || []) : [];
    
    renderWeightClasses();
    const modal = new bootstrap.Modal(document.getElementById('weightClassesModal'));
    modal.show();
  });
});

function renderWeightClasses() {
  const container = document.getElementById('weight-classes-list');
  container.innerHTML = '';
  
  if (weightClassesData.length === 0) {
    container.innerHTML = '<p class="text-muted">No hay clases de peso definidas.</p>';
    return;
  }
  
  weightClassesData.forEach((wc, idx) => {
    const div = document.createElement('div');
    div.className = 'weight-class-row d-flex align-items-center gap-2 mb-2';
    div.innerHTML = `
      <input type="text" class="form-control bg-dark text-light wc-name" placeholder="Nombre" value="${wc.name||''}" style="flex:1">
      <input type="number" step="0.01" class="form-control bg-dark text-light wc-min weight-input" placeholder="Min" value="${wc.min_weight||''}">
      <input type="number" step="0.01" class="form-control bg-dark text-light wc-max weight-input" placeholder="Max" value="${wc.max_weight||''}">
      <input type="text" class="form-control bg-dark text-light wc-code" placeholder="Código" value="${wc.division_code||''}" style="width:100px">
      <button class="btn btn-sm btn-danger remove-wc" data-idx="${idx}">🗑</button>
    `;
    container.appendChild(div);
  });
  
  // Event listeners
  document.querySelectorAll('.remove-wc').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      const idx = parseInt(e.target.dataset.idx);
      weightClassesData.splice(idx, 1);
      renderWeightClasses();
    });
  });
  
  document.querySelectorAll('.wc-name').forEach((inp, i)=>{
    inp.addEventListener('input', (e)=>{
      weightClassesData[i].name = e.target.value;
    });
  });
  
  document.querySelectorAll('.wc-min').forEach((inp, i)=>{
    inp.addEventListener('input', (e)=>{
      weightClassesData[i].min_weight = e.target.value;
    });
  });
  
  document.querySelectorAll('.wc-max').forEach((inp, i)=>{
    inp.addEventListener('input', (e)=>{
      weightClassesData[i].max_weight = e.target.value;
    });
  });
  
  document.querySelectorAll('.wc-code').forEach((inp, i)=>{
    inp.addEventListener('input', (e)=>{
      weightClassesData[i].division_code = e.target.value;
    });
  });
}

document.getElementById('add-weight-class').addEventListener('click', ()=>{
  weightClassesData.push({name:'', min_weight:'', max_weight:'', division_code:''});
  renderWeightClasses();
});

document.getElementById('save-weight-classes').addEventListener('click', async ()=>{
  const res = await fetch('divisions_api_inline.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      action:'save_weight_classes',
      division_id:currentDivisionId,
      weight_classes:weightClassesData
    })
  });
  
  const j = await res.json();
  if (!j.ok) alert('Error: '+j.error);
  else {
    alert('Clases de peso guardadas');
    bootstrap.Modal.getInstance(document.getElementById('weightClassesModal')).hide();
  }
});

// ----------------------
// GENERAR DIVISIONES
// ----------------------
document.getElementById('btn-generate').addEventListener('click', async ()=>{
  const tipo = document.querySelector('input[name="g_tipo"]:checked').value;
  const generos = Array.from(document.querySelectorAll('.g_gen:checked')).map(x=>x.value);
  const refs = Array.from(document.querySelectorAll('.g_ref:checked')).map(x=>x.value);
  const ages = Array.from(document.querySelectorAll('.g_age:checked')).map(x=>x.value);
  const score = document.getElementById('g_score').value;

  if (!generos.length || !refs.length || !ages.length) {
    alert('Selecciona género, R/E y al menos una edad');
    return;
  }

  const payload = { action:'generate', meet_id:meetId, tipo, generos, refs, ages, score, competition_type: tipo };

  const res = await fetch('divisions_api_inline.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });

  const j = await res.json();
  if (!j.ok) alert('Error: '+j.error);
  else location.reload();
});
</script>

<footer class="mt-5 text-center text-secondary">© 2025 USLCast</footer>

</body>
</html>
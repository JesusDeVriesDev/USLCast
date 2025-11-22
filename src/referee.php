<?php
// referee.php — Tablet del árbitro
session_start();
header('Content-Type: text/html; charset=utf-8');

require_once 'database.php';
try { $pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch(Exception $e){ die("DB error: ".$e->getMessage()); }

function json_out($v){ 
    header('Content-Type: application/json; charset=utf-8'); 
    echo json_encode($v); 
    exit; 
}

// --- Verificar sesión y rol (mínimo referee = rol 2) ---
if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'Sesión no iniciada']);
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Acceso denegado</h1><p style='text-align:center;'>Debes iniciar sesión para acceder a esta página.</p>");
}

$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$userRole = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userRole || $userRole['role'] < 2) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'Acceso denegado']);
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Solo árbitros y organizadores (rol 2+)</h1>");
}

// --- Parámetros requeridos ---
$meet_id = isset($_REQUEST['meet']) ? (int)$_REQUEST['meet'] : null;
$platform_filter = isset($_REQUEST['platform']) && $_REQUEST['platform'] !== '' ? $_REQUEST['platform'] : null;
$referee_num = isset($_REQUEST['ref']) ? (int)$_REQUEST['ref'] : null;

if (!$meet_id) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'meet required']);
    die("<h3 style='color:red;text-align:center;'>Falta parámetro: ?meet=ID&platform=ID&ref=1|2|3</h3>");
}

if (!$platform_filter) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'platform required']);
    die("<h3 style='color:red;text-align:center;'>Falta parámetro: ?meet=ID&platform=ID&ref=1|2|3</h3>");
}

if (!$referee_num || $referee_num < 1 || $referee_num > 3) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'ref must be 1, 2, or 3']);
    die("<h3 style='color:red;text-align:center;'>Falta parámetro: ?ref=1|2|3</h3>");
}

// --- Load meet ---
$stmt = $pdo->prepare("SELECT * FROM meets WHERE id = :id");
$stmt->execute(['id' => $meet_id]);
$meet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meet) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'meet not found']);
    die("<h3 style='color:red;text-align:center;'>Competencia no encontrada.</h3>");
}

// --- Verificar que el meet pertenece al usuario (solo si es organizador rol 2) ---
// Los árbitros (rol 2) pueden acceder a cualquier meet, pero verificamos ownership para organizadores
if ($userRole['role'] == 2 && $meet['organizer_id'] != $_SESSION['user_id']) {
    // Si es rol 2 y NO es el organizador, aún puede acceder como árbitro
    // Verificamos si está asignado como referee en este meet
    $refCheck = $pdo->prepare("SELECT id FROM referees WHERE user_id = :uid AND meet_id = :mid");
    $refCheck->execute(['uid' => $_SESSION['user_id'], 'mid' => $meet_id]);
    
    if (!$refCheck->fetchColumn()) {
        if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'not authorized']);
        die("<h3 style='color:red;text-align:center;'>Competencia no encontrada o no te pertenece.</h3>");
    }
}

// --- Get platform_id ---
$platform_id = null;
if (is_numeric($platform_filter)) {
    $platform_id = (int)$platform_filter;
} else {
    $q = $pdo->prepare("SELECT id FROM platforms WHERE meet_id = :mid AND name = :name");
    $q->execute(['mid' => $meet_id, 'name' => $platform_filter]);
    $platform_id = $q->fetchColumn();
}

if (!$platform_id) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'platform not found']);
    die("<h3 style='color:red;text-align:center;'>Plataforma no encontrada.</h3>");
}

// --- Load platform ---
$pstmt = $pdo->prepare("SELECT * FROM platforms WHERE id = :id");
$pstmt->execute(['id' => $platform_id]);
$platform = $pstmt->fetch(PDO::FETCH_ASSOC);

if (!$platform) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'platform not found']);
    die("<h3 style='color:red;text-align:center;'>Plataforma no encontrada.</h3>");
}

$settings = json_decode($platform['settings'] ?? '{}', true);
if (!is_array($settings)) $settings = [];

// ========== AJAX: state ==========
if (isset($_GET['ajax']) && $_GET['ajax'] === 'state') {
    // Reload settings fresh
    $pstmt->execute(['id' => $platform_id]);
    $platform = $pstmt->fetch(PDO::FETCH_ASSOC);
    $settings = json_decode($platform['settings'] ?? '{}', true) ?: [];
    
    $current_attempt = $settings['current_attempt'] ?? null;
    $attempt = null;

    if ($current_attempt && $current_attempt > 0) {
        $q = $pdo->prepare("SELECT a.*, c.name, c.flight, c.lot_number 
                            FROM attempts a
                            JOIN competitors c ON c.id = a.competitor_id
                            WHERE a.id = :id");
        $q->execute(['id' => $current_attempt]);
        $attempt = $q->fetch(PDO::FETCH_ASSOC);
        
        if ($attempt) {
            $attempt['referee_calls'] = json_decode($attempt['referee_calls'] ?? '[]', true);
        }
    } elseif ($current_attempt && $current_attempt < 0) {
        // Generated attempt (not yet in DB)
        $attempt = [
            "id" => $current_attempt,
            "weight" => null,
            "lift_type" => null,
            "attempt_number" => null,
            "name" => "Lifter",
            "flight" => "-",
            "lot_number" => "-",
            "referee_calls" => []
        ];
    }

    json_out([
        "ok" => true,
        "platform" => ["id" => $platform_id, "name" => $platform['name']],
        "current_attempt" => $current_attempt,
        "attempt" => $attempt
    ]);
}

// ========== AJAX: call (GOOD/BAD) ==========
if (isset($_GET['ajax']) && $_GET['ajax'] === 'call' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true) ?: [];
    $attempt_id = $data['attempt_id'] ?? null;
    $call = $data['call'] ?? null;
    $ref = $data['referee'] ?? null;

    if (!$attempt_id) json_out(["ok" => false, "error" => "Missing attempt_id"]);
    if (!in_array($call, ["good", "bad"])) json_out(["ok" => false, "error" => "Invalid call"]);
    if (!$ref || $ref < 1 || $ref > 3) json_out(["ok" => false, "error" => "Invalid referee number"]);

    if ((int)$attempt_id < 0) {
        json_out(["ok" => false, "error" => "Intento no guardado aún. El scorer debe asignar peso primero."]);
    }

    // Load existing referee calls
    $q = $pdo->prepare("SELECT referee_calls FROM attempts WHERE id = :id");
    $q->execute(['id' => $attempt_id]);
    $current = $q->fetchColumn();
    $calls = json_decode($current ?? "[]", true);
    if (!is_array($calls)) $calls = [];

    // Replace existing vote or add new
    $found = false;
    foreach ($calls as &$c) {
        if (isset($c['referee']) && (int)$c['referee'] === (int)$ref) {
            $c['call'] = $call;
            $c['timestamp'] = time();
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $calls[] = [
            "referee" => (int)$ref, 
            "call" => $call,
            "timestamp" => time()
        ];
    }

    // Save updated calls
    $pdo->prepare("UPDATE attempts SET referee_calls = :c WHERE id = :id")
        ->execute(['c' => json_encode($calls), 'id' => $attempt_id]);

    json_out(["ok" => true]);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Referee <?= $referee_num ?> — <?= htmlspecialchars($platform['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    -webkit-tap-highlight-color: transparent;
}

body {
    background: #111;
    color: #fff;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    touch-action: manipulation;
}

.header {
    padding: 12px;
    background: #1a1a1a;
    text-align: center;
    border-bottom: 3px solid #333;
}

.header h1 {
    font-size: 1.4rem;
    color: #ffcc00;
}

.header .sub {
    font-size: 0.9rem;
    color: #888;
    margin-top: 4px;
}

.card {
    margin: 16px;
    padding: 20px;
    background: #1a1a1a;
    border-radius: 12px;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.lifter-name {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 8px;
    text-align: center;
}

.lift-info {
    font-size: 1.4rem;
    margin-bottom: 8px;
    text-align: center;
    color: #aaa;
}

.weight {
    font-size: 3.5rem;
    font-weight: 700;
    color: #ffcc00;
    margin: 20px 0;
    text-align: center;
}

.ref-lights {
    display: flex;
    justify-content: center;
    gap: 16px;
    margin: 20px 0;
}

.ref-light {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: #333;
    border: 4px solid #555;
    transition: all 0.3s ease;
    position: relative;
}

.ref-light.voted {
    background: #666;
    box-shadow: 0 0 10px rgba(255,255,255,0.3);
}

.ref-light.good {
    background: #0b6b2b;
    border-color: #0f0;
    box-shadow: 0 0 20px rgba(0,255,0,0.5);
}

.ref-light.bad {
    background: #6b0b0b;
    border-color: #f00;
    box-shadow: 0 0 20px rgba(255,0,0,0.5);
}

.ref-light.mine::after {
    content: '';
    position: absolute;
    top: -6px;
    left: -6px;
    right: -6px;
    bottom: -6px;
    border: 3px solid #ffcc00;
    border-radius: 50%;
}

.result {
    font-size: 1.8rem;
    font-weight: 700;
    text-align: center;
    margin: 16px 0;
    min-height: 40px;
}

.buttons {
    display: flex;
    gap: 16px;
    padding: 16px;
}

.btn {
    flex: 1;
    padding: 40px 20px;
    font-size: 2.2rem;
    font-weight: 700;
    border: none;
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-transform: uppercase;
    box-shadow: 0 4px 8px rgba(0,0,0,0.3);
}

.btn:active {
    transform: scale(0.95);
    box-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.btn-good {
    background: linear-gradient(135deg, #0b6b2b 0%, #0a5a24 100%);
    color: #fff;
}

.btn-good:hover {
    background: linear-gradient(135deg, #0a5a24 0%, #084a1d 100%);
}

.btn-bad {
    background: linear-gradient(135deg, #6b0b0b 0%, #5a0a0a 100%);
    color: #fff;
}

.btn-bad:hover {
    background: linear-gradient(135deg, #5a0a0a 0%, #4a0808 100%);
}

.btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    transform: none !important;
}

.status {
    text-align: center;
    padding: 12px;
    color: #888;
    font-size: 1rem;
    background: #1a1a1a;
    border-top: 2px solid #333;
}

.status.success {
    color: #0f0;
    background: #0a3a0a;
}

.status.error {
    color: #f00;
    background: #3a0a0a;
}
</style>
</head>
<body>

<div class="header">
  <h1>ÁRBITRO <?= $referee_num ?></h1>
  <div class="sub"><?= htmlspecialchars($platform['name']) ?> — <?= htmlspecialchars($meet['name']) ?></div>
</div>

<div class="card">
  <div class="lifter-name" id="lifter-name">Esperando...</div>
  <div class="lift-info" id="lift-info">--</div>
  <div class="weight" id="weight">--</div>
  
  <div class="ref-lights">
    <div class="ref-light" id="l1"></div>
    <div class="ref-light" id="l2"></div>
    <div class="ref-light" id="l3"></div>
  </div>
  <div class="result" id="result"></div>
</div>

<div class="buttons">
  <button class="btn btn-good" id="btn-good">✓ GOOD</button>
  <button class="btn btn-bad" id="btn-bad">✗ BAD</button>
</div>

<div class="status" id="status">Toca un botón para votar</div>

<script>
const MEET_ID = <?= json_encode($meet_id) ?>;
const PLATFORM_ID = <?= json_encode($platform_id) ?>;
const REF = <?= json_encode($referee_num) ?>;

let currentAttempt = null;
let myVote = null;

async function poll(){
  try {
    const r = await fetch(`referee.php?meet=${MEET_ID}&platform=${PLATFORM_ID}&ref=${REF}&ajax=state`);
    const j = await r.json();
    
    if (!j.ok) {
      console.error('Poll error:', j);
      return;
    }
    
    // Reset UI if attempt changed
    if (currentAttempt !== j.current_attempt) {
      myVote = null;
      document.getElementById('btn-good').disabled = false;
      document.getElementById('btn-bad').disabled = false;
      document.getElementById('status').textContent = 'Toca un botón para votar';
      document.getElementById('status').className = 'status';
    }
    
    currentAttempt = j.current_attempt;
    const a = j.attempt;
    
    if (!currentAttempt || !a) {
      document.getElementById('lifter-name').textContent = 'Esperando intento...';
      document.getElementById('lift-info').textContent = '--';
      document.getElementById('weight').textContent = '--';
      updateLights([]);
      return;
    }
    
    document.getElementById('lifter-name').textContent = a.name || 'Lifter';
    document.getElementById('lift-info').textContent = 
      `${a.lift_type || '--'} #${a.attempt_number || '-'} • Flight: ${a.flight || '-'} • Lot: ${a.lot_number || '-'}`;
    document.getElementById('weight').textContent = a.weight ? a.weight + ' kg' : '--';
    
    updateLights(a.referee_calls || []);
    
  } catch (e) {
    console.error('Poll exception:', e);
  }
}

function updateLights(calls) {
  const els = ['l1', 'l2', 'l3'].map(id => document.getElementById(id));
  
  // Reset all lights
  els.forEach((el, i) => {
    el.className = 'ref-light';
    if (i + 1 === REF) el.classList.add('mine');
  });
  
  const allVoted = calls.length >= 3;
  
  if (allVoted) {
    // Reveal all votes
    calls.forEach(c => {
      const el = document.getElementById('l' + c.referee);
      if (el) el.classList.add(c.call);
    });
    
    const goods = calls.filter(c => c.call === 'good').length;
    const resultEl = document.getElementById('result');
    resultEl.textContent = goods >= 2 ? '✅ GOOD LIFT!' : '❌ NO LIFT';
    resultEl.style.color = goods >= 2 ? '#0f0' : '#f00';
    
  } else {
    // Show who voted (gray) but hide their decision
    calls.forEach(c => {
      const el = document.getElementById('l' + c.referee);
      if (el) el.classList.add('voted');
    });
    
    const resultEl = document.getElementById('result');
    resultEl.textContent = calls.length > 0 ? `${calls.length}/3 votos` : '';
    resultEl.style.color = '#888';
  }
}

async function sendCall(type) {
  if (!currentAttempt) {
    alert('No hay intento activo');
    return;
  }
  
  if (currentAttempt < 0) {
    alert('El scorer debe asignar peso primero');
    return;
  }
  
  const statusEl = document.getElementById('status');
  statusEl.textContent = 'Enviando...';
  statusEl.className = 'status';
  
  document.getElementById('btn-good').disabled = true;
  document.getElementById('btn-bad').disabled = true;
  
  try {
    const r = await fetch(`referee.php?meet=${MEET_ID}&platform=${PLATFORM_ID}&ref=${REF}&ajax=call`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        attempt_id: currentAttempt, 
        referee: REF, 
        call: type
      })
    });
    
    const j = await r.json();
    
    if (!j.ok) {
      alert('Error: ' + (j.error || 'desconocido'));
      document.getElementById('btn-good').disabled = false;
      document.getElementById('btn-bad').disabled = false;
      statusEl.textContent = 'Error al enviar';
      statusEl.className = 'status error';
      return;
    }
    
    myVote = type;
    statusEl.textContent = '✓ Voto registrado: ' + type.toUpperCase();
    statusEl.className = 'status success';
    
    // Refresh immediately
    poll();
    
  } catch (e) {
    console.error('Send call error:', e);
    statusEl.textContent = 'Error de conexión';
    statusEl.className = 'status error';
    document.getElementById('btn-good').disabled = false;
    document.getElementById('btn-bad').disabled = false;
  }
}

// Button handlers
document.getElementById('btn-good').onclick = () => sendCall('good');
document.getElementById('btn-bad').onclick = () => sendCall('bad');

// Start polling
poll();
setInterval(poll, 1000);
</script>
</body>
</html>
<?php

session_start();

require_once 'database.php';
try { $pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch(Exception $e){ die("DB error: ".$e->getMessage()); }

function json_out($v){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($v); exit; }
function safe($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$meet_id = isset($_REQUEST['meet']) ? (int)$_REQUEST['meet'] : null;
$platform_filter = isset($_REQUEST['platform']) && $_REQUEST['platform'] !== '' ? $_REQUEST['platform'] : null;

if (!$meet_id) die("Use ?meet=ID&platform=ID");
if (!$platform_filter) die("Platform required: ?meet=ID&platform=ID");

$sth = $pdo->prepare("SELECT * FROM meets WHERE id = :id");
$sth->execute(['id'=>$meet_id]);
$meet = $sth->fetch(PDO::FETCH_ASSOC);
if (!$meet) die("Meet not found");

// Get platform_id
$platform_id = null;
if (is_numeric($platform_filter)) {
    $platform_id = (int)$platform_filter;
} else {
    $q = $pdo->prepare("SELECT id FROM platforms WHERE meet_id = :mid AND name = :name");
    $q->execute(['mid'=>$meet_id,'name'=>$platform_filter]);
    $platform_id = $q->fetchColumn();
}
if (!$platform_id) die("Platform not found");

$pstmt = $pdo->prepare("SELECT * FROM platforms WHERE id = :id");
$pstmt->execute(['id'=>$platform_id]);
$platform = $pstmt->fetch(PDO::FETCH_ASSOC);
if (!$platform) die("Platform not found");

$settings = json_decode($platform['settings'] ?? '{}', true) ?: [];

// AJAX state endpoint
if (isset($_GET['ajax']) && $_GET['ajax'] === 'state') {
    // Reload settings fresh
    $pstmt->execute(['id'=>$platform_id]);
    $platform = $pstmt->fetch(PDO::FETCH_ASSOC);
    $settings = json_decode($platform['settings'] ?? '{}', true) ?: [];
    
    // Load competitors
    $sql = "SELECT c.* FROM competitors c
            WHERE c.meet_id = :meet AND c.platform_id = :plat";
    $q = $pdo->prepare($sql);
    $q->execute(['meet'=>$meet_id, 'plat'=>$platform_id]);
    $comps = $q->fetchAll(PDO::FETCH_ASSOC);
    
    // Load attempts
    $comp_ids = array_column($comps, 'id');
    $attempts_idx = [];
    if (!empty($comp_ids)) {
        $in = implode(',', array_fill(0,count($comp_ids),'?'));
        $aq = $pdo->prepare("SELECT a.* FROM attempts a WHERE a.competitor_id IN ($in)");
        $aq->execute($comp_ids);
        foreach($aq->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $attempts_idx[$a['competitor_id']][$a['lift_type']][$a['attempt_number']] = $a;
        }
    }
    
    $current_attempt = $settings['current_attempt'] ?? null;
    
    // Find current lifter
    $current = ['lifter'=>null,'lift'=>null,'attempt_num'=>null,'weight'=>null,'referee_calls'=>[]];
    if ($current_attempt && $current_attempt > 0) {
        foreach($comps as $c) {
            $cid = $c['id'];
            foreach(['Squat','Bench','Deadlift'] as $lift) {
                for($i=1;$i<=4;$i++) {
                    if (isset($attempts_idx[$cid][$lift][$i]) && $attempts_idx[$cid][$lift][$i]['id'] == $current_attempt) {
                        $current['lifter'] = $c;
                        $current['lift'] = $lift;
                        $current['attempt_num'] = $i;
                        $current['weight'] = $attempts_idx[$cid][$lift][$i]['weight'];
                        $current['referee_calls'] = json_decode($attempts_idx[$cid][$lift][$i]['referee_calls'] ?? '[]', true);
                        break 3;
                    }
                }
            }
        }
    }
    
    json_out([
        'ok'=>true,
        'current'=>$current
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Live Feed — <?= safe($meet['name']) ?> — <?= safe($platform['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#000;color:#fff;font-family:Arial,sans-serif;overflow:hidden}
.feed{width:100vw;height:100vh;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:40px}

.lifter-card{background:#1a1a1a;border:3px solid #333;border-radius:12px;padding:40px 60px;margin-bottom:40px;text-align:center;min-width:600px}
.lifter-name{font-size:3.5rem;font-weight:700;margin-bottom:16px}
.lift-info{font-size:2.5rem;color:#aaa;margin-bottom:8px}

.ref-lights{display:flex;gap:40px;justify-content:center;margin-top:20px}
.ref-light{width:120px;height:120px;border-radius:50%;background:#1a1a1a;border:6px solid #333;transition:all 0.3s;position:relative}
.ref-light::after{content:'';position:absolute;inset:0;border-radius:50%;box-shadow:inset 0 0 20px rgba(0,0,0,0.5)}
.ref-light.voted{background:#444;border-color:#666}
.ref-light.good{background:#0b6b2b;border-color:#0f0;box-shadow:0 0 40px #0f0}
.ref-light.bad{background:#6b0b0b;border-color:#f00;box-shadow:0 0 40px #f00}

.ref-result{font-size:2.5rem;font-weight:700;text-align:center;margin-top:30px;min-height:60px}

.waiting{color:#666;font-size:2rem;text-align:center}
</style>
</head>
<body>
<div class="feed">
  <div id="content">
    <div class="waiting">Esperando próximo intento...</div>
  </div>
</div>

<script>
const MEET_ID = <?= json_encode($meet_id) ?>;
const PLATFORM_ID = <?= json_encode($platform_id) ?>;
let state = {};

async function fetchState(){
  const r = await fetch(`livefeed.php?meet=${MEET_ID}&platform=${PLATFORM_ID}&ajax=state`);
  state = await r.json();
  if(state.ok) render();
}

function render(){
  const content = document.getElementById('content');
  const c = state.current;
  
  if(!c?.lifter || !c.weight){
    content.innerHTML = '<div class="waiting">Esperando próximo intento...</div>';
    return;
  }
  
  const liftNames = {
    'Squat': 'Sentadilla',
    'Bench': 'Press Banca',
    'Deadlift': 'Peso Muerto'
  };
  
  const lbs = (c.weight * 2.20462).toFixed(1);
  
  content.innerHTML = `
    <div class="lifter-card">
      <div class="lifter-name">${esc(c.lifter.name)}</div>
      <div class="lift-info">${liftNames[c.lift] || c.lift}-${c.attempt_num}: ${c.weight} kg (${lbs} lbs)</div>
    </div>
    
    <div class="ref-lights">
      <div class="ref-light" id="r1"></div>
      <div class="ref-light" id="r2"></div>
      <div class="ref-light" id="r3"></div>
    </div>
    
    <div class="ref-result" id="ref-result"></div>
  `;
  
  renderLights();
}

function renderLights(){
  const els = ['r1','r2','r3'].map(id => document.getElementById(id));
  if(!els[0]) return; // Not rendered yet
  
  els.forEach(el => el.className = 'ref-light');
  
  const calls = state.current?.referee_calls || [];
  const allVoted = calls.length >= 3;
  
  if(allVoted){
    // Reveal all votes
    calls.forEach(c => {
      const el = document.getElementById('r'+c.referee);
      if(el) el.classList.add(c.call);
    });
    const goods = calls.filter(c => c.call === 'good').length;
    const resultEl = document.getElementById('ref-result');
    if(resultEl){
      resultEl.textContent = goods >= 2 ? '¡VÁLIDO!' : '¡NULO!';
      resultEl.style.color = goods >= 2 ? '#0f0' : '#f00';
    }
  } else {
    // Show who has voted (gray) but not their decision
    calls.forEach(c => {
      const el = document.getElementById('r'+c.referee);
      if(el) el.classList.add('voted');
    });
    const resultEl = document.getElementById('ref-result');
    if(resultEl){
      resultEl.textContent = calls.length > 0 ? `${calls.length}/3 votos` : '';
      resultEl.style.color = '#888';
    }
  }
}

function esc(s){ return s == null ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Polling
fetchState();
setInterval(fetchState, 500); // Update more frequently for live feel
</script>

</body>
</html>
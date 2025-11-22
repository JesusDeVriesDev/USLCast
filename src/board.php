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
$plate_colors = json_decode($platform['plate_colors'] ?? '{}', true) ?: [];

// AJAX state endpoint
if (isset($_GET['ajax']) && $_GET['ajax'] === 'state') {
    // Reload settings fresh
    $pstmt->execute(['id'=>$platform_id]);
    $platform = $pstmt->fetch(PDO::FETCH_ASSOC);
    $settings = json_decode($platform['settings'] ?? '{}', true) ?: [];
    
    $bar_weight = $settings['bar_weight'] ?? 20;
    $collar_weight = $settings['collar_weight'] ?? 2.5;
    
    // Load competitors
    $sql = "SELECT c.*, 
            STRING_AGG(DISTINCT d.name, ', ') as division_names,
            STRING_AGG(DISTINCT cd.declared_weight_class, ', ') as weight_classes
            FROM competitors c
            LEFT JOIN competitor_divisions cd ON c.id = cd.competitor_id
            LEFT JOIN divisions d ON cd.division_id = d.id
            WHERE c.meet_id = :meet AND c.platform_id = :plat
            GROUP BY c.id";
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
    
    // Build competitor data
    $competitors = [];
    foreach ($comps as $c) {
        $cid = $c['id'];
        $attempts_json = json_decode($c['attempts'] ?? '{}', true) ?: [];
        $rack = json_decode($c['rack_height'] ?? '{}', true) ?: [];
        
        $get_data = function($lift,$no) use ($attempts_idx,$cid,$attempts_json) {
            if (isset($attempts_idx[$cid][$lift][$no])) {
                $a = $attempts_idx[$cid][$lift][$no];
                return [
                    'id' => $a['id'],
                    'weight' => $a['weight'],
                    'success' => $a['success'],
                    'referee_calls' => json_decode($a['referee_calls'] ?? '[]', true)
                ];
            }
            $lk = strtolower($lift);
            $w = (isset($attempts_json[$lk][$no])) ? $attempts_json[$lk][$no] : null;
            return ['id'=>null, 'weight'=>$w, 'success'=>null, 'referee_calls'=>[]];
        };
        
        $s = []; $b = []; $d = [];
        for ($i=1;$i<=4;$i++){
            $s[] = $get_data('Squat',$i);
            $b[] = $get_data('Bench',$i);
            $d[] = $get_data('Deadlift',$i);
        }
        
        $competitors[] = [
            'id'=>$cid,
            'name'=>$c['name'],
            'body_weight'=>$c['body_weight'],
            'lot_number'=>$c['lot_number'],
            'session'=>$c['session'],
            'flight'=>$c['flight'],
            'division'=>$c['division_names'],
            'weight_class'=>$c['weight_classes'],
            'rack_squat'=>$rack['squat']??'',
            'rack_bench'=>$rack['bench']??'',
            'squats'=>$s,
            'bench'=>$b,
            'deadlift'=>$d
        ];
    }
    
    // Determine current lift phase - completar todos los Squat antes de pasar a Bench
    $phase_votes = ['Squat'=>0, 'Bench'=>0, 'Deadlift'=>0];
    foreach ($competitors as $comp) {
        // Contar intentos pendientes por cada lift
        foreach ($comp['squats'] as $at) {
            if ($at['weight'] !== null && $at['success'] === null) {
                $phase_votes['Squat']++;
            }
        }
        foreach ($comp['bench'] as $at) {
            if ($at['weight'] !== null && $at['success'] === null) {
                $phase_votes['Bench']++;
            }
        }
        foreach ($comp['deadlift'] as $at) {
            if ($at['weight'] !== null && $at['success'] === null) {
                $phase_votes['Deadlift']++;
            }
        }
    }
    
    // Prioridad: completar Squat -> Bench -> Deadlift
    $current_lift = 'Squat';
    if ($phase_votes['Squat'] > 0) {
        $current_lift = 'Squat';
    } elseif ($phase_votes['Bench'] > 0) {
        $current_lift = 'Bench';
    } elseif ($phase_votes['Deadlift'] > 0) {
        $current_lift = 'Deadlift';
    }
    
    $lift_key_map = ['Squat'=>'squats', 'Bench'=>'bench', 'Deadlift'=>'deadlift'];
    $key = $lift_key_map[$current_lift];
    
    // Ordenar competidores: session -> flight -> peso próximo intento -> body weight -> lot -> name
    usort($competitors, function($a, $b) use ($key) {
        // 1. Session ASC
        $as = $a['session'] === null ? 999999 : (int)$a['session'];
        $bs = $b['session'] === null ? 999999 : (int)$b['session'];
        if ($as != $bs) return $as - $bs;
        
        // 2. Flight ASC
        $af = $a['flight'] ?? '';
        $bf = $b['flight'] ?? '';
        if ($af !== $bf) return strcmp($af, $bf);
        
        // 3. Próximo intento pendiente (peso) para el lift actual
        $a_weight = null;
        $b_weight = null;
        
        foreach ($a[$key] as $at) {
            if ($at['weight'] !== null && $at['success'] === null) {
                $a_weight = (float)$at['weight'];
                break;
            }
        }
        foreach ($b[$key] as $at) {
            if ($at['weight'] !== null && $at['success'] === null) {
                $b_weight = (float)$at['weight'];
                break;
            }
        }
        
        // Competidores sin intentos pendientes van al final
        if ($a_weight === null && $b_weight !== null) return 1;
        if ($a_weight !== null && $b_weight === null) return -1;
        
        // Ordenar por peso del próximo intento (menor peso primero)
        if ($a_weight !== null && $b_weight !== null && abs($a_weight - $b_weight) > 0.001) {
            return ($a_weight < $b_weight) ? -1 : 1;
        }
        
        // 4. Body weight ASC (el más liviano va primero)
        $abw = $a['body_weight'] === null ? 999999 : (float)$a['body_weight'];
        $bbw = $b['body_weight'] === null ? 999999 : (float)$b['body_weight'];
        if (abs($abw - $bbw) > 0.001) return ($abw < $bbw) ? -1 : 1;
        
        // 5. Lot number ASC
        $al = $a['lot_number'] === null ? 999999 : (int)$a['lot_number'];
        $bl = $b['lot_number'] === null ? 999999 : (int)$b['lot_number'];
        if ($al != $bl) return $al - $bl;
        
        // 6. Name ASC
        return strcmp($a['name'], $b['name']);
    });
    
    $current_attempt = $settings['current_attempt'] ?? null;
    $timer = $settings['timer'] ?? ['running'=>false,'started_at'=>null,'duration'=>60];
    
    // Find current lifter
    $current = ['lifter'=>null,'lift'=>null,'attempt_num'=>null,'weight'=>null,'rack'=>'','referee_calls'=>[]];
    if ($current_attempt && $current_attempt > 0) {
        foreach($comps as $c) {
            $cid = $c['id'];
            $rack = json_decode($c['rack_height'] ?? '{}', true) ?: [];
            foreach(['Squat','Bench','Deadlift'] as $lift) {
                for($i=1;$i<=4;$i++) {
                    if (isset($attempts_idx[$cid][$lift][$i]) && $attempts_idx[$cid][$lift][$i]['id'] == $current_attempt) {
                        $current['lifter'] = $c;
                        $current['lift'] = $lift;
                        $current['attempt_num'] = $i;
                        $current['weight'] = $attempts_idx[$cid][$lift][$i]['weight'];
                        $current['rack'] = ($lift === 'Squat') ? ($rack['squat'] ?? '') : (($lift === 'Bench') ? ($rack['bench'] ?? '') : '');
                        $current['referee_calls'] = json_decode($attempts_idx[$cid][$lift][$i]['referee_calls'] ?? '[]', true);
                        break 3;
                    }
                }
            }
        }
    }
    
    json_out([
        'ok'=>true,
        'meet'=>['id'=>$meet_id,'name'=>$meet['name']],
        'platform'=>['id'=>$platform_id,'name'=>$platform['name']],
        'competitors'=>$competitors,
        'timer'=>$timer,
        'current_attempt_id'=>$current_attempt,
        'current'=>$current,
        'plate_colors'=>$plate_colors,
        'bar_weight'=>$bar_weight,
        'collar_weight'=>$collar_weight
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Board — <?= safe($meet['name']) ?> — <?= safe($platform['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#000;color:#fff;font-family:Arial,sans-serif;overflow:hidden}
.board{width:100vw;height:100vh;display:flex;flex-direction:column;padding:16px}

.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.platform-title{font-size:1.3rem;font-weight:700}
.timer{font-size:4rem;font-weight:700;font-family:'Courier New',monospace}
.timer.warning{color:#ff0}
.timer.danger{color:#f00}

.current-lifter{background:#1a1a1a;border:3px solid #333;border-radius:8px;padding:16px;margin-bottom:12px}
.lifter-name{font-size:2.5rem;font-weight:700}
.lift-info{font-size:1.8rem;margin-top:8px}
.division-info{font-size:1.2rem;color:#aaa;margin-top:4px}

.ref-lights{display:flex;gap:16px;justify-content:center;margin-bottom:12px}
.ref-light{width:70px;height:70px;border-radius:50%;background:#333;border:4px solid #555;transition:all 0.3s}
.ref-light.voted{background:#666}
.ref-light.good{background:#0b6b2b;border-color:#0f0;box-shadow:0 0 20px #0f0}
.ref-light.bad{background:#6b0b0b;border-color:#f00;box-shadow:0 0 20px #f00}
.ref-result{font-size:1.5rem;font-weight:700;text-align:center;margin-bottom:12px}

.plate-loading{background:#1a1a1a;border:2px solid #333;border-radius:8px;padding:12px;margin-bottom:12px}
.plate-loading h3{margin-bottom:8px;font-size:1.2rem}
.plates-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.plate{padding:6px 10px;border-radius:4px;font-weight:700;font-size:1.1rem;border:2px solid #fff}
.rack-info{margin-left:auto;font-size:1.2rem;font-weight:700;color:#ffcc00}

.attempts-table{flex:1;overflow:auto}
table{width:100%;border-collapse:collapse;background:#111}
th,td{padding:6px 8px;border:1px solid #333;text-align:center;font-size:0.9rem}
th{background:#1a1a1a;position:sticky;top:0}
.att{display:inline-block;min-width:45px;padding:3px 6px;border-radius:4px;font-weight:700}
.att-good{background:#0b6b2b}
.att-bad{background:#6b0b0b}
.att-pending{background:#333}
.att-current{background:#1f78d1;box-shadow:0 0 8px #1f78d1}
.current-row{background:#2a1a1a!important}

.next-lifter{background:#1a0000;padding:12px;text-align:center;font-size:1.3rem;font-weight:700;border-top:3px solid #c00}
</style>
</head>
<body>
<div class="board">
  <div class="header">
    <div class="platform-title"><?= safe($meet['name']) ?> — Board Plataforma: <?= safe($platform['name']) ?></div>
    <div class="timer" id="timer">1:00</div>
    <div style="text-align:right">
      <div id="current-name" style="font-size:1.5rem;font-weight:700">--</div>
      <div id="current-lift" style="font-size:1.1rem">--</div>
    </div>
  </div>

  <div class="ref-lights">
    <div class="ref-light" id="r1"></div>
    <div class="ref-light" id="r2"></div>
    <div class="ref-light" id="r3"></div>
  </div>
  <div class="ref-result" id="ref-result"></div>

  <div class="plate-loading">
    <h3>Peso en la barra:</h3>
    <div class="plates-row">
      <div id="plates"></div>
      <div class="rack-info" id="rack-info">Rack: --</div>
    </div>
  </div>

  <div class="attempts-table">
    <table>
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Peso corporal</th>
          <th>S1</th><th>S2</th><th>S3</th><th>S4</th>
          <th>B1</th><th>B2</th><th>B3</th><th>B4</th>
          <th>Subtotal</th>
          <th>D1</th><th>D2</th><th>D3</th><th>D4</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody id="tbody"></tbody>
    </table>
  </div>

  <div class="next-lifter" id="next-bar">Siguiente — Esperando...</div>
</div>

<script>
const MEET_ID = <?= json_encode($meet_id) ?>;
const PLATFORM_ID = <?= json_encode($platform_id) ?>;
let state = {};

async function fetchState(){
  const r = await fetch(`board.php?meet=${MEET_ID}&platform=${PLATFORM_ID}&ajax=state`);
  state = await r.json();
  if(state.ok) render();
}

function render(){
  renderTimer();
  renderCurrent();
  renderLights();
  renderPlates();
  renderTable();
  renderNext();
}

function renderTimer(){
  const t = state.timer || {duration:60};
  let rem = t.duration || 60;
  if(t.running && t.started_at){
    rem = Math.max(0, t.duration - (Math.floor(Date.now()/1000) - t.started_at));
  }
  const el = document.getElementById('timer');
  el.textContent = Math.floor(rem/60) + ':' + String(rem%60).padStart(2,'0');
  el.className = 'timer' + (rem <= 10 ? ' danger' : rem <= 30 ? ' warning' : '');
}

function renderCurrent(){
  const c = state.current;
  if(!c?.lifter){
    document.getElementById('current-name').textContent = 'Esperando...';
    document.getElementById('current-lift').textContent = '--';
    return;
  }
  document.getElementById('current-name').textContent = c.lifter.name;
  const lbs = c.weight ? (c.weight * 2.20462).toFixed(1) : '0';
  document.getElementById('current-lift').textContent = `${c.lift} #${c.attempt_num}: ${c.weight||'--'}kg (${lbs}lbs)`;
}

function renderLights(){
  const els = ['r1','r2','r3'].map(id => document.getElementById(id));
  els.forEach(el => el.className = 'ref-light');
  
  const calls = state.current?.referee_calls || [];
  const allVoted = calls.length >= 3;
  
  if(allVoted){
    // Reveal votes
    calls.forEach(c => {
      const el = document.getElementById('r'+c.referee);
      if(el) el.classList.add(c.call);
    });
    const goods = calls.filter(c => c.call === 'good').length;
    document.getElementById('ref-result').textContent = goods >= 2 ? '¡Intento válido!' : '¡Intento nulo!';
    document.getElementById('ref-result').style.color = goods >= 2 ? '#0f0' : '#f00';
  } else {
    // Show voted (gray) but not color
    calls.forEach(c => {
      const el = document.getElementById('r'+c.referee);
      if(el) el.classList.add('voted');
    });
    document.getElementById('ref-result').textContent = calls.length > 0 ? `${calls.length}/3 votos` : '';
    document.getElementById('ref-result').style.color = '#888';
  }
}

function renderPlates(){
  const weight = state.current?.weight;
  const rack = state.current?.rack;
  document.getElementById('rack-info').textContent = rack ? 'Rack: ' + rack : 'Rack: --';
  
  if(!weight){
    document.getElementById('plates').innerHTML = '<span style="color:#666">--</span>';
    return;
  }
  
  const bar = state.bar_weight || 20;
  const collar = state.collar_weight || 2.5;
  const perSide = (weight - bar - collar*2) / 2;
  
  if(perSide <= 0){
    document.getElementById('plates').innerHTML = `<span>Solo barra (${bar}kg) + collarines (${collar*2}kg)</span>`;
    return;
  }
  
  // Ordenar discos de mayor a menor
  const plateSizes = [50, 25, 20, 15, 10, 5, 2.5, 2, 1.25, 1, 0.5, 0.25];
  let remaining = perSide;
  let plates = [];
  const colors = state.plate_colors || {};
  
  // Crear un inventario de pares disponibles
  const available = {};
  plateSizes.forEach(pw => {
    const key = pw + ' KG';
    const pairs = parseInt(colors[key]?.pairs || 0);
    available[pw] = pairs;
  });
  
  // Usar discos disponibles
  for(const pw of plateSizes){
    while(remaining >= pw - 0.001 && available[pw] > 0){
      const key = pw + ' KG';
      const color = colors[key]?.color || '#666';
      plates.push({w:pw, c:color});
      remaining -= pw;
      available[pw]--;
    }
  }
  
  // Si no se pudo cargar exactamente, mostrar advertencia
  if(remaining > 0.1){
    document.getElementById('plates').innerHTML = `<span style="color:#f00">Discos insuficientes (faltan ${remaining.toFixed(2)}kg por lado)</span>`;
    return;
  }
  
  document.getElementById('plates').innerHTML = plates.map(p => 
    `<div class="plate" style="background:${p.c};color:${contrast(p.c)}">${p.w}</div>`
  ).join('') || '<span>--</span>';
}

function contrast(hex){
  if(!hex || hex.length < 7) return '#fff';
  const r = parseInt(hex.substr(1,2),16);
  const g = parseInt(hex.substr(3,2),16);
  const b = parseInt(hex.substr(5,2),16);
  return (r*299+g*587+b*114)/1000 >= 128 ? '#000' : '#fff';
}

function renderTable(){
  const tbody = document.getElementById('tbody');
  tbody.innerHTML = '';
  
  if(!state.competitors?.length){
    tbody.innerHTML = '<tr><td colspan="16">Sin competidores</td></tr>';
    return;
  }
  
  const curId = state.current_attempt_id;
  
  state.competitors.forEach(comp => {
    const tr = document.createElement('tr');
    let isCurrent = false;
    
    // Check if this competitor has current attempt
    ['squats','bench','deadlift'].forEach(s => {
      comp[s].forEach(a => { if(a.id == curId) isCurrent = true; });
    });
    if(isCurrent) tr.className = 'current-row';
    
    tr.innerHTML = `<td>${comp.name}</td><td>${comp.body_weight||'--'}</td>`;
    
    const renderAtt = (a) => {
      if(!a.weight) return '<td></td>';
      let cls = 'att ';
      if(a.id == curId) cls += 'att-current';
      else if(a.success === true || a.success === 1) cls += 'att-good';
      else if(a.success === false || a.success === 0) cls += 'att-bad';
      else cls += 'att-pending';
      return `<td><span class="${cls}">${a.weight}</span></td>`;
    };
    
    comp.squats.forEach(a => tr.innerHTML += renderAtt(a));
    comp.bench.forEach(a => tr.innerHTML += renderAtt(a));
    
    // Subtotal (solo primeros 3 intentos - el 4to no suma)
    const bestS = Math.max(0, ...comp.squats.slice(0,3).filter(a=>a.success===true||a.success===1).map(a=>parseFloat(a.weight)||0));
    const bestB = Math.max(0, ...comp.bench.slice(0,3).filter(a=>a.success===true||a.success===1).map(a=>parseFloat(a.weight)||0));
    tr.innerHTML += `<td>${bestS+bestB > 0 ? bestS+bestB : '--'}</td>`;
    
    comp.deadlift.forEach(a => tr.innerHTML += renderAtt(a));
    
    // Total (solo primeros 3 intentos - el 4to no suma)
    const bestD = Math.max(0, ...comp.deadlift.slice(0,3).filter(a=>a.success===true||a.success===1).map(a=>parseFloat(a.weight)||0));
    const total = bestS + bestB + bestD;
    tr.innerHTML += `<td style="font-weight:700">${total > 0 ? total : '--'}</td>`;
    
    tbody.appendChild(tr);
  });
}

function renderNext(){
  // Find next pending attempt after current
  let next = null;
  const curId = state.current_attempt_id;
  
  for(const comp of state.competitors || []){
    for(const [key, name] of [['squats','Sentadilla '],['bench','Press Banca '],['deadlift','Peso Muerto ']]){
      for(let i=0; i<comp[key].length; i++){
        const a = comp[key][i];
        if(a.weight && a.success === null && a.id !== curId){
          if(!next) next = {name:comp.name, lift:name, num:i+1, weight:a.weight, rack: key==='squats'?comp.rack_squat:(key==='bench'?comp.rack_bench:'')};
        }
      }
    }
  }
  
  if(next){
    document.getElementById('next-bar').textContent = `Siguiente — ${next.name} — ${next.lift}${next.num}: ${next.weight}kg` + (next.rack ? ` — Rack: ${next.rack}` : '');
  } else {
    document.getElementById('next-bar').textContent = 'Siguiente — Esperando...';
  }
}

// Polling
fetchState();
setInterval(fetchState, 1000);
</script>

</body>
</html>
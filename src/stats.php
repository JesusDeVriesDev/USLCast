<?php
session_start();
$sessionUserId = $_SESSION['user_id'] ?? $_SESSION['session_user_id'] ?? null;
if (!$sessionUserId) { header("Location: ../login.php"); exit; }

$host = "localhost"; $dbname = "uslcast"; $user = "postgres"; $pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// check role
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute(['id'=>$sessionUserId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || $u['role'] != 2) { http_response_code(403); die("403 - Solo organizadores"); }

// meet id
$meet_id = isset($_GET['meet']) ? (int)$_GET['meet'] : (isset($_GET['id'])?(int)$_GET['id']:null);
if (!$meet_id) die("ID de competencia no especificado.");

// verify meet belongs to organizer
$stmt = $pdo->prepare("SELECT * FROM meets WHERE id = :id AND organizer_id = :org");
$stmt->execute(['id'=>$meet_id,'org'=>$sessionUserId]);
$meet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$meet) die("Competencia no encontrada o no te pertenece.");

// Get platforms with sessions
$stmt = $pdo->prepare("
  SELECT DISTINCT p.id, p.name, c.session
  FROM platforms p
  LEFT JOIN competitors c ON p.id = c.platform_id
  WHERE p.meet_id = :mid AND c.session IS NOT NULL
  ORDER BY c.session, p.name
");
$stmt->execute(['mid'=>$meet_id]);
$platform_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by session and platform
$sessions = [];
foreach($platform_sessions as $ps) {
  $session = $ps['session'] ?: 1;
  if (!isset($sessions[$session])) $sessions[$session] = [];
  $sessions[$session][] = $ps;
}

// Function to calculate stats for a platform/session
function getStats($pdo, $meet_id, $platform_id, $session) {
  // Get lifters count
  $stmt = $pdo->prepare("
    SELECT COUNT(*) FROM competitors 
    WHERE meet_id=:mid AND platform_id=:pid AND session=:sess
  ");
  $stmt->execute(['mid'=>$meet_id,'pid'=>$platform_id,'sess'=>$session]);
  $lifter_count = $stmt->fetchColumn();
  
  // Get attempts data
  $stmt = $pdo->prepare("
    SELECT 
      a.lift_type,
      a.success,
      a.created_at,
      cd.raw_or_equipped
    FROM attempts a
    JOIN competitors c ON a.competitor_id = c.id
    LEFT JOIN competitor_divisions cd ON c.id = cd.competitor_id
    WHERE c.meet_id=:mid AND c.platform_id=:pid AND c.session=:sess
    ORDER BY a.created_at
  ");
  $stmt->execute(['mid'=>$meet_id,'pid'=>$platform_id,'sess'=>$session]);
  $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Calculate statistics
  $stats = [
    'lifter_count' => $lifter_count,
    'complete' => 0,
    'remaining' => 0,
    'total_attempts' => count($attempts),
    'first_squat' => null,
    'last_squat' => null,
    'first_bench' => null,
    'last_bench' => null,
    'first_dead' => null,
    'last_dead' => null,
    'avg_time_all' => ['squat'=>0,'bench'=>0,'deadlift'=>0],
    'avg_time_raw' => ['squat'=>0,'bench'=>0,'deadlift'=>0],
    'avg_time_equipped' => ['squat'=>0,'bench'=>0,'deadlift'=>0],
    'fastest_turnaround' => ['squat'=>999,'bench'=>999,'deadlift'=>999],
    'slowest_turnaround' => ['squat'=>0,'bench'=>0,'deadlift'=>0]
  ];
  
  // Count completed lifters (those with 9 attempts)
  $stmt = $pdo->prepare("
    SELECT competitor_id, COUNT(*) as cnt
    FROM attempts a
    JOIN competitors c ON a.competitor_id = c.id
    WHERE c.meet_id=:mid AND c.platform_id=:pid AND c.session=:sess
    GROUP BY competitor_id
  ");
  $stmt->execute(['mid'=>$meet_id,'pid'=>$platform_id,'sess'=>$session]);
  $lifter_attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach($lifter_attempts as $la) {
    if ($la['cnt'] >= 9) $stats['complete']++;
  }
  $stats['remaining'] = $lifter_count - $stats['complete'];
  
  // Process timestamps
  $times_by_lift = ['Squat'=>[],'Bench'=>[],'Deadlift'=>[]];
  $times_by_lift_raw = ['Squat'=>[],'Bench'=>[],'Deadlift'=>[]];
  $times_by_lift_equipped = ['Squat'=>[],'Bench'=>[],'Deadlift'=>[]];
  
  $prev_time = null;
  foreach($attempts as $att) {
    $lift = $att['lift_type'];
    $time = strtotime($att['created_at']);
    
    // Track first/last
    if ($lift === 'Squat') {
      if (!$stats['first_squat']) $stats['first_squat'] = $time;
      $stats['last_squat'] = $time;
    } elseif ($lift === 'Bench') {
      if (!$stats['first_bench']) $stats['first_bench'] = $time;
      $stats['last_bench'] = $time;
    } elseif ($lift === 'Deadlift') {
      if (!$stats['first_dead']) $stats['first_dead'] = $time;
      $stats['last_dead'] = $time;
    }
    
    // Calculate time between attempts
    if ($prev_time) {
      $diff = ($time - $prev_time) / 60; // minutes
      if ($diff > 0 && $diff < 300) { // reasonable range
        $times_by_lift[$lift][] = $diff;
        if ($att['raw_or_equipped'] === 'Raw') {
          $times_by_lift_raw[$lift][] = $diff;
        } else {
          $times_by_lift_equipped[$lift][] = $diff;
        }
      }
    }
    $prev_time = $time;
  }
  
  // Calculate averages
  $lift_map = ['Squat'=>'squat','Bench'=>'bench','Deadlift'=>'deadlift'];
  foreach($lift_map as $lift=>$key) {
    if (count($times_by_lift[$lift]) > 0) {
      $stats['avg_time_all'][$key] = round(array_sum($times_by_lift[$lift]) / count($times_by_lift[$lift]), 1);
      $stats['fastest_turnaround'][$key] = round(min($times_by_lift[$lift]), 1);
      $stats['slowest_turnaround'][$key] = round(max($times_by_lift[$lift]), 1);
    }
    if (count($times_by_lift_raw[$lift]) > 0) {
      $stats['avg_time_raw'][$key] = round(array_sum($times_by_lift_raw[$lift]) / count($times_by_lift_raw[$lift]), 1);
    }
    if (count($times_by_lift_equipped[$lift]) > 0) {
      $stats['avg_time_equipped'][$key] = round(array_sum($times_by_lift_equipped[$lift]) / count($times_by_lift_equipped[$lift]), 1);
    }
  }
  
  return $stats;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estadísticas — <?=htmlspecialchars($meet['name'])?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/styles.css">
<style>
body{background:#000;color:#fff}
.stat-card{
  background:#0f0f0f;
  border:1px solid #333;
  border-radius:8px;
  padding:1.5rem;
  margin-bottom:1.5rem;
}
.stat-card h5{
  color:#e60000;
  border-bottom:2px solid #e60000;
  padding-bottom:0.5rem;
  margin-bottom:1rem;
}
.stat-row{
  display:flex;
  justify-content:space-between;
  padding:0.4rem 0;
  border-bottom:1px solid #222;
}
.stat-row:last-child{border-bottom:none}
.stat-label{color:#999;font-size:0.9rem}
.stat-value{color:#fff;font-weight:500}
.section-title{
  font-size:0.85rem;
  color:#e60000;
  font-weight:600;
  margin-top:1rem;
  margin-bottom:0.5rem;
}
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
        <li class="nav-item"><a class="nav-link" href="lifters.php?id=<?= $meet_id ?>">Lifters</a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php">Salir</a></li>
      </ul>
    </div>
  </div>
</nav>

<div class="container py-4 text-light">
  <h2 class="text-danger mb-4 fw-bold">Estadísticas — <?=htmlspecialchars($meet['name'])?></h2>

  <?php if (empty($sessions)): ?>
    <div class="alert alert-warning">
      No hay datos de estadísticas disponibles. Asegúrate de que los levantadores tengan asignadas sesiones y plataformas, y que se hayan registrado intentos.
    </div>
  <?php else: ?>
    
    <?php foreach($sessions as $session_num => $platforms): ?>
      <h3 class="text-light mt-4 mb-3">Sesión <?=$session_num?></h3>
      
      <div class="row">
        <?php foreach($platforms as $platform): ?>
          <?php $stats = getStats($pdo, $meet_id, $platform['id'], $session_num); ?>
          
          <div class="col-md-6">
            <div class="stat-card">
              <h5>Plataforma: <?=htmlspecialchars($platform['name'])?></h5>
              
              <div class="stat-row">
                <span class="stat-label">Número de Levantadores:</span>
                <span class="stat-value"><?=$stats['lifter_count']?></span>
              </div>
              
              <div class="stat-row">
                <span class="stat-label">Completos / Restantes / Total de Intentos:</span>
                <span class="stat-value"><?=$stats['complete']?>-<?=$stats['remaining']?> / <?=$stats['total_attempts']?></span>
              </div>
              
              <?php if ($stats['first_squat']): ?>
              <div class="stat-row">
                <span class="stat-label">Primera Sentadilla Marcada:</span>
                <span class="stat-value"><?=date('g:i:s a', $stats['first_squat'])?></span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Última Sentadilla Marcada:</span>
                <span class="stat-value"><?=date('g:i:s a', $stats['last_squat'])?></span>
              </div>
              <?php endif; ?>
              
              <?php if ($stats['first_bench']): ?>
              <div class="stat-row">
                <span class="stat-label">Primer Press Banca Marcado:</span>
                <span class="stat-value"><?=date('g:i:s a', $stats['first_bench'])?></span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Último Press Banca Marcado:</span>
                <span class="stat-value"><?=date('g:i:s a', $stats['last_bench'])?></span>
              </div>
              <?php endif; ?>
              
              <?php if ($stats['first_dead']): ?>
              <div class="stat-row">
                <span class="stat-label">Primer Peso Muerto Marcado:</span>
                <span class="stat-value"><?=date('g:i:s a', $stats['first_dead'])?></span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Último Peso Muerto Marcado:</span>
                <span class="stat-value"><?=date('g:i:s a', $stats['last_dead'])?></span>
              </div>
              <?php endif; ?>
              
              <div class="section-title">Tiempo Promedio Entre Intentos - Todos</div>
              <div class="stat-row">
                <span class="stat-label">Sentadillas:</span>
                <span class="stat-value"><?=$stats['avg_time_all']['squat']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Press Banca:</span>
                <span class="stat-value"><?=$stats['avg_time_all']['bench']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Peso Muerto:</span>
                <span class="stat-value"><?=$stats['avg_time_all']['deadlift']?> min</span>
              </div>
              
              <div class="section-title">Tiempo Promedio Entre Intentos - Raw</div>
              <div class="stat-row">
                <span class="stat-label">Sentadillas:</span>
                <span class="stat-value"><?=$stats['avg_time_raw']['squat']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Press Banca:</span>
                <span class="stat-value"><?=$stats['avg_time_raw']['bench']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Peso Muerto:</span>
                <span class="stat-value"><?=$stats['avg_time_raw']['deadlift']?> min</span>
              </div>
              
              <div class="section-title">Tiempo Promedio Entre Intentos - Equipado</div>
              <div class="stat-row">
                <span class="stat-label">Sentadillas:</span>
                <span class="stat-value"><?=$stats['avg_time_equipped']['squat']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Press Banca:</span>
                <span class="stat-value"><?=$stats['avg_time_equipped']['bench']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Peso Muerto:</span>
                <span class="stat-value"><?=$stats['avg_time_equipped']['deadlift']?> min</span>
              </div>
              
              <div class="section-title">Cambio Más Rápido</div>
              <div class="stat-row">
                <span class="stat-label">Sentadillas:</span>
                <span class="stat-value"><?=$stats['fastest_turnaround']['squat']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Press Banca:</span>
                <span class="stat-value"><?=$stats['fastest_turnaround']['bench']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Peso Muerto:</span>
                <span class="stat-value"><?=$stats['fastest_turnaround']['deadlift']?> min</span>
              </div>
              
              <div class="section-title">Cambio Más Lento</div>
              <div class="stat-row">
                <span class="stat-label">Sentadillas:</span>
                <span class="stat-value"><?=$stats['slowest_turnaround']['squat']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Press Banca:</span>
                <span class="stat-value"><?=$stats['slowest_turnaround']['bench']?> min</span>
              </div>
              <div class="stat-row">
                <span class="stat-label">Peso Muerto:</span>
                <span class="stat-value"><?=$stats['slowest_turnaround']['deadlift']?> min</span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<footer class="mt-5 text-center text-secondary">© 2025 USLCast</footer>

</body>
</html>
<?php
session_start();

$host = "localhost"; $dbname = "uslcast"; $user = "postgres"; $pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$meet_id = isset($_GET['meet']) ? (int)$_GET['meet'] : (isset($_GET['id'])?(int)$_GET['id']:null);
if (!$meet_id) die("ID de competencia no especificado.");

$stmt = $pdo->prepare("SELECT * FROM meets WHERE id = :id");
$stmt->execute(['id'=>$meet_id]);
$meet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$meet) die("Competencia no encontrada.");

$settings = json_decode($meet['settings'] ?? '{}', true);
if (!($settings['show_link'] ?? false)) {
    die("<h3 style='text-align:center;color:#e60000;'>Esta competencia no está disponible públicamente.</h3>");
}

// Get platforms
$stmt = $pdo->prepare("SELECT * FROM platforms WHERE meet_id = :mid ORDER BY id");
$stmt->execute(['mid'=>$meet_id]);
$platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get lifters grouped by session, platform and flight
$stmt = $pdo->prepare("
    SELECT 
        c.*,
        p.name as platform_name
    FROM competitors c
    LEFT JOIN platforms p ON c.platform_id = p.id
    WHERE c.meet_id = :mid
    ORDER BY c.session, c.platform_id, c.flight, c.lot_number
");
$stmt->execute(['mid'=>$meet_id]);
$all_lifters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by session -> platform -> flight
$roster = [];
$total_lifters = count($all_lifters);
foreach($all_lifters as $lifter) {
    $session = $lifter['session'] ?: 1;
    $platform = $lifter['platform_name'] ?: 'Sin Asignar';
    $flight = $lifter['flight'] ?: 'Sin Asignar';
    
    if (!isset($roster[$session])) $roster[$session] = [];
    if (!isset($roster[$session][$platform])) $roster[$session][$platform] = [];
    if (!isset($roster[$session][$platform][$flight])) $roster[$session][$platform][$flight] = [];
    
    $roster[$session][$platform][$flight][] = $lifter;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lista de Orden — <?=htmlspecialchars($meet['name'])?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/styles.css">
<style>
body{
  background:#000;
  color:#fff;
  margin:0;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  font-size:14px;
  line-height:1.4;
}
.sidebar{
  position:fixed;
  left:-250px;
  top:0;
  width:250px;
  height:100vh;
  background:#0a0a0a;
  border-right:2px solid #e60000;
  transition:left 0.3s;
  z-index:1000;
  overflow-y:auto;
  padding:1rem;
}
.sidebar.open{left:0}
.sidebar-toggle{
  position:fixed;
  top:1rem;
  left:1rem;
  z-index:1001;
  background:#e60000;
  color:#fff;
  border:none;
  padding:0.5rem 1rem;
  border-radius:0.25rem;
  cursor:pointer;
  font-size:1.2rem;
}
.sidebar h5{
  color:#e60000;
  border-bottom:2px solid #e60000;
  padding-bottom:0.5rem;
  margin-bottom:1rem;
  font-size:1rem;
}
.sidebar a{
  color:#fff;
  text-decoration:none;
  display:block;
  padding:0.5rem;
  border-radius:0.25rem;
  margin-bottom:0.25rem;
  font-size:0.9rem;
}
.sidebar a:hover,.sidebar a.active{background:#e60000}
.sidebar .submenu{padding-left:1rem}

.main-content{
  margin-left:1rem;
  padding:1rem;
  max-width:600px;
}

.total-header{
  color:#fff;
  margin-bottom:1.5rem;
  font-size:15px;
  font-weight:normal;
}

.session-group{
  margin-bottom:2rem;
}

.session-title{
  color:#fff;
  margin-bottom:0.5rem;
  font-size:15px;
  font-weight:normal;
}

.platform-group{
  margin-bottom:1rem;
  margin-left:1rem;
}

.platform-title{
  color:#fff;
  margin-bottom:0.5rem;
  font-size:15px;
  font-weight:normal;
}

.flight-group{
  margin-bottom:0.75rem;
  margin-left:2rem;
}

.flight-title{
  color:#fff;
  margin-bottom:0.25rem;
  font-size:15px;
  font-weight:normal;
}

.lifter-list{
  margin-left:3rem;
}

.lifter-item{
  color:#fff;
  margin-bottom:0.15rem;
  font-size:14px;
  font-weight:normal;
}
</style>
</head>
<body>

<button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>

<div class="sidebar" id="sidebar">
  <h5><?=htmlspecialchars($meet['name'])?></h5>
  <a href="liveFeed.php?meet=<?=$meet_id?>">Feed en Vivo</a>
  <a href="results.php?meet=<?=$meet_id?>">Resultados</a>
  <a href="roster.php?meet=<?=$meet_id?>" class="active">Lista de Orden</a>
  
  <?php foreach($platforms as $p): ?>
    <div class="mt-3">
      <strong style="color:#e60000"><?=htmlspecialchars($p['name'])?></strong>
      <div class="submenu">
        <a href="#platform-<?=$p['id']?>-run">Ejecutar</a>
        <a href="#platform-<?=$p['id']?>-board">Tablero</a>
        <a href="#platform-<?=$p['id']?>-display">Pantalla</a>
        <a href="#platform-<?=$p['id']?>-ref-left">Ref - Izquierda</a>
        <a href="#platform-<?=$p['id']?>-ref-head">Ref - Central</a>
        <a href="#platform-<?=$p['id']?>-ref-right">Ref - Derecha</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="main-content">
  <div class="total-header">
    ""
  </div>

  <div class="total-header">
    Total Lifters (<?=$total_lifters?>)
  </div>
  <?php foreach($roster as $session => $platforms_data): ?>
    <div class="session-group">
      <div class="session-title">
        Session <?=$session?> (<?=array_sum(array_map(function($p){return array_sum(array_map('count',$p));}, $platforms_data))?>)
      </div>
      
      <?php foreach($platforms_data as $platform_name => $flights_data): ?>
        <div class="platform-group">
          <div class="platform-title">
            Plataforma <?=$platform_name?> (<?=array_sum(array_map('count', $flights_data))?>)
          </div>
          
          <?php foreach($flights_data as $flight_name => $lifters): ?>
            <div class="flight-group">
              <div class="flight-title">
                Flight <?=$flight_name?> (<?=count($lifters)?>)
              </div>
              
              <div class="lifter-list">
                <?php foreach($lifters as $lifter): ?>
                  <div class="lifter-item">
                    <?=$lifter['lot_number']?> - <?=htmlspecialchars($lifter['name'])?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}

// Cerrar sidebar al hacer clic fuera
document.addEventListener('click', function(e) {
  const sidebar = document.getElementById('sidebar');
  const toggle = document.querySelector('.sidebar-toggle');
  
  if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
    sidebar.classList.remove('open');
  }
});
</script>

</body>
</html>
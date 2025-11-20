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

// Get all divisions
$stmt = $pdo->prepare("SELECT * FROM divisions WHERE meet_id = :mid ORDER BY name");
$stmt->execute(['mid'=>$meet_id]);
$divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to calculate score based on scoring method
function calculateScore($scoring_method, $total, $bodyweight, $gender, $age = null) {
    if ($total <= 0 || !$bodyweight) return 0;
    
    $bw = (float)$bodyweight;
    
    switch($scoring_method) {
        case 'Total':
            return $total;
            
        case 'Wilks':
            if ($gender === 'M') {
                $a = -216.0475144; $b = 16.2606339; $c = -0.002388645;
                $d = -0.00113732; $e = 7.01863E-06; $f = -1.291E-08;
            } else {
                $a = 594.31747775582; $b = -27.23842536447; $c = 0.82112226871;
                $d = -0.00930733913; $e = 4.731582E-05; $f = -9.054E-08;
            }
            $denom = $a + $b*$bw + $c*pow($bw,2) + $d*pow($bw,3) + $e*pow($bw,4) + $f*pow($bw,5);
            return $total * (500 / $denom);
            
        case 'DOTS':
            if ($gender === 'M') {
                $a = -307.75076; $b = 24.0900756; $c = -0.1918759221;
                $d = 0.0007391293; $e = -0.000001093;
            } else {
                $a = -57.96288; $b = 13.6175032; $c = -0.1126655495;
                $d = 0.0005158568; $e = -0.0000010706;
            }
            $denom = $a + $b*$bw + $c*pow($bw,2) + $d*pow($bw,3) + $e*pow($bw,4);
            return $total * (500 / $denom);
            
        case 'IPF Points':
            if ($gender === 'M') {
                $a = 1199.72839; $b = 1025.18162; $c = 0.00921;
            } else {
                $a = 610.32796; $b = 1045.59282; $c = 0.03048;
            }
            return $total * (100 / ($a - $b * exp(-$c * $bw)));
            
        case 'Glossbrenner':
            if ($gender === 'M') {
                $a = -0.000025208; $b = 0.0107295; $c = -1.4499; $d = 130.065;
            } else {
                $a = -0.0000239625; $b = 0.010546; $c = -1.4431; $d = 130.42;
            }
            $coeff = $a*pow($bw,3) + $b*pow($bw,2) + $c*$bw + $d;
            return $total * $coeff;
            
        case 'Schwartz/Malone':
            return $gender === 'M' ? $total * pow($bw, -0.55) : $total * pow($bw, -0.52);
            
        case 'AH':
            return $total * (100 / $bw);
            
        case 'Para DOTS':
            return calculateScore('DOTS', $total, $bodyweight, $gender);
            
        case 'K-Points':
            return $gender === 'M' ? $total / pow($bw, 0.3) : $total / pow($bw, 0.28);
            
        case 'Age Points':
        case 'IPF+Age':
        case 'DOTS+Age':
        case 'Schwartz/Malone+Age':
        case 'Glossbrenner+Age':
            $baseMethod = str_replace('+Age', '', $scoring_method);
            $baseScore = calculateScore($baseMethod, $total, $bodyweight, $gender);
            if ($age) {
                if ($age >= 40 && $age < 50) $ageCoeff = 1.01;
                elseif ($age >= 50 && $age < 60) $ageCoeff = 1.05;
                elseif ($age >= 60 && $age < 70) $ageCoeff = 1.10;
                elseif ($age >= 70) $ageCoeff = 1.20;
                else $ageCoeff = 1.0;
                return $baseScore * $ageCoeff;
            }
            return $baseScore;
            
        default:
            return $total;
    }
}

// Get results by division and weight class
$results_by_division = [];

foreach($divisions as $div) {
    // First, get all competitors in this division with their specific weight class
    $stmt = $pdo->prepare("
        SELECT 
            c.id, 
            c.name, 
            c.team, 
            c.body_weight, 
            c.lot_number, 
            c.gender, 
            c.dob,
            cd.id as competitor_division_id,
            cd.declared_weight_class
        FROM competitors c
        JOIN competitor_divisions cd ON c.id = cd.competitor_id
        WHERE c.meet_id = :mid AND cd.division_id = :did
        ORDER BY cd.declared_weight_class, c.lot_number, c.name
    ");
    $stmt->execute(['mid'=>$meet_id, 'did'=>$div['id']]);
    $competitors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $lifters = [];
    
    foreach($competitors as $comp) {
        // Get all attempts for this competitor
        $stmt = $pdo->prepare("
            SELECT 
                lift_type, 
                attempt_number, 
                weight, 
                success,
                is_record
            FROM attempts
            WHERE competitor_id = :cid
            ORDER BY lift_type, attempt_number
        ");
        $stmt->execute(['cid' => $comp['id']]);
        $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate age
        $age = null;
        if ($comp['dob']) {
            $dob = new DateTime($comp['dob']);
            $now = new DateTime();
            $age = $now->diff($dob)->y;
        }
        
        // Initialize lifter data
        $lifter = [
            'id' => $comp['id'],
            'name' => $comp['name'],
            'team' => $comp['team'],
            'body_weight' => $comp['body_weight'],
            'lot_number' => $comp['lot_number'],
            'gender' => $comp['gender'],
            'declared_weight_class' => $comp['declared_weight_class'] ?: 'Sin Clase',
            'squat1' => null, 'squat1_success' => false,
            'squat2' => null, 'squat2_success' => false,
            'squat3' => null, 'squat3_success' => false,
            'squat4' => null, 'squat4_success' => false,
            'bench1' => null, 'bench1_success' => false,
            'bench2' => null, 'bench2_success' => false,
            'bench3' => null, 'bench3_success' => false,
            'bench4' => null, 'bench4_success' => false,
            'dead1' => null, 'dead1_success' => false,
            'dead2' => null, 'dead2_success' => false,
            'dead3' => null, 'dead3_success' => false,
            'dead4' => null, 'dead4_success' => false,
        ];
        
        // Fill in attempts
        foreach($attempts as $att) {
            $lift = strtolower($att['lift_type']);
            if ($lift === 'squat') $prefix = 'squat';
            elseif ($lift === 'bench') $prefix = 'bench';
            elseif ($lift === 'deadlift') $prefix = 'dead';
            else continue;
            
            $num = $att['attempt_number'];
            if ($num >= 1 && $num <= 4) {
                $lifter[$prefix . $num] = $att['weight'];
                $lifter[$prefix . $num . '_success'] = (bool)$att['success'];
            }
        }
        
        // Calculate best lifts (4th attempt doesn't count for total unless it's the only successful attempt)
        $best_squat = 0;
        for($i=1; $i<=3; $i++) {
            if ($lifter["squat{$i}_success"]) {
                $best_squat = max($best_squat, $lifter["squat{$i}"] ?? 0);
            }
        }
        
        $best_bench = 0;
        for($i=1; $i<=3; $i++) {
            if ($lifter["bench{$i}_success"]) {
                $best_bench = max($best_bench, $lifter["bench{$i}"] ?? 0);
            }
        }
        
        $best_dead = 0;
        for($i=1; $i<=3; $i++) {
            if ($lifter["dead{$i}_success"]) {
                $best_dead = max($best_dead, $lifter["dead{$i}"] ?? 0);
            }
        }
        
        $lifter['best_squat'] = $best_squat;
        $lifter['best_bench'] = $best_bench;
        $lifter['best_dead'] = $best_dead;
        $lifter['total'] = $best_squat + $best_bench + $best_dead;
        
        // Only calculate score if lifter has a valid total
        if ($lifter['total'] > 0) {
            $lifter['score'] = calculateScore(
                $div['scoring_method'], 
                $lifter['total'], 
                $comp['body_weight'], 
                $comp['gender'], 
                $age
            );
        } else {
            $lifter['score'] = 0;
        }
        
        $lifters[] = $lifter;
    }
    
    // Skip division if no lifters
    if (empty($lifters)) continue;
    
    // Group by weight class
    $by_weight_class = [];
    foreach($lifters as $lifter) {
        $wc = $lifter['declared_weight_class'];
        if (!isset($by_weight_class[$wc])) {
            $by_weight_class[$wc] = [];
        }
        $by_weight_class[$wc][] = $lifter;
    }
    
    // Sort each weight class by score and assign places
    foreach($by_weight_class as $wc => &$lifters_in_wc) {
        // Sort by score descending, then by total descending, then by bodyweight ascending
        usort($lifters_in_wc, function($a, $b) {
            if ($b['score'] != $a['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($b['total'] != $a['total']) {
                return $b['total'] <=> $a['total'];
            }
            return $a['body_weight'] <=> $b['body_weight'];
        });
        
        // Assign places
        $place = 1;
        foreach($lifters_in_wc as $idx => &$l) {
            // Only assign place if lifter has a total > 0
            if ($l['total'] > 0) {
                $l['place'] = $place++;
            } else {
                $l['place'] = '-'; // No place for bombed out lifters
            }
        }
    }
    
    // Only add division to results if it has competitors with data
    if (count($by_weight_class) > 0) {
        $results_by_division[$div['id']] = [
            'division' => $div,
            'weight_classes' => $by_weight_class
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resultados — <?=htmlspecialchars($meet['name'])?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/styles.css">
<style>
body{background:#000;color:#fff;margin:0;overflow-x:hidden}
.sidebar{position:fixed;left:-250px;top:0;width:250px;height:100vh;background:#0a0a0a;border-right:2px solid #e60000;transition:left 0.3s;z-index:1000;overflow-y:auto;padding:1rem}
.sidebar.open{left:0}
.sidebar-toggle{position:fixed;top:1rem;left:1rem;z-index:1001;background:#e60000;color:#fff;border:none;padding:0.5rem 1rem;border-radius:0.25rem;cursor:pointer;font-size:1.2rem}
.sidebar h5{color:#e60000;border-bottom:2px solid #e60000;padding-bottom:0.5rem;margin-bottom:1rem}
.sidebar a{color:#fff;text-decoration:none;display:block;padding:0.5rem;border-radius:0.25rem;margin-bottom:0.25rem}
.sidebar a:hover,.sidebar a.active{background:#e60000}
.sidebar .submenu{padding-left:1rem}
.results-table{font-size:0.8rem;margin-bottom:3rem}
.results-table th{background:#1a1a1a;color:#e60000;padding:0.5rem;text-align:center;position:sticky;top:0;font-size:0.75rem}
.results-table td{padding:0.3rem 0.2rem;text-align:center;font-size:0.8rem}
.success{background:#0a4d0a !important;color:#fff}
.failed{background:#4d0a0a !important;color:#fff}
.record{background:#1a1a4d !important;color:#ffd700;font-weight:bold}
.division-header{background:#e60000;color:#fff;padding:1rem;margin:2rem 0 0.5rem 0;border-radius:0.5rem}
.weight-class-header{background:#333;color:#fff;padding:0.75rem;margin:1rem 0 0.5rem 0;border-radius:0.25rem}
.bombed-out{opacity:0.5;text-decoration:line-through}
@media print {
  .sidebar, .sidebar-toggle, .btn {display:none !important}
  body{background:#fff;color:#000}
  .results-table th{background:#ddd !important;color:#000 !important}
  .results-table td{color:#000}
}
</style>
</head>
<body>

<button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>

<div class="sidebar" id="sidebar">
  <h5><?=htmlspecialchars($meet['name'])?></h5>
  <a href="liveFeed.php?meet=<?=$meet_id?>">Feed en Vivo</a>
  <a href="results.php?meet=<?=$meet_id?>" class="active">Resultados</a>
  <a href="roster.php?meet=<?=$meet_id?>">Lista de Orden</a>
  
  <?php foreach($platforms as $p): ?>
    <div class="mt-3">
      <strong style="color:#e60000"><?=htmlspecialchars($p['name'])?></strong>
      <div class="submenu">
        <a href="run.php?platform=<?=$p['id']?>">Ejecutar</a>
        <a href="board.php?platform=<?=$p['id']?>">Tablero</a>
        <a href="display.php?platform=<?=$p['id']?>">Pantalla</a>
        <a href="referee.php?platform=<?=$p['id']?>&position=left">Ref - Izquierda</a>
        <a href="referee.php?platform=<?=$p['id']?>&position=head">Ref - Central</a>
        <a href="referee.php?platform=<?=$p['id']?>&position=right">Ref - Derecha</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div style="margin-left:1rem;padding:2rem">
  <h1 class="text-danger mb-4">Resultados de Competencia</h1>
  
  <div class="mb-3">
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Imprimir</button>
    <button class="btn btn-secondary" onclick="exportResults()">📥 Exportar</button>
  </div>
  
  <?php if (empty($results_by_division)): ?>
    <div class="alert alert-warning">
      <p>No hay resultados disponibles aún. Los competidores aún no han completado sus intentos.</p>
    </div>
  <?php endif; ?>
  
  <?php foreach($results_by_division as $div_data): ?>
    <div class="division-header">
      <h3><?=htmlspecialchars($div_data['division']['name'])?></h3>
      <small>Sistema de Puntuación: <?=htmlspecialchars($div_data['division']['scoring_method'] ?: 'Total')?></small>
    </div>
    
    <?php foreach($div_data['weight_classes'] as $wc_name => $lifters): ?>
      <div class="weight-class-header">
        <h5>Clase de Peso: <?=htmlspecialchars($wc_name)?> (<?=count($lifters)?> competidores)</h5>
      </div>
      
      <div class="table-responsive">
        <table class="table table-dark table-bordered results-table">
          <thead>
            <tr>
              <th rowspan="2">Lugar</th>
              <th rowspan="2">Nombre</th>
              <th rowspan="2">Equipo</th>
              <th rowspan="2">Peso<br>Corp.</th>
              <th colspan="4">Sentadilla</th>
              <th colspan="4">Press Banca</th>
              <th colspan="4">Peso Muerto</th>
              <th rowspan="2">Total</th>
              <th rowspan="2">Puntos</th>
            </tr>
            <tr>
              <th>1</th><th>2</th><th>3</th><th>4*</th>
              <th>1</th><th>2</th><th>3</th><th>4*</th>
              <th>1</th><th>2</th><th>3</th><th>4*</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($lifters as $lifter): 
              $bombed = $lifter['total'] == 0;
              $rowClass = $bombed ? 'bombed-out' : '';
            ?>
              <tr class="<?=$rowClass?>">
                <td><strong><?=$lifter['place']?></strong></td>
                <td class="text-start"><?=htmlspecialchars($lifter['name'])?></td>
                <td><?=htmlspecialchars($lifter['team']??'')?></td>
                <td><?=$lifter['body_weight'] ?: '-'?></td>
                
                <?php for($i=1; $i<=4; $i++): 
                  $weight = $lifter["squat{$i}"];
                  $success = $lifter["squat{$i}_success"];
                  $class = '';
                  if ($weight !== null) {
                    $class = $success ? 'success' : 'failed';
                    if ($i == 4 && $success) $class = 'record';
                  }
                ?>
                  <td class="<?=$class?>"><?=$weight !== null ? $weight : '-'?></td>
                <?php endfor; ?>
                
                <?php for($i=1; $i<=4; $i++): 
                  $weight = $lifter["bench{$i}"];
                  $success = $lifter["bench{$i}_success"];
                  $class = '';
                  if ($weight !== null) {
                    $class = $success ? 'success' : 'failed';
                    if ($i == 4 && $success) $class = 'record';
                  }
                ?>
                  <td class="<?=$class?>"><?=$weight !== null ? $weight : '-'?></td>
                <?php endfor; ?>
                
                <?php for($i=1; $i<=4; $i++): 
                  $weight = $lifter["dead{$i}"];
                  $success = $lifter["dead{$i}_success"];
                  $class = '';
                  if ($weight !== null) {
                    $class = $success ? 'success' : 'failed';
                    if ($i == 4 && $success) $class = 'record';
                  }
                ?>
                  <td class="<?=$class?>"><?=$weight !== null ? $weight : '-'?></td>
                <?php endfor; ?>
                
                <td><strong><?=$lifter['total'] > 0 ? $lifter['total'] : 'BOMB'?></strong></td>
                <td><?=$lifter['score'] > 0 ? number_format($lifter['score'], 2) : '-'?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="text-muted small">* Intento 4 = Intento de récord (no cuenta para el total)</p>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}

function exportResults() {
  window.location.href = 'export_results.php?meet=<?=$meet_id?>';
}
</script>

</body>
</html>
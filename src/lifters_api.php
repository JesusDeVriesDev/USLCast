<?php
session_start();
$sessionUserId = $_SESSION['user_id'] ?? $_SESSION['session_user_id'] ?? null;
if (!$sessionUserId) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit; }

$host = "localhost"; $dbname = "uslcast"; $user = "postgres"; $pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? null;

header('Content-Type: application/json');

// check role
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute(['id'=>$sessionUserId]); 
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || $u['role'] != 2) { 
  http_response_code(403); 
  echo json_encode(['ok'=>false,'error'=>'Forbidden']); 
  exit; 
}

function competitor_belongs_to_organizer($pdo, $competitor_id, $user_id) {
  $st = $pdo->prepare("SELECT m.organizer_id FROM competitors c JOIN meets m ON c.meet_id = m.id WHERE c.id=:id");
  $st->execute(['id'=>$competitor_id]);
  $org = $st->fetchColumn();
  return $org && $org == $user_id;
}

function meet_belongs_to_organizer($pdo, $meet_id, $user_id) {
  $st = $pdo->prepare("SELECT id FROM meets WHERE id=:id AND organizer_id=:org");
  $st->execute(['id'=>$meet_id,'org'=>$user_id]);
  return (bool)$st->fetchColumn();
}

try {
  if ($action === 'update') {
    $id = (int)$input['id'];
    if (!competitor_belongs_to_organizer($pdo, $id, $sessionUserId)) {
      throw new Exception('No autorizado.');
    }
    
    $allowed = ['team','lot_number','platform_id','session','flight','dob','gender','body_weight','rack_height'];
    $sets = [];
    $params = ['id'=>$id];
    
    foreach($allowed as $f) {
      if (isset($input[$f])) {
        if ($f === 'rack_height') {
          $sets[] = "$f = :$f";
          $params[$f] = is_array($input[$f]) ? json_encode($input[$f]) : $input[$f];
        } else {
          $sets[] = "$f = :$f";
          $params[$f] = $input[$f] === '' ? null : $input[$f];
        }
      }
    }
    
    if (empty($sets)) throw new Exception('Nada que actualizar.');
    
    // Add cast for rack_height
    $sqlParts = [];
    foreach($sets as $set) {
      if (strpos($set, 'rack_height') !== false) {
        $sqlParts[] = "rack_height = :rack_height::jsonb";
      } else {
        $sqlParts[] = $set;
      }
    }
    
    $sql = "UPDATE competitors SET " . implode(',', $sqlParts) . " WHERE id=:id";
    $pdo->prepare($sql)->execute($params);
    
    echo json_encode(['ok'=>true]);
    exit;
  }
  
  if ($action === 'delete') {
    $id = (int)$input['id'];
    if (!competitor_belongs_to_organizer($pdo, $id, $sessionUserId)) {
      throw new Exception('No autorizado.');
    }
    
    $pdo->prepare("DELETE FROM competitors WHERE id=:id")->execute(['id'=>$id]);
    echo json_encode(['ok'=>true]);
    exit;
  }
  
  if ($action === 'generate_lots') {
    $meet_id = (int)$input['meet_id'];
    if (!meet_belongs_to_organizer($pdo, $meet_id, $sessionUserId)) {
      throw new Exception('Meet inválido.');
    }
    
    // Get all lifters ordered by name
    $stmt = $pdo->prepare("SELECT id FROM competitors WHERE meet_id=:mid ORDER BY name");
    $stmt->execute(['mid'=>$meet_id]);
    $lifters = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Assign lot numbers sequentially
    $update = $pdo->prepare("UPDATE competitors SET lot_number=:lot WHERE id=:id");
    $lot = 100; // Start at 100
    foreach($lifters as $lifter_id) {
      $update->execute(['lot'=>$lot,'id'=>$lifter_id]);
      $lot++;
    }
    
    echo json_encode(['ok'=>true,'assigned'=>count($lifters)]);
    exit;
  }
  
  if ($action === 'get_divisions') {
    $lifter_id = (int)$input['lifter_id'];
    if (!competitor_belongs_to_organizer($pdo, $lifter_id, $sessionUserId)) {
      throw new Exception('No autorizado.');
    }
    
    $stmt = $pdo->prepare("
      SELECT 
        cd.id, 
        cd.raw_or_equipped, 
        cd.declared_weight_class, 
        d.name as division_name,
        d.gender as division_gender,
        d.type as division_type
      FROM competitor_divisions cd
      JOIN divisions d ON cd.division_id = d.id
      WHERE cd.competitor_id = :cid
      ORDER BY d.name, cd.declared_weight_class
    ");
    $stmt->execute(['cid'=>$lifter_id]);
    $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['ok'=>true,'data'=>$divisions]);
    exit;
  }
  
  if ($action === 'add_division') {
    $lifter_id = (int)$input['lifter_id'];
    if (!competitor_belongs_to_organizer($pdo, $lifter_id, $sessionUserId)) {
      throw new Exception('No autorizado.');
    }
    
    $division_id = (int)$input['division_id'];
    $raw_or_eq = $input['raw_or_equipped'];
    $weight_class = $input['declared_weight_class'];
    
    // CAMBIO IMPORTANTE: Ahora permitimos la MISMA división con DIFERENTE clase de peso
    // Solo verificamos si existe la combinación EXACTA de división + clase de peso
    $chk = $pdo->prepare("
      SELECT id FROM competitor_divisions 
      WHERE competitor_id=:cid 
      AND division_id=:did 
      AND declared_weight_class=:wc
    ");
    $chk->execute(['cid'=>$lifter_id,'did'=>$division_id,'wc'=>$weight_class]);
    if ($chk->fetchColumn()) {
      throw new Exception('Esta combinación de división y clase de peso ya está asignada.');
    }
    
    $stmt = $pdo->prepare("INSERT INTO competitor_divisions (competitor_id, division_id, raw_or_equipped, declared_weight_class) VALUES (:cid,:did,:roe,:dwc)");
    $stmt->execute([
      'cid'=>$lifter_id,
      'did'=>$division_id,
      'roe'=>$raw_or_eq,
      'dwc'=>$weight_class
    ]);
    
    echo json_encode(['ok'=>true]);
    exit;
  }
  
  if ($action === 'remove_division') {
    $cd_id = (int)$input['competitor_division_id'];
    
    // Verify ownership
    $st = $pdo->prepare("
      SELECT m.organizer_id 
      FROM competitor_divisions cd 
      JOIN competitors c ON cd.competitor_id = c.id 
      JOIN meets m ON c.meet_id = m.id 
      WHERE cd.id=:id
    ");
    $st->execute(['id'=>$cd_id]);
    $org = $st->fetchColumn();
    if (!$org || $org != $sessionUserId) {
      throw new Exception('No autorizado.');
    }
    
    $pdo->prepare("DELETE FROM competitor_divisions WHERE id=:id")->execute(['id'=>$cd_id]);
    echo json_encode(['ok'=>true]);
    exit;
  }
  
  throw new Exception('Acción inválida.');
  
} catch(Exception $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
<?php
// divisions_api_inline.php
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
$stmt->execute(['id'=>$sessionUserId]); $u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || $u['role'] != 2) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

function belongs_to_organizer($pdo,$meet_id,$user_id){
  $st = $pdo->prepare("SELECT id FROM meets WHERE id=:id AND organizer_id=:org");
  $st->execute(['id'=>$meet_id,'org'=>$user_id]);
  return (bool)$st->fetchColumn();
}

function division_belongs_to_organizer($pdo,$division_id,$user_id){
  $st = $pdo->prepare("SELECT m.organizer_id FROM divisions d JOIN meets m ON d.meet_id = m.id WHERE d.id=:id");
  $st->execute(['id'=>$division_id]);
  $org = $st->fetchColumn();
  return $org && $org == $user_id;
}

try {
  if ($action === 'create') {
    $meet_id = (int)$input['meet_id'];
    if (!belongs_to_organizer($pdo,$meet_id,$sessionUserId)) throw new Exception('Meet inválido.');
    $name = trim($input['name'] ?? 'Nueva División');
    $gender = $input['gender'] ?? 'M';
    $type = $input['type'] ?? 'Raw';
    $scoring_method = $input['scoring_method'] ?? 'Total';
    $code = $input['division_code'] ?? null;
    $hidden = (isset($input['hidden_on_board']) && $input['hidden_on_board'] == 1) ? 'true' : 'false';
    $competition_type = $input['competition_type'] ?? 'Powerlifting';
    $lifts = json_encode($input['lifts'] ?? ['squat'=>true,'bench'=>true,'deadlift'=>true]);

    $stmt = $pdo->prepare("INSERT INTO divisions (meet_id,name,gender,type,scoring_method,min_weight,max_weight,division_code,hidden_on_board,competition_type,lifts) VALUES
      (:meet_id,:name,:gender,:type,:scoring_method,NULL,NULL,:code,:hidden::boolean,:comp_type,:lifts::jsonb) RETURNING id");
    $stmt->execute([
      'meet_id'=>$meet_id,'name'=>$name,'gender'=>$gender,'type'=>$type,'scoring_method'=>$scoring_method,
      'code'=>$code,'hidden'=>$hidden,'comp_type'=>$competition_type,'lifts'=>$lifts
    ]);
    $id = $stmt->fetchColumn();
    echo json_encode(['ok'=>true,'id'=>$id]); exit;
  }

  if ($action === 'update') {
    $id = (int)$input['id'];
    $meet_id = (int)$input['meet_id'];
    if (!belongs_to_organizer($pdo,$meet_id,$sessionUserId)) throw new Exception('Meet inválido.');
    $st = $pdo->prepare("SELECT meet_id FROM divisions WHERE id=:id");
    $st->execute(['id'=>$id]); $mid = $st->fetchColumn();
    if (!$mid || $mid != $meet_id) throw new Exception('Division no pertenece al meet.');

    $fields = ['name','gender','type','scoring_method','division_code','hidden_on_board','competition_type','lifts'];
    $sets=[]; $params=['id'=>$id];
    foreach($fields as $f) if (isset($input[$f])) {
      if ($f === 'hidden_on_board') {
        $sets[] = "$f = :$f";
        $params[$f] = ($input[$f] == 1) ? 'true' : 'false';
      }
      else if ($f === 'lifts') {
        $sets[] = "$f = :$f";
        $params[$f] = is_array($input[$f]) ? json_encode($input[$f]) : $input[$f];
      }
      else { $sets[]="$f = :$f"; $params[$f] = $input[$f]; }
    }
    if (!$sets) throw new Exception('Nada que actualizar.');
    
    $sqlParts = [];
    foreach($sets as $set) {
      if (strpos($set, 'hidden_on_board') !== false) {
        $sqlParts[] = "hidden_on_board = :hidden_on_board::boolean";
      } else if (strpos($set, 'lifts') !== false) {
        $sqlParts[] = "lifts = :lifts::jsonb";
      } else {
        $sqlParts[] = $set;
      }
    }
    
    $sql = "UPDATE divisions SET " . implode(',', $sqlParts) . " WHERE id=:id";
    $pdo->prepare($sql)->execute($params);
    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'delete') {
    $id = (int)$input['id'];
    $st = $pdo->prepare("SELECT m.organizer_id FROM divisions d JOIN meets m ON d.meet_id = m.id WHERE d.id=:id");
    $st->execute(['id'=>$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['organizer_id'] != $sessionUserId) throw new Exception('No autorizado o división inexistente.');
    $pdo->prepare("DELETE FROM divisions WHERE id=:id")->execute(['id'=>$id]);
    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'generate') {
    $meet_id = (int)$input['meet_id'];
    if (!belongs_to_organizer($pdo,$meet_id,$sessionUserId)) throw new Exception('Meet inválido.');
    $tipo = $input['tipo'] ?? 'Powerlifting';
    $competition_type = $input['competition_type'] ?? $tipo;
    $generos = $input['generos'] ?? ['M','F'];
    $refs = $input['refs'] ?? ['Raw','Equipped'];
    $ages = $input['ages'] ?? [];
    if (empty($generos) || empty($refs) || empty($ages)) throw new Exception('Faltan generos/ref/edades.');
    $score = $input['score'] ?? 'Total';

    // Determinar lifts según el tipo
    $lifts = ['squat'=>true,'bench'=>true,'deadlift'=>true];
    if ($tipo === 'Push/Pull') {
      $lifts = ['squat'=>false,'bench'=>true,'deadlift'=>true];
    } else if ($tipo === 'Bench') {
      $lifts = ['squat'=>false,'bench'=>true,'deadlift'=>false];
    } else if ($tipo === 'Deadlift') {
      $lifts = ['squat'=>false,'bench'=>false,'deadlift'=>true];
    }
    $lifts_json = json_encode($lifts);

    $insert = $pdo->prepare("INSERT INTO divisions (meet_id,name,gender,type,scoring_method,min_weight,max_weight,division_code,hidden_on_board,competition_type,lifts) VALUES
      (:meet_id,:name,:gender,:type,:score, NULL, NULL, NULL, false, :comp_type, :lifts::jsonb)");
    $count=0;
    foreach($ages as $age) {
      foreach($generos as $g) {
        foreach($refs as $r) {
          $name = trim("$age - $g - $r");
          $chk = $pdo->prepare("SELECT id FROM divisions WHERE meet_id=:m AND name=:n LIMIT 1");
          $chk->execute(['m'=>$meet_id,'n'=>$name]);
          if ($chk->fetchColumn()) continue;
          $insert->execute([
            'meet_id'=>$meet_id,'name'=>$name,'gender'=>$g,'type'=>$r,'score'=>$score,
            'comp_type'=>$competition_type,'lifts'=>$lifts_json
          ]);
          $count++;
        }
      }
    }
    echo json_encode(['ok'=>true,'created'=>$count]); exit;
  }

  if ($action === 'get_weight_classes') {
    $division_id = (int)$input['division_id'];
    if (!division_belongs_to_organizer($pdo,$division_id,$sessionUserId)) throw new Exception('División inválida.');
    
    $st = $pdo->prepare("SELECT * FROM weight_classes WHERE division_id=:did ORDER BY id");
    $st->execute(['did'=>$division_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'data'=>$rows]); exit;
  }

  if ($action === 'save_weight_classes') {
    $division_id = (int)$input['division_id'];
    if (!division_belongs_to_organizer($pdo,$division_id,$sessionUserId)) throw new Exception('División inválida.');
    
    $weight_classes = $input['weight_classes'] ?? [];
    
    // Eliminar todas las weight classes existentes de esta división
    $pdo->prepare("DELETE FROM weight_classes WHERE division_id=:did")->execute(['did'=>$division_id]);
    
    // Insertar las nuevas
    $insert = $pdo->prepare("INSERT INTO weight_classes (division_id,name,min_weight,max_weight,division_code) 
      VALUES (:did,:name,:min,:max,:code)");
    
    foreach($weight_classes as $wc) {
      if (empty($wc['name'])) continue; // skip empty entries
      $insert->execute([
        'did'=>$division_id,
        'name'=>$wc['name'],
        'min'=>!empty($wc['min_weight']) ? (float)$wc['min_weight'] : null,
        'max'=>!empty($wc['max_weight']) ? (float)$wc['max_weight'] : null,
        'code'=>$wc['division_code'] ?? null
      ]);
    }
    
    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'list') {
    $meet_id = (int)$input['meet_id'];
    if (!belongs_to_organizer($pdo,$meet_id,$sessionUserId)) throw new Exception('Meet inválido.');
    $st = $pdo->prepare("SELECT * FROM divisions WHERE meet_id=:m ORDER BY id");
    $st->execute(['m'=>$meet_id]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'data'=>$rows]); exit;
  }

  throw new Exception('Acción inválida.');
} catch(Exception $e){
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
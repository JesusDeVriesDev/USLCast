<?php
// run.php - filas por levantador, UI estilo B, timer manual, actions por intento
session_start();
header('Content-Type: text/html; charset=utf-8');

$host = "localhost"; $dbname = "uslcast"; $user = "postgres"; $pass = "unicesmag";
$dsn = "pgsql:host=$host;dbname=$dbname";
try { $pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch(Exception $e){ die("DB error: ".$e->getMessage()); }

function json_out($v){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($v); exit; }
function safe($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function now_ts(){ return time(); }

// --- Auth: require session + role >= 2 (referee)
if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'Sesión no iniciada']);
    http_response_code(403); die("<h3>403 - Debes iniciar sesión</h3>");
}
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute(['id'=>$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || $user['role'] < 2) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'Acceso denegado']);
    http_response_code(403); die("<h3>403 - Acceso denegado</h3>");
}

// --- meet and optional platform filter
$meet_id = isset($_REQUEST['meet']) ? (int)$_REQUEST['meet'] : null;
$platform_filter = isset($_REQUEST['platform']) && $_REQUEST['platform'] !== '' ? $_REQUEST['platform'] : null;

if (!$meet_id) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'meet required']);
    die("Use ?meet=ID");
}

// check meet
$sth = $pdo->prepare("SELECT * FROM meets WHERE id = :id");
$sth->execute(['id'=>$meet_id]);
$meet = $sth->fetch(PDO::FETCH_ASSOC);
if (!$meet) { if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'meet not found']); die("Meet not found"); }
$settings = json_decode($meet['settings'] ?? '{}', true);
if (!is_array($settings)) $settings = [];

// ---------- AJAX endpoints ----------
if (isset($_GET['ajax'])) {
    $action = $_GET['ajax'];

    // Helper: load competitors for meet (filtered by platform if provided)
    $load_competitors = function() use ($pdo, $meet_id, $platform_filter) {
        $params = ['meet'=>$meet_id];
        $wherePlatform = '';
        if ($platform_filter !== null && $platform_filter !== '') {
            // Try as numeric ID first
            if (is_numeric($platform_filter)) {
                $wherePlatform = " AND c.platform_id = :plat";
                $params['plat'] = (int)$platform_filter;
            } else {
                // Try as platform name
                $wherePlatform = " AND p.name = :platname";
                $params['platname'] = $platform_filter;
            }
        }
        $sql = "SELECT c.* FROM competitors c
                LEFT JOIN platforms p ON p.id = c.platform_id
                WHERE c.meet_id = :meet $wherePlatform
                ORDER BY COALESCE(c.session::int,0), COALESCE(c.flight,''), COALESCE(c.lot_number::int,0), c.name";
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    };

    // Helper: attempts index
    $load_attempts_index = function($comp_ids) use ($pdo) {
        if (empty($comp_ids)) return [];
        $in = implode(',', array_fill(0,count($comp_ids),'?'));
        $sql = "SELECT a.*, a.id as attempt_id FROM attempts a WHERE a.competitor_id IN ($in)";
        $q = $pdo->prepare($sql);
        $q->execute($comp_ids);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $idx = [];
        foreach($rows as $r) {
            $cid = $r['competitor_id'];
            $lift = $r['lift_type'];
            $no = (int)$r['attempt_number'];
            $idx[$cid][$lift][$no] = $r;
            // ensure referee_calls decoded
            $idx[$cid][$lift][$no]['referee_calls'] = json_decode($r['referee_calls'] ?? '[]', true);
        }
        return $idx;
    };

    // Build per-competitor structure with arrays for squat/bench/deadlift
    if ($action === 'state') {
        $comps = $load_competitors();
        $comp_ids = array_map(function($c){ return $c['id']; }, $comps);
        $attempts_idx = $load_attempts_index($comp_ids);

        // Build competitors array
        $competitors = [];
        foreach ($comps as $c) {
            $cid = $c['id'];
            $attempts_json = json_decode($c['attempts'] ?? '{}', true) ?: [];
            // helper to get candidate weight for (lift,attempt_number)
            $get_open = function($lift,$no) use ($attempts_idx,$cid,$attempts_json) {
                // DB attempt overrides
                if (isset($attempts_idx[$cid][$lift][$no])) {
                    return $attempts_idx[$cid][$lift][$no]['weight'] === null ? null : (float)$attempts_idx[$cid][$lift][$no]['weight'];
                }
                // JSON openings
                $lk = strtolower($lift);
                if (isset($attempts_json[$lk]) && isset($attempts_json[$lk][$no])) {
                    $v = $attempts_json[$lk][$no];
                    return $v === null ? null : (float)$v;
                }
                return null;
            };

            // fill arrays
            $s = [];
            $b = [];
            $d = [];
            for ($i=1;$i<=4;$i++){
                $s[] = ['exists' => isset($attempts_idx[$cid]['Squat'][$i]), 'data' => $attempts_idx[$cid]['Squat'][$i] ?? null, 'weight' => $get_open('Squat',$i), 'attempt_number'=>$i];
                $b[] = ['exists' => isset($attempts_idx[$cid]['Bench'][$i]), 'data' => $attempts_idx[$cid]['Bench'][$i] ?? null, 'weight' => $get_open('Bench',$i), 'attempt_number'=>$i];
                $d[] = ['exists' => isset($attempts_idx[$cid]['Deadlift'][$i]), 'data' => $attempts_idx[$cid]['Deadlift'][$i] ?? null, 'weight' => $get_open('Deadlift',$i), 'attempt_number'=>$i];
            }

            $competitors[] = [
                'id'=>$cid,
                'name'=>$c['name'],
                'lot_number'=>$c['lot_number'],
                'session'=>$c['session'],
                'flight'=>$c['flight'],
                'body_weight'=>$c['body_weight'],
                'platform_id'=>$c['platform_id'],
                'divisions' => [] ,
                'squats'=>$s,
                'bench'=>$b,
                'deadlift'=>$d
            ];
        }

        // ORDERING: Dynamic ordering based on NEXT PENDING attempt weight
        // Group by session -> flight
        $groups = [];
        foreach ($competitors as $comp) {
            $sess = $comp['session'] ?? 0;
            $fl = $comp['flight'] ?? '';
            $groups[$sess][$fl][] = $comp;
        }
        ksort($groups, SORT_NUMERIC);
        
        // Determine current lift phase by majority
        $phase_votes = ['Squat'=>0, 'Bench'=>0, 'Deadlift'=>0];
        
        foreach ($competitors as $comp) {
            // Find next pending attempt
            foreach (['squats'=>'Squat', 'bench'=>'Bench', 'deadlift'=>'Deadlift'] as $key=>$lift) {
                foreach ($comp[$key] as $at) {
                    if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                        $phase_votes[$lift]++;
                        break 2;
                    }
                }
            }
        }
        
        // Determine current phase
        $current_lift = 'Squat';
        if ($phase_votes['Deadlift'] > 0) $current_lift = 'Deadlift';
        elseif ($phase_votes['Bench'] > 0) $current_lift = 'Bench';
        
        // For each session and flight, sort competitors by their NEXT PENDING attempt weight
        $ordered = [];
        foreach ($groups as $sess => $fls) {
            ksort($fls, SORT_STRING);
            foreach ($fls as $fl => $list) {
                usort($list, function($a, $b) use ($current_lift) {
                    $lift_key_map = ['Squat'=>'squats', 'Bench'=>'bench', 'Deadlift'=>'deadlift'];
                    $key = $lift_key_map[$current_lift];
                    
                    // Get next PENDING attempt weight (success=null) for current lift
                    $a_next_weight = null;
                    $b_next_weight = null;
                    
                    foreach ($a[$key] as $at) {
                        if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                            $a_next_weight = $at['weight'];
                            break;
                        }
                    }
                    
                    foreach ($b[$key] as $at) {
                        if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                            $b_next_weight = $at['weight'];
                            break;
                        }
                    }
                    
                    // Sort by next attempt weight ASC (nulls last = finished lifters go to end)
                    if ($a_next_weight === null && $b_next_weight !== null) return 1;
                    if ($a_next_weight !== null && $b_next_weight === null) return -1;
                    if ($a_next_weight !== null && $b_next_weight !== null) {
                        if ($a_next_weight != $b_next_weight) return ($a_next_weight < $b_next_weight) ? -1 : 1;
                    }
                    
                    // Tie-breaker: body weight asc
                    $abw = $a['body_weight'] === null ? 999999 : (float)$a['body_weight'];
                    $bbw = $b['body_weight'] === null ? 999999 : (float)$b['body_weight'];
                    if ($abw != $bbw) return ($abw < $bbw) ? -1 : 1;
                    
                    // lot_number
                    $al = $a['lot_number'] === null ? 999999 : (int)$a['lot_number'];
                    $bl = $b['lot_number'] === null ? 999999 : (int)$b['lot_number'];
                    if ($al != $bl) return ($al < $bl) ? -1 : 1;
                    
                    return strcmp($a['name'], $b['name']);
                });
                foreach ($list as $c) $ordered[] = $c;
            }
        }

        // Finally return state: ordered competitors, current_attempt, timer
        $current_attempt = $settings['current_attempt'] ?? null;
        $timer = $settings['timer'] ?? ['running'=>false,'started_at'=>null,'duration'=>60];
        json_out(['ok'=>true,'meet'=>['id'=>$meet_id,'name'=>$meet['name']],'competitors'=>$ordered,'current_attempt'=>$current_attempt,'timer'=>$timer]);
    }

    // ---------- set_weight ----------
    if ($action === 'set_weight' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null;
        $weight = isset($data['weight']) && $data['weight'] !== '' ? (float)$data['weight'] : null;
        $lift_type = $data['lift_type'] ?? null;
        $attempt_number = isset($data['attempt_number']) ? (int)$data['attempt_number'] : null;
        $competitor_id = isset($data['competitor_id']) ? (int)$data['competitor_id'] : null;

        if ($attempt_id === null) json_out(['ok'=>false,'error'=>'attempt_id required']);

        if ((int)$attempt_id <= 0) {
            // create attempt
            if (!$competitor_id || !$lift_type || !$attempt_number) json_out(['ok'=>false,'error'=>'missing metadata']);
            $ins = $pdo->prepare("INSERT INTO attempts (competitor_id,lift_type,attempt_number,weight,success,created_at) VALUES (:cid,:lift,:no,:w,NULL,now()) RETURNING id");
            $ins->execute(['cid'=>$competitor_id,'lift'=>$lift_type,'no'=>$attempt_number,'w'=>$weight]);
            $newid = (int)$ins->fetchColumn();
            if (($settings['current_attempt'] ?? null) == $attempt_id) {
                $settings['current_attempt'] = $newid;
                $pdo->prepare("UPDATE meets SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$meet_id]);
            }
            json_out(['ok'=>true,'created_id'=>$newid]);
        } else {
            // update existing
            $pdo->prepare("UPDATE attempts SET weight = :w WHERE id = :id")->execute(['w'=>$weight,'id'=>$attempt_id]);
            json_out(['ok'=>true]);
        }
    }

    // ---------- set_current (NO auto-advance) ----------
    if ($action === 'set_current' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null;
        if ($attempt_id === null) json_out(['ok'=>false,'error'=>'attempt_id required']);
        
        // Simply set the current attempt, do NOT advance
        $settings['current_attempt'] = $attempt_id;
        $settings['timer'] = ['running'=>false,'started_at'=>null,'duration'=>60];
        $pdo->prepare("UPDATE meets SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$meet_id]);
        json_out(['ok'=>true]);
    }

    // ---------- mark (good/bad) with AUTO-ADVANCE ----------
    if ($action === 'mark' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null;
        $result = $data['result'] ?? null; // 'good' or 'bad'
        if ($attempt_id === null || !in_array($result,['good','bad'])) json_out(['ok'=>false,'error'=>'invalid payload']);
        $success = $result === 'good' ? 1 : 0;

        if ((int)$attempt_id <= 0) {
            // create row then set success
            $competitor_id = $data['competitor_id'] ?? null;
            $lift_type = $data['lift_type'] ?? null;
            $attempt_number = isset($data['attempt_number']) ? (int)$data['attempt_number'] : null;
            $weight = isset($data['weight']) && $data['weight'] !== '' ? (float)$data['weight'] : null;
            if (!$competitor_id || !$lift_type || !$attempt_number) json_out(['ok'=>false,'error'=>'missing metadata']);
            $ins = $pdo->prepare("INSERT INTO attempts (competitor_id,lift_type,attempt_number,weight,success,created_at) VALUES (:cid,:lift,:no,:w,:s,now()) RETURNING id");
            $ins->execute(['cid'=>$competitor_id,'lift'=>$lift_type,'no'=>$attempt_number,'w'=>$weight,'s'=>$success]);
            $newid = (int)$ins->fetchColumn();
            if (($settings['current_attempt'] ?? null) == $attempt_id) {
                $settings['current_attempt'] = $newid;
            }
        } else {
            // update existing attempt success
            $pdo->prepare("UPDATE attempts SET success = :s WHERE id = :id")->execute(['s'=>$success,'id'=>$attempt_id]);
        }
        
        // NOW AUTO-ADVANCE: Find next lifter with lowest pending weight
        $comps = $load_competitors();
        $comp_ids = array_map(function($c){ return $c['id']; }, $comps);
        $attempts_idx = $load_attempts_index($comp_ids);
        
        // Rebuild competitors with attempts
        $competitors = [];
        foreach ($comps as $c) {
            $cid = $c['id'];
            $attempts_json = json_decode($c['attempts'] ?? '{}', true) ?: [];
            $get_open = function($lift,$no) use ($attempts_idx,$cid,$attempts_json) {
                if (isset($attempts_idx[$cid][$lift][$no])) {
                    return $attempts_idx[$cid][$lift][$no]['weight'] === null ? null : (float)$attempts_idx[$cid][$lift][$no]['weight'];
                }
                $lk = strtolower($lift);
                if (isset($attempts_json[$lk]) && isset($attempts_json[$lk][$no])) {
                    $v = $attempts_json[$lk][$no];
                    return $v === null ? null : (float)$v;
                }
                return null;
            };
            
            $s = []; $b = []; $d = [];
            for ($i=1;$i<=4;$i++){
                $s[] = ['exists' => isset($attempts_idx[$cid]['Squat'][$i]), 'data' => $attempts_idx[$cid]['Squat'][$i] ?? null, 'weight' => $get_open('Squat',$i), 'attempt_number'=>$i];
                $b[] = ['exists' => isset($attempts_idx[$cid]['Bench'][$i]), 'data' => $attempts_idx[$cid]['Bench'][$i] ?? null, 'weight' => $get_open('Bench',$i), 'attempt_number'=>$i];
                $d[] = ['exists' => isset($attempts_idx[$cid]['Deadlift'][$i]), 'data' => $attempts_idx[$cid]['Deadlift'][$i] ?? null, 'weight' => $get_open('Deadlift',$i), 'attempt_number'=>$i];
            }
            
            $competitors[] = [
                'id'=>$cid,
                'name'=>$c['name'],
                'session'=>$c['session'],
                'flight'=>$c['flight'],
                'body_weight'=>$c['body_weight'],
                'lot_number'=>$c['lot_number'],
                'platform_id'=>$c['platform_id'],
                'squats'=>$s,
                'bench'=>$b,
                'deadlift'=>$d
            ];
        }
        
        // Determine current lift phase
        $phase_votes = ['Squat'=>0, 'Bench'=>0, 'Deadlift'=>0];
        foreach ($competitors as $comp) {
            foreach (['squats'=>'Squat', 'bench'=>'Bench', 'deadlift'=>'Deadlift'] as $key=>$lift) {
                foreach ($comp[$key] as $at) {
                    if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                        $phase_votes[$lift]++;
                        break 2;
                    }
                }
            }
        }
        $current_lift = 'Squat';
        if ($phase_votes['Deadlift'] > 0) $current_lift = 'Deadlift';
        elseif ($phase_votes['Bench'] > 0) $current_lift = 'Bench';
        
        $lift_key_map = ['Squat'=>'squats', 'Bench'=>'bench', 'Deadlift'=>'deadlift'];
        $key = $lift_key_map[$current_lift];
        
        // Find lifter with LOWEST pending weight in current lift
        $next_attempt_id = null;
        $lowest_weight = null;
        $best_comp = null;
        
        foreach ($competitors as $comp) {
            foreach ($comp[$key] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                    // This is a pending attempt
                    if ($lowest_weight === null || $at['weight'] < $lowest_weight) {
                        $lowest_weight = $at['weight'];
                        $best_comp = $comp;
                        $next_attempt_id = $at['data'] ? ($at['data']['id'] ?? $at['data']['attempt_id'] ?? null) : -($comp['id']*1000 + $at['attempt_number'] + ($current_lift==='Bench'?100:($current_lift==='Deadlift'?200:0)));
                    } elseif ($at['weight'] == $lowest_weight && $best_comp) {
                        // Tie-breaker: body weight
                        $cur_bw = $comp['body_weight'] === null ? 999999 : (float)$comp['body_weight'];
                        $best_bw = $best_comp['body_weight'] === null ? 999999 : (float)$best_comp['body_weight'];
                        if ($cur_bw < $best_bw) {
                            $best_comp = $comp;
                            $next_attempt_id = $at['data'] ? ($at['data']['id'] ?? $at['data']['attempt_id'] ?? null) : -($comp['id']*1000 + $at['attempt_number'] + ($current_lift==='Bench'?100:($current_lift==='Deadlift'?200:0)));
                        }
                    }
                    break; // Only check first pending attempt per lifter
                }
            }
        }
        
        if ($next_attempt_id !== null) {
            $settings['current_attempt'] = $next_attempt_id;
        }
        
        $pdo->prepare("UPDATE meets SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$meet_id]);
        json_out(['ok'=>true,'advanced'=>($next_attempt_id !== null)]);
    }

    // ---------- move_end ----------
    if ($action === 'move_end' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null;
        if ($attempt_id === null) json_out(['ok'=>false,'error'=>'attempt_id required']);
        $ts = now_ts();
        if ((int)$attempt_id > 0) {
            $q = $pdo->prepare("SELECT referee_calls FROM attempts WHERE id = :id"); $q->execute(['id'=>$attempt_id]); $rc = $q->fetchColumn();
            $arr = json_decode($rc ?? '[]', true); if (!is_array($arr)) $arr = [];
            $arr[] = ['moved_to_end'=>$ts];
            $pdo->prepare("UPDATE attempts SET referee_calls = :rc WHERE id = :id")->execute(['rc'=>json_encode($arr),'id'=>$attempt_id]);
            json_out(['ok'=>true]);
        } else {
            if (!isset($settings['moved_to_end'])) $settings['moved_to_end'] = [];
            $settings['moved_to_end'][] = ['id'=>$attempt_id,'ts'=>$ts];
            $pdo->prepare("UPDATE meets SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$meet_id]);
            json_out(['ok'=>true]);
        }
    }

    // ---------- record ----------
    if ($action === 'record' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null; $rtype = $data['record_type'] ?? null;
        if ($attempt_id === null || !$rtype) json_out(['ok'=>false,'error'=>'invalid payload']);
        if ((int)$attempt_id > 0) {
            $pdo->prepare("UPDATE attempts SET is_record=true, record_type=:rt WHERE id=:id")->execute(['rt'=>$rtype,'id'=>$attempt_id]);
            json_out(['ok'=>true]);
        } else {
            $competitor_id = $data['competitor_id'] ?? null; $lift_type = $data['lift_type'] ?? null; $attempt_number = $data['attempt_number'] ?? null; $weight = $data['weight'] ?? null;
            if (!$competitor_id || !$lift_type || !$attempt_number) json_out(['ok'=>false,'error'=>'missing metadata']);
            $ins = $pdo->prepare("INSERT INTO attempts (competitor_id,lift_type,attempt_number,weight,success,is_record,record_type,created_at) VALUES (:cid,:lift,:no,:w,NULL,TRUE,:rt,now()) RETURNING id");
            $ins->execute(['cid'=>$competitor_id,'lift'=>$lift_type,'no'=>$attempt_number,'w'=>$weight,'rt'=>$rtype]);
            $newid = (int)$ins->fetchColumn();
            if (($settings['current_attempt'] ?? null) == $attempt_id) {
                $settings['current_attempt'] = $newid;
                $pdo->prepare("UPDATE meets SET settings=:s WHERE id=:id")->execute(['s'=>json_encode($settings),'id'=>$meet_id]);
            }
            json_out(['ok'=>true,'created_id'=>$newid]);
        }
    }

    // ---------- timer control (manual only from run.php) ----------
    if ($action === 'timer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $cmd = $data['cmd'] ?? null;
        if (!$cmd) json_out(['ok'=>false,'error'=>'cmd required']);
        $timer = $settings['timer'] ?? ['running'=>false,'started_at'=>null,'duration'=>60];
        if ($cmd === 'start') {
            $timer['running'] = true; $timer['started_at'] = now_ts();
            if (isset($data['duration'])) $timer['duration'] = (int)$data['duration'];
        } elseif ($cmd === 'pause') {
            if (!empty($timer['running']) && !empty($timer['started_at'])) {
                $elapsed = now_ts() - (int)$timer['started_at'];
                $remaining = max(0, (int)$timer['duration'] - $elapsed);
                $timer['duration'] = $remaining;
            }
            $timer['running'] = false; $timer['started_at'] = null;
        } elseif ($cmd === 'reset') {
            $timer['running'] = false; $timer['started_at'] = null; $timer['duration'] = isset($data['duration']) ? (int)$data['duration'] : 60;
        } elseif ($cmd === 'set_duration') {
            $timer['duration'] = isset($data['duration']) ? (int)$data['duration'] : $timer['duration'];
            if (!empty($timer['running'])) $timer['started_at'] = now_ts();
        }
        $settings['timer'] = $timer;
        $pdo->prepare("UPDATE meets SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$meet_id]);
        json_out(['ok'=>true,'timer'=>$timer]);
    }

    // ---------- get_rack_height ----------
    if ($action === 'get_rack' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $comp_id = isset($_GET['competitor_id']) ? (int)$_GET['competitor_id'] : null;
        if (!$comp_id) json_out(['ok'=>false,'error'=>'competitor_id required']);
        
        $q = $pdo->prepare("SELECT rack_height FROM competitors WHERE id = :id");
        $q->execute(['id'=>$comp_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok'=>false,'error'=>'competitor not found']);
        
        $rack = json_decode($row['rack_height'] ?? '{}', true);
        if (!is_array($rack)) $rack = [];
        
        json_out(['ok'=>true,'rack'=>$rack]);
    }

    // ---------- update_rack_height ----------
    if ($action === 'update_rack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $comp_id = isset($data['competitor_id']) ? (int)$data['competitor_id'] : null;
        $squat = $data['squat'] ?? null;
        $bench = $data['bench'] ?? null;
        
        if (!$comp_id) json_out(['ok'=>false,'error'=>'competitor_id required']);
        
        $rack = ['squat'=>$squat, 'bench'=>$bench];
        
        $pdo->prepare("UPDATE competitors SET rack_height = :rack::jsonb WHERE id = :id")
            ->execute(['rack'=>json_encode($rack), 'id'=>$comp_id]);
        
        json_out(['ok'=>true]);
    }

    json_out(['ok'=>false,'error'=>'unknown action']);
}

// ------------- HTML UI (non-AJAX) -------------
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Run — <?= safe($meet['name']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
/* Estilo B: similar a results.php (oscuro, cajas redondeadas) */
body{background:#0f0f0f;color:#fff;font-family:Inter,Arial,Helvetica,sans-serif;margin:0}
.wrap{max-width:1400px;margin:12px auto;padding:12px}
.top{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.title{font-size:1.2rem;font-weight:700}
.btn{background:#c0392b;color:#fff;padding:8px 10px;border-radius:6px;border:none;cursor:pointer}
.btn-ghost{background:transparent;border:1px solid #333;color:#fff;padding:6px 8px;border-radius:6px;cursor:pointer}
.layout{display:grid;grid-template-columns:1fr 360px;gap:12px}
.panel{background:#151515;border-radius:8px;padding:12px}
.table{width:100%;border-collapse:collapse}
.table th, .table td{padding:8px;border-bottom:1px solid #222;text-align:left;vertical-align:middle}
.lifter-cell{width:300px}
.box{width:100px;height:68px;border-radius:6px;background:#111;display:flex;flex-direction:column;justify-content:center;align-items:center;margin:4px;border:1px solid #222;position:relative;cursor:pointer}
.box .w{font-weight:700}
.box.good{background:#0b6b2b}
.box.bad{background:#6b0b0b}
.box.record{background:#1f78d1}
.small{font-size:0.9rem;color:#bbb}
.meat{position:absolute;right:6px;top:6px;cursor:pointer}
.ref-light{display:inline-block;width:18px;height:18px;border-radius:50%;background:#3a3a3a;margin-right:6px;border:2px solid #222}
.ref-good{background:#0b6b2b}
.ref-bad{background:#6b0b0b}
.popover{position:absolute;background:#121212;padding:8px;border-radius:6px;border:1px solid #333;z-index:200}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title">Run — <?= safe($meet['name']) ?></div>
    <div style="margin-left:auto">
      <button class="btn" id="select-first">Select First Lifter</button>
      <button class="btn-ghost" id="toggle-autoscroll">Autoscroll: ON</button>
    </div>
  </div>

  <div class="layout">
    <div class="panel" id="left-panel">
      <div style="overflow:auto;max-height:78vh">
        <table class="table" id="competitors-table">
          <thead>
            <tr style="border-bottom:2px solid #222">
              <th class="lifter-cell">Lifter</th>
              <!-- 12 attempt columns: S1-S4, B1-B4, D1-D4 -->
              <?php for($i=1;$i<=4;$i++) echo "<th>S{$i}</th>"; for($i=1;$i<=4;$i++) echo "<th>B{$i}</th>"; for($i=1;$i<=4;$i++) echo "<th>D{$i}</th>"; ?>
            </tr>
          </thead>
          <tbody id="competitors-body">
            <tr><td colspan="13" class="small">Cargando...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <div class="small">Timer (control desde Run)</div>
          <div style="font-size:2rem;font-weight:700" id="timer-display">--:--</div>
        </div>
        <div style="text-align:right">
          <div style="margin-bottom:8px">
            <button class="btn" id="timer-start">Start</button>
            <button class="btn" id="timer-pause">Pause</button>
            <button class="btn" id="timer-reset">Reset</button>
          </div>
          <div>
            <label class="small">Duration</label>
            <input type="number" id="duration-input" value="60" style="width:80px;padding:6px;border-radius:6px;background:#111;color:#fff;border:1px solid #333">
            <button class="btn-ghost" id="set-duration">Set</button>
          </div>
        </div>
      </div>
      <hr style="border-color:#222;margin:12px 0">
      <div><strong>Referee Lights</strong>
        <div style="margin-top:8px">
          <span class="ref-light ref-wait" id="r1"></span>
          <span class="ref-light ref-wait" id="r2"></span>
          <span class="ref-light ref-wait" id="r3"></span>
          <div class="small" id="ref-summary">Sin votos</div>
        </div>
      </div>
      <hr style="border-color:#222;margin:12px 0">
      <div><strong>Rack Heights</strong>
        <div style="margin-top:8px">
          <div class="small mb-2" id="lifter-name-display">Selecciona un competidor</div>
          <div style="display:flex;gap:12px;margin-bottom:8px">
            <div style="flex:1">
              <label class="small">Squat Rack</label>
              <input type="text" id="squat-rack-input" style="font-size:1.2rem;font-weight:700;width:100%;padding:8px;background:#1a1a1a;color:#fff;border:1px solid #444;border-radius:4px" placeholder="-" disabled>
            </div>
            <div style="flex:1">
              <label class="small">Bench Rack</label>
              <input type="text" id="bench-rack-input" style="font-size:1.2rem;font-weight:700;width:100%;padding:8px;background:#1a1a1a;color:#fff;border:1px solid #444;border-radius:4px" placeholder="-" disabled>
            </div>
          </div>
          <button class="btn btn-usl" id="save-rack-btn" style="width:100%;padding:8px" disabled>💾 Guardar Rack Heights</button>
        </div>
      </div>
      <div style="margin-top:12px" class="small">
        - Click en ⋮ para acciones por intento.<br>
        - Auto-advance: al marcar Good/Bad avanza al siguiente peso más bajo.<br>
        - Timer manual: no se inicia automáticamente.
      </div>
    </div>
  </div>
</div>

<script>
const MEET_ID = <?= json_encode($meet_id) ?>;
const PLATFORM_FILTER = <?= json_encode($platform_filter) ?>;
let state = { competitors:[], current_attempt:null, timer:{running:false,started_at:null,duration:60} };
let autoscroll = true;
let poll = null;

function apiGet(action){ 
  let url = 'run.php?meet='+MEET_ID+'&ajax='+action;
  if (PLATFORM_FILTER) url += '&platform='+encodeURIComponent(PLATFORM_FILTER);
  return fetch(url).then(r=>r.json()); 
}
function api(action,payload){ 
  let url = 'run.php?meet='+MEET_ID+'&ajax='+action;
  if (PLATFORM_FILTER) url += '&platform='+encodeURIComponent(PLATFORM_FILTER);
  return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body: JSON.stringify(payload)}).then(r=>r.json()); 
}

// initial fetch and polling
async function fetchState(){
  const j = await apiGet('state');
  if (!j.ok) { console.error(j); return; }
  state = { competitors: j.competitors, current_attempt: j.current_attempt, timer: j.timer };
  render();
}
async function startPolling(){ await fetchState(); if (poll) clearInterval(poll); poll = setInterval(fetchState,1000); }
startPolling();

// render table
function render(){
  renderTable();
  renderTimer();
  updateRefLights();
  updateRackHeights();
}

function renderTable(){
  const body = document.getElementById('competitors-body');
  body.innerHTML = '';
  if (!state.competitors || state.competitors.length===0) { body.innerHTML = '<tr><td colspan="13" class="small">Sin competidores</td></tr>'; return; }
  state.competitors.forEach(comp => {
    const tr = document.createElement('tr');
    // lifter cell
    const tdL = document.createElement('td'); tdL.className='lifter-cell';
    tdL.innerHTML = `<div style="font-weight:700">${escape(comp.name)}</div><div class="small">Lot: ${escape(comp.lot_number||'-')} • Session: ${escape(comp.session||'-')} • Flight: ${escape(comp.flight||'-')}</div>`;
    tr.appendChild(tdL);

    // helper to render attempt boxes array
    const renderBoxes = (arr, liftType) => {
      arr.forEach(at => {
        const td = document.createElement('td');
        const box = document.createElement('div'); box.className='box';
        // mark classes if data exists
        let weight = '';
        let aid = null;
        let success = null;
        let is_record = false;
        if (at['data']) {
          aid = at['data'].id ?? at['data'].attempt_id ?? null;
          weight = at['data'].weight === null ? '' : at['data'].weight;
          success = at['data'].success;
          is_record = at['data'].is_record;
        } else {
          aid = at['data'] ? (at['data'].id||at['data'].attempt_id) : (at['exists']?null:null);
          weight = at['weight']===null ? '' : at['weight'];
        }
        // decide css
        if (is_record) box.classList.add('record');
        else if (success === true || success === 1 || success === '1') box.classList.add('good');
        else if (success === false || success === 0 || success === '0') box.classList.add('bad');

        // inner content
        const wdiv = document.createElement('div'); wdiv.className='w'; wdiv.textContent = weight === '' ? '-' : weight + ' kg';
        box.appendChild(wdiv);
        const small = document.createElement('div'); small.className='small'; small.textContent = liftType + ' #' + at.attempt_number;
        box.appendChild(small);

        // meatball actions (small)
        const meat = document.createElement('div'); meat.className='meat'; meat.textContent='⋮';
        meat.onclick = (ev)=>{ ev.stopPropagation(); openPopover(ev, comp, at, liftType); };
        box.appendChild(meat);

        // inline edit: clicking weight opens prompt to set weight (AJAX)
        box.onclick = async (ev) => {
          ev.stopPropagation();
          const newW = prompt('Peso (kg) para ' + comp.name + ' — ' + liftType + ' #' + at.attempt_number, weight);
          if (newW === null) return;
          // prepare payload: if DB attempt exists use id, else send metadata to create
          let payload = { attempt_id: at.data ? (at.data.id || at.data.attempt_id) : -1, weight: newW };
          if (!at.data) { payload.competitor_id = comp.id; payload.lift_type = liftType; payload.attempt_number = at.attempt_number; }
          const res = await api('set_weight', payload);
          if (!res.ok) return alert('Error guardando peso: '+(res.error||''));
          await fetchState();
        };

        td.appendChild(box);
        tr.appendChild(td);
      });
    };

    renderBoxes(comp.squats, 'Squat');
    renderBoxes(comp.bench, 'Bench');
    renderBoxes(comp.deadlift, 'Deadlift');

    body.appendChild(tr);
    // autoscroll if a box inside row is current_attempt -> highlight
    if (state.current_attempt) {
      // find if any attempt data.id equals current_attempt
      let found = false;
      ['squats','bench','deadlift'].forEach(section => {
        comp[section].forEach(a => {
          if (a.data && (a.data.id == state.current_attempt || a.data.attempt_id == state.current_attempt)) found = true;
        });
      });
      if (found && autoscroll) { tr.scrollIntoView({behavior:'smooth',block:'center'}); tr.style.outline='3px solid #c0392b'; setTimeout(()=>tr.style.outline='',1200); }
    }
  });
}

// Popover menu per attempt
function openPopover(ev, comp, at, liftType){
  // remove existing
  const old = document.getElementById('pop');
  if (old) old.remove();
  const pop = document.createElement('div'); pop.id='pop'; pop.className='popover';
  const actions = [
    {k:'set_current', t:'Set as Current Attempt'},
    {k:'good', t:'Mark Good'},
    {k:'bad', t:'Mark Bad'},
    {k:'move', t:'Move to End of Round'},
    {k:'rec_state', t:'State Record'},
    {k:'rec_reg', t:'Regional Record'},
    {k:'rec_ame', t:'American Record'},
    {k:'rec_w', t:'World Record'}
  ];
  actions.forEach(a=>{
    const d = document.createElement('div'); d.style.padding='6px 8px'; d.style.cursor='pointer'; d.textContent = a.t;
    d.onclick = async ()=>{
      pop.remove();
      const attempt_id = at.data ? (at.data.id || at.data.attempt_id) : ( - (comp.id*1000 + at.attempt_number + (liftType==='Bench'?100:(liftType==='Deadlift'?200:0)) ) );
      if (a.k === 'set_current') {
        await api('set_current',{attempt_id});
        await fetchState();
      } else if (a.k === 'good' || a.k === 'bad') {
        // payload include metadata if generated
        const payload = { attempt_id, result: a.k === 'good' ? 'good' : 'bad' };
        if (!at.data) { payload.competitor_id = comp.id; payload.lift_type = liftType; payload.attempt_number = at.attempt_number; payload.weight = at.weight; }
        const res = await api('mark', payload);
        if (!res.ok) return alert('Error: '+(res.error||''));
        await fetchState();
      } else if (a.k === 'move') {
        if (!confirm('Mover al final de la misma ronda?')) return;
        await api('move_end',{attempt_id});
        await fetchState();
      } else if (a.k.startsWith('rec')) {
        const map = {'rec_state':'State','rec_reg':'Regional','rec_ame':'American','rec_w':'World'};
        const rtype = map[a.k];
        const payload = { attempt_id, record_type: rtype };
        if (!at.data) { payload.competitor_id = comp.id; payload.lift_type = liftType; payload.attempt_number = at.attempt_number; payload.weight = at.weight; }
        const res = await api('record', payload);
        if (!res.ok) alert('Error marcando record');
        await fetchState();
      }
    };
    pop.appendChild(d);
  });
  document.body.appendChild(pop);
  pop.style.left = ev.pageX + 'px'; pop.style.top = ev.pageY + 'px';
  window.addEventListener('click', ()=>{ const p=document.getElementById('pop'); if(p) p.remove(); }, {once:true});
}

// Timer UI
function renderTimer(){
  const el = document.getElementById('timer-display');
  const t = state.timer || {running:false,started_at:null,duration:60};
  let rem = t.duration;
  if (t.running && t.started_at) {
    const now = Math.floor(Date.now()/1000); const elapsed = now - t.started_at; rem = Math.max(0, t.duration - elapsed);
  }
  const mm = Math.floor(rem/60).toString().padStart(2,'0'); const ss = (rem%60).toString().padStart(2,'0');
  el.textContent = mm + ':' + ss;
}

// Ref lights (summary uses current_attempt's referee_calls if possible)
function updateRefLights(){
  const r1 = document.getElementById('r1'), r2 = document.getElementById('r2'), r3 = document.getElementById('r3'), rs = document.getElementById('ref-summary');
  [r1,r2,r3].forEach(x=>x.className='ref-light ref-wait');
  if (!state.current_attempt) { rs.textContent='Sin votos'; return; }
  // find attempt
  let calls = [];
  state.competitors.forEach(comp=>{
    ['squats','bench','deadlift'].forEach(sec=>{
      comp[sec].forEach(a=>{
        if (a.data && (a.data.id == state.current_attempt || a.data.attempt_id == state.current_attempt)) {
          calls = a.data.referee_calls || [];
        }
      });
    });
  });
  if (calls.length===0) { rs.textContent='Sin votos'; return; }
  calls.forEach((c,i)=>{ const el = document.getElementById('r'+(i+1)); if(!el) return; el.className = c.call==='good' ? 'ref-light ref-good' : 'ref-light ref-bad'; });
  rs.textContent = calls.map(c=>'Ref '+(c.referee||'?')+': '+c.call).join(' • ');
}

// Rack Heights - show rack info for current lifter
async function updateRackHeights(){
  const squatInput = document.getElementById('squat-rack-input');
  const benchInput = document.getElementById('bench-rack-input');
  const lifterDisplay = document.getElementById('lifter-name-display');
  const saveBtn = document.getElementById('save-rack-btn');
  
  if (!state.current_attempt) {
    squatInput.value = '';
    benchInput.value = '';
    squatInput.disabled = true;
    benchInput.disabled = true;
    saveBtn.disabled = true;
    lifterDisplay.textContent = 'Selecciona un competidor';
    return;
  }
  
  // Find current lifter
  let currentComp = null;
  state.competitors.forEach(comp=>{
    ['squats','bench','deadlift'].forEach(sec=>{
      comp[sec].forEach(a=>{
        if (a.data && (a.data.id == state.current_attempt || a.data.attempt_id == state.current_attempt)) {
          currentComp = comp;
        }
      });
    });
  });
  
  if (!currentComp) {
    squatInput.value = '';
    benchInput.value = '';
    squatInput.disabled = true;
    benchInput.disabled = true;
    saveBtn.disabled = true;
    lifterDisplay.textContent = 'Selecciona un competidor';
    return;
  }
  
  lifterDisplay.textContent = currentComp.name;
  squatInput.disabled = false;
  benchInput.disabled = false;
  saveBtn.disabled = false;
  
  // Fetch rack heights from server
  try {
    const res = await apiGet('get_rack&competitor_id=' + currentComp.id);
    if (res.ok && res.rack) {
      squatInput.value = res.rack.squat || '';
      benchInput.value = res.rack.bench || '';
    } else {
      squatInput.value = '';
      benchInput.value = '';
    }
  } catch(e) {
    console.error('Error fetching rack heights:', e);
    squatInput.value = '';
    benchInput.value = '';
  }
}

// Save rack heights
document.getElementById('save-rack-btn').addEventListener('click', async ()=>{
  if (!state.current_attempt) return;
  
  // Find current lifter
  let currentComp = null;
  state.competitors.forEach(comp=>{
    ['squats','bench','deadlift'].forEach(sec=>{
      comp[sec].forEach(a=>{
        if (a.data && (a.data.id == state.current_attempt || a.data.attempt_id == state.current_attempt)) {
          currentComp = comp;
        }
      });
    });
  });
  
  if (!currentComp) return;
  
  const squatInput = document.getElementById('squat-rack-input');
  const benchInput = document.getElementById('bench-rack-input');
  
  const res = await api('update_rack', {
    competitor_id: currentComp.id,
    squat: squatInput.value,
    bench: benchInput.value
  });
  
  if (res.ok) {
    const saveBtn = document.getElementById('save-rack-btn');
    saveBtn.textContent = '✓ Guardado';
    setTimeout(()=>saveBtn.textContent='💾 Guardar Rack Heights', 1000);
  } else {
    alert('Error guardando rack heights: ' + (res.error || ''));
  }
});

// utilities
function escape(s){ return s===null||s===undefined ? '' : String(s); }

// select first lifter (first competitor first non-empty attempt in order)
document.getElementById('select-first').addEventListener('click', async ()=>{
  await fetchState();
  if (!state.competitors || state.competitors.length===0) return alert('No hay competidores');
  // find first attempt that has weight or exists (prefer S1 then B1 then D1?) — per spec pick first in the sequence (Squat1 first)
  for (const comp of state.competitors) {
    const a = comp.squats[0];
    const attempt_id = a.data ? (a.data.id||a.data.attempt_id) : ( - (comp.id*1000 + 1) );
    await api('set_current',{attempt_id});
    await fetchState();
    return;
  }
});

// autoscroll toggle
document.getElementById('toggle-autoscroll').addEventListener('click', ()=>{ autoscroll = !autoscroll; document.getElementById('toggle-autoscroll').textContent = 'Autoscroll: ' + (autoscroll ? 'ON' : 'OFF'); });

// timer buttons
document.getElementById('timer-start').addEventListener('click', async ()=>{ const dur = parseInt(document.getElementById('duration-input').value)||60; await api('timer',{cmd:'start',duration:dur}); await fetchState(); });
document.getElementById('timer-pause').addEventListener('click', async ()=>{ await api('timer',{cmd:'pause'}); await fetchState(); });
document.getElementById('timer-reset').addEventListener('click', async ()=>{ const dur = parseInt(document.getElementById('duration-input').value)||60; await api('timer',{cmd:'reset',duration:dur}); await fetchState(); });
document.getElementById('set-duration').addEventListener('click', async ()=>{ const dur = parseInt(document.getElementById('duration-input').value)||60; await api('timer',{cmd:'set_duration',duration:dur}); await fetchState(); });

</script>
</body>
</html>
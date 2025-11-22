<?php
// run.php - filas por levantador, UI estilo B, timer manual, actions por intento
session_start();
header('Content-Type: text/html; charset=utf-8');

require_once 'database.php';
try { $pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch(Exception $e){ die("DB error: ".$e->getMessage()); }

function json_out($v){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($v); exit; }
function safe($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function now_ts(){ return time(); }

// --- Auth: require session + role >= 2 (referee)
if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'Sesión no iniciada']);
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Acceso denegado</h1><p style='text-align:center;'>Debes iniciar sesión para acceder a esta página.</p>");
}

$stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
$stmt->execute(['id'=>$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] < 2) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'Acceso denegado - rol insuficiente']);
    http_response_code(403);
    die("<h1 style='text-align:center;color:red;'>403 - Acceso denegado</h1><p style='text-align:center;'>Se requiere rol de árbitro o superior (rol ≥ 2) para acceder a esta página.</p>");
}

// --- meet and REQUIRED platform filter
$meet_id = isset($_REQUEST['meet']) ? (int)$_REQUEST['meet'] : null;
$platform_filter = isset($_REQUEST['platform']) && $_REQUEST['platform'] !== '' ? $_REQUEST['platform'] : null;

if (!$meet_id) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'meet required']);
    die("<h3 style='color:red;text-align:center;'>ID de competencia no especificado. Use ?meet=ID&platform=ID</h3>");
}
if (!$platform_filter) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'platform required']);
    die("<h3 style='color:red;text-align:center;'>Plataforma requerida. Use ?meet=ID&platform=ID</h3>");
}

// check meet
$sth = $pdo->prepare("SELECT * FROM meets WHERE id = :id");
$sth->execute(['id'=>$meet_id]);
$meet = $sth->fetch(PDO::FETCH_ASSOC);
if (!$meet) { 
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'meet not found']); 
    die("<h3 style='color:red;text-align:center;'>Competencia no encontrada.</h3>");
}

// Get platform_id
$platform_id = null;
if (is_numeric($platform_filter)) {
    $platform_id = (int)$platform_filter;
} else {
    $q = $pdo->prepare("SELECT id FROM platforms WHERE meet_id = :mid AND name = :name");
    $q->execute(['mid'=>$meet_id,'name'=>$platform_filter]);
    $platform_id = $q->fetchColumn();
}
if (!$platform_id) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'platform not found']);
    die("<h3 style='color:red;text-align:center;'>Plataforma no encontrada.</h3>");
}

$pstmt = $pdo->prepare("SELECT * FROM platforms WHERE id = :id");
$pstmt->execute(['id'=>$platform_id]);
$platform = $pstmt->fetch(PDO::FETCH_ASSOC);
if (!$platform) {
    if (isset($_GET['ajax'])) json_out(['ok'=>false,'error'=>'platform not found']);
    die("<h3 style='color:red;text-align:center;'>Plataforma no encontrada.</h3>");
}

$settings = json_decode($platform['settings'] ?? '{}', true);
if (!is_array($settings)) $settings = [];

// Default bar and collar weights
$bar_weight = $settings['bar_weight'] ?? 20;
$collar_weight = $settings['collar_weight'] ?? 2.5;

// ---------- AJAX endpoints ----------
if (isset($_GET['ajax'])) {
    $action = $_GET['ajax'];

    // Helper: load competitors for platform
    $load_competitors = function() use ($pdo, $meet_id, $platform_id) {
        $sql = "SELECT c.* FROM competitors c
                WHERE c.meet_id = :meet AND c.platform_id = :plat
                ORDER BY COALESCE(c.session::int,0), COALESCE(c.flight,''), COALESCE(c.lot_number::int,0), c.name";
        $q = $pdo->prepare($sql);
        $q->execute(['meet'=>$meet_id, 'plat'=>$platform_id]);
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
            $idx[$cid][$lift][$no]['referee_calls'] = json_decode($r['referee_calls'] ?? '[]', true);
        }
        return $idx;
    };

    // ========== STATE ==========
    if ($action === 'state') {
        $comps = $load_competitors();
        $comp_ids = array_map(function($c){ return $c['id']; }, $comps);
        $attempts_idx = $load_attempts_index($comp_ids);

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
            
            // Mostrar todos los intentos 1-4
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
                'squats'=>$s,
                'bench'=>$b,
                'deadlift'=>$d
            ];
        }

        // Determine current lift phase - debe completar TODOS los intentos de un lift antes de pasar al siguiente
        $phase_votes = ['Squat'=>0, 'Bench'=>0, 'Deadlift'=>0];
        foreach ($competitors as $comp) {
            // Contar intentos pendientes por cada lift
            foreach ($comp['squats'] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                    $phase_votes['Squat']++;
                }
            }
            foreach ($comp['bench'] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                    $phase_votes['Bench']++;
                }
            }
            foreach ($comp['deadlift'] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                    $phase_votes['Deadlift']++;
                }
            }
        }
        
        // Prioridad: completar todos los Squat primero, luego Bench, luego Deadlift
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

        // Sort by session -> flight -> next pending weight -> body weight -> lot
        usort($competitors, function($a, $b) use ($key) {
            // 1. Session ASC (primero sesión 1, luego 2, etc.)
            $as = $a['session'] === null ? 999999 : (int)$a['session'];
            $bs = $b['session'] === null ? 999999 : (int)$b['session'];
            if ($as != $bs) return $as - $bs;
            
            // 2. Flight ASC (A, B, C... o 1, 2, 3...)
            $af = $a['flight'] ?? '';
            $bf = $b['flight'] ?? '';
            if ($af !== $bf) return strcmp($af, $bf);
            
            // 3. Próximo intento pendiente (peso) para el lift actual
            $a_weight = null;
            $b_weight = null;
            
            foreach ($a[$key] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                    $a_weight = (float)$at['weight'];
                    break;
                }
            }
            foreach ($b[$key] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
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
            
            // 4. Body weight ASC (el más liviano va primero - regla oficial de desempate)
            $abw = $a['body_weight'] === null ? 999999 : (float)$a['body_weight'];
            $bbw = $b['body_weight'] === null ? 999999 : (float)$b['body_weight'];
            if (abs($abw - $bbw) > 0.001) return ($abw < $bbw) ? -1 : 1;
            
            // 5. Lot number ASC
            $al = $a['lot_number'] === null ? 999999 : (int)$a['lot_number'];
            $bl = $b['lot_number'] === null ? 999999 : (int)$b['lot_number'];
            if ($al != $bl) return $al - $bl;
            
            // 6. Name ASC como fallback final
            return strcmp($a['name'], $b['name']);
        });

        $current_attempt = $settings['current_attempt'] ?? null;
        $timer = $settings['timer'] ?? ['running'=>false,'started_at'=>null,'duration'=>60];
        
        json_out([
            'ok'=>true,
            'meet'=>['id'=>$meet_id,'name'=>$meet['name'],'platform'=>$platform['name']],
            'competitors'=>$competitors,
            'current_attempt'=>$current_attempt,
            'timer'=>$timer,
            'current_lift'=>$current_lift,
            'bar_weight'=>$bar_weight,
            'collar_weight'=>$collar_weight
        ]);
    }

    // ========== SET_WEIGHT ==========
    if ($action === 'set_weight' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null;
        $weight = isset($data['weight']) && $data['weight'] !== '' ? (float)$data['weight'] : null;
        $lift_type = $data['lift_type'] ?? null;
        $attempt_number = isset($data['attempt_number']) ? (int)$data['attempt_number'] : null;
        $competitor_id = isset($data['competitor_id']) ? (int)$data['competitor_id'] : null;

        if ($attempt_id === null) json_out(['ok'=>false,'error'=>'attempt_id required']);

        if ((int)$attempt_id <= 0) {
            if (!$competitor_id || !$lift_type || !$attempt_number) json_out(['ok'=>false,'error'=>'missing metadata']);
            $ins = $pdo->prepare("INSERT INTO attempts (competitor_id,lift_type,attempt_number,weight,success,created_at) VALUES (:cid,:lift,:no,:w,NULL,now()) RETURNING id");
            $ins->execute(['cid'=>$competitor_id,'lift'=>$lift_type,'no'=>$attempt_number,'w'=>$weight]);
            $newid = (int)$ins->fetchColumn();
            if (($settings['current_attempt'] ?? null) == $attempt_id) {
                $settings['current_attempt'] = $newid;
                $pdo->prepare("UPDATE platforms SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$platform_id]);
            }
            json_out(['ok'=>true,'created_id'=>$newid]);
        } else {
            $pdo->prepare("UPDATE attempts SET weight = :w WHERE id = :id")->execute(['w'=>$weight,'id'=>$attempt_id]);
            json_out(['ok'=>true]);
        }
    }

    // ========== SET_CURRENT ==========
    if ($action === 'set_current' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null;
        if ($attempt_id === null) json_out(['ok'=>false,'error'=>'attempt_id required']);
        
        $settings['current_attempt'] = $attempt_id;
        $settings['timer'] = ['running'=>false,'started_at'=>null,'duration'=>60];
        $pdo->prepare("UPDATE platforms SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$platform_id]);
        json_out(['ok'=>true]);
    }

    // ========== MARK (good/bad) ==========
    if ($action === 'mark' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null;
        $result = $data['result'] ?? null;
        if ($attempt_id === null || !in_array($result,['good','bad'])) json_out(['ok'=>false,'error'=>'invalid payload']);
        $success = $result === 'good' ? 1 : 0;

        if ((int)$attempt_id <= 0) {
            $competitor_id = $data['competitor_id'] ?? null;
            $lift_type = $data['lift_type'] ?? null;
            $attempt_number = isset($data['attempt_number']) ? (int)$data['attempt_number'] : null;
            $weight = isset($data['weight']) && $data['weight'] !== '' ? (float)$data['weight'] : null;
            if (!$competitor_id || !$lift_type || !$attempt_number) json_out(['ok'=>false,'error'=>'missing metadata']);
            $ins = $pdo->prepare("INSERT INTO attempts (competitor_id,lift_type,attempt_number,weight,success,created_at) VALUES (:cid,:lift,:no,:w,:s,now()) RETURNING id");
            $ins->execute(['cid'=>$competitor_id,'lift'=>$lift_type,'no'=>$attempt_number,'w'=>$weight,'s'=>$success]);
            $newid = (int)$ins->fetchColumn();
        } else {
            $pdo->prepare("UPDATE attempts SET success = :s WHERE id = :id")->execute(['s'=>$success,'id'=>$attempt_id]);
        }
        
        // AUTO-ADVANCE to next lifter with lowest weight
        $comps = $load_competitors();
        $comp_ids = array_map(function($c){ return $c['id']; }, $comps);
        $attempts_idx = $load_attempts_index($comp_ids);
        
        // Rebuild competitors
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
                    return $attempts_json[$lk][$no] === null ? null : (float)$attempts_json[$lk][$no];
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
                'id'=>$cid, 'name'=>$c['name'], 'session'=>$c['session'], 'flight'=>$c['flight'],
                'body_weight'=>$c['body_weight'], 'lot_number'=>$c['lot_number'],
                'squats'=>$s, 'bench'=>$b, 'deadlift'=>$d
            ];
        }
        
        // Determine phase - completar todos los Squat antes de pasar a Bench
        $phase_votes = ['Squat'=>0, 'Bench'=>0, 'Deadlift'=>0];
        foreach ($competitors as $comp) {
            // Contar intentos pendientes por cada lift
            foreach ($comp['squats'] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                    $phase_votes['Squat']++;
                }
            }
            foreach ($comp['bench'] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                    $phase_votes['Bench']++;
                }
            }
            foreach ($comp['deadlift'] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
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
        
        // Find next: session -> flight -> peso -> body weight -> lot
        $next_attempt_id = null;
        $best = null;
        
        foreach ($competitors as $comp) {
            foreach ($comp[$key] as $at) {
                if ($at['weight'] !== null && (!isset($at['data']) || $at['data'] === null || $at['data']['success'] === null)) {
                    $candidate = [
                        'session' => $comp['session'] === null ? 999999 : (int)$comp['session'],
                        'flight' => $comp['flight'] ?? '',
                        'weight' => (float)$at['weight'],
                        'body_weight' => $comp['body_weight'] === null ? 999999 : (float)$comp['body_weight'],
                        'lot' => $comp['lot_number'] === null ? 999999 : (int)$comp['lot_number'],
                        'attempt_id' => $at['data'] ? ($at['data']['id'] ?? $at['data']['attempt_id']) : -($comp['id']*1000 + $at['attempt_number'] + ($current_lift==='Bench'?100:($current_lift==='Deadlift'?200:0)))
                    ];
                    
                    if ($best === null) {
                        $best = $candidate;
                    } else {
                        // Compare: session -> flight -> weight -> body_weight -> lot
                        $should_replace = false;
                        
                        if ($candidate['session'] < $best['session']) {
                            $should_replace = true;
                        } elseif ($candidate['session'] == $best['session']) {
                            $cmp_flight = strcmp($candidate['flight'], $best['flight']);
                            if ($cmp_flight < 0) {
                                $should_replace = true;
                            } elseif ($cmp_flight == 0) {
                                if ($candidate['weight'] < $best['weight']) {
                                    $should_replace = true;
                                } elseif (abs($candidate['weight'] - $best['weight']) < 0.001) {
                                    if ($candidate['body_weight'] < $best['body_weight']) {
                                        $should_replace = true;
                                    } elseif (abs($candidate['body_weight'] - $best['body_weight']) < 0.001) {
                                        if ($candidate['lot'] < $best['lot']) {
                                            $should_replace = true;
                                        }
                                    }
                                }
                            }
                        }
                        
                        if ($should_replace) {
                            $best = $candidate;
                        }
                    }
                    break;
                }
            }
        }
        
        if ($best !== null) {
            $settings['current_attempt'] = $best['attempt_id'];
        }
        
        $pdo->prepare("UPDATE platforms SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$platform_id]);
        json_out(['ok'=>true,'advanced'=>($best !== null)]);
    }

    // ========== UPDATE BAR/COLLAR ==========
    if ($action === 'update_equipment' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (isset($data['bar_weight'])) $settings['bar_weight'] = (float)$data['bar_weight'];
        if (isset($data['collar_weight'])) $settings['collar_weight'] = (float)$data['collar_weight'];
        $pdo->prepare("UPDATE platforms SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$platform_id]);
        json_out(['ok'=>true]);
    }

    // ========== TIMER ==========
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
                $timer['duration'] = max(0, (int)$timer['duration'] - $elapsed);
            }
            $timer['running'] = false; $timer['started_at'] = null;
        } elseif ($cmd === 'reset') {
            $timer['running'] = false; $timer['started_at'] = null;
            $timer['duration'] = isset($data['duration']) ? (int)$data['duration'] : 60;
        } elseif ($cmd === 'set_duration') {
            // Solo actualizar la duración sin afectar el estado de running
            $timer['duration'] = isset($data['duration']) ? (int)$data['duration'] : 60;
        }
        $settings['timer'] = $timer;
        $pdo->prepare("UPDATE platforms SET settings = :s WHERE id = :id")->execute(['s'=>json_encode($settings),'id'=>$platform_id]);
        json_out(['ok'=>true,'timer'=>$timer]);
    }

    // ========== GET/UPDATE RACK ==========
    if ($action === 'get_rack') {
        $comp_id = isset($_GET['competitor_id']) ? (int)$_GET['competitor_id'] : null;
        if (!$comp_id) json_out(['ok'=>false,'error'=>'competitor_id required']);
        $q = $pdo->prepare("SELECT rack_height FROM competitors WHERE id = :id");
        $q->execute(['id'=>$comp_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok'=>false,'error'=>'not found']);
        $rack = json_decode($row['rack_height'] ?? '{}', true) ?: [];
        json_out(['ok'=>true,'rack'=>$rack]);
    }

    if ($action === 'update_rack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $comp_id = isset($data['competitor_id']) ? (int)$data['competitor_id'] : null;
        if (!$comp_id) json_out(['ok'=>false,'error'=>'competitor_id required']);
        $rack = ['squat'=>$data['squat']??null, 'bench'=>$data['bench']??null];
        $pdo->prepare("UPDATE competitors SET rack_height = :rack::jsonb WHERE id = :id")->execute(['rack'=>json_encode($rack), 'id'=>$comp_id]);
        json_out(['ok'=>true]);
    }

    // ========== MOVE_END, RECORD ==========
    if ($action === 'move_end' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null;
        if (!$attempt_id) json_out(['ok'=>false,'error'=>'attempt_id required']);
        $ts = now_ts();
        if ((int)$attempt_id > 0) {
            $q = $pdo->prepare("SELECT referee_calls FROM attempts WHERE id = :id"); $q->execute(['id'=>$attempt_id]);
            $arr = json_decode($q->fetchColumn() ?? '[]', true) ?: [];
            $arr[] = ['moved_to_end'=>$ts];
            $pdo->prepare("UPDATE attempts SET referee_calls = :rc WHERE id = :id")->execute(['rc'=>json_encode($arr),'id'=>$attempt_id]);
        }
        json_out(['ok'=>true]);
    }

    if ($action === 'record' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $attempt_id = $data['attempt_id'] ?? null;
        $rtype = $data['record_type'] ?? null;
        if (!$attempt_id || !$rtype) json_out(['ok'=>false,'error'=>'invalid payload']);
        if ((int)$attempt_id > 0) {
            $pdo->prepare("UPDATE attempts SET is_record=true, record_type=:rt WHERE id=:id")->execute(['rt'=>$rtype,'id'=>$attempt_id]);
        }
        json_out(['ok'=>true]);
    }

    json_out(['ok'=>false,'error'=>'unknown action']);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Run — <?= safe($meet['name']) ?> — <?= safe($platform['name']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{background:#0f0f0f;color:#fff;font-family:Inter,Arial,Helvetica,sans-serif;margin:0}
.wrap{max-width:1500px;margin:0 auto;padding:12px}
.top{display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.title{font-size:1.2rem;font-weight:700}
.btn{background:#c0392b;color:#fff;padding:8px 12px;border-radius:6px;border:none;cursor:pointer}
.btn:hover{background:#a5281b}
.btn-ghost{background:transparent;border:1px solid #444;color:#fff;padding:6px 10px;border-radius:6px;cursor:pointer}
.layout{display:grid;grid-template-columns:1fr 380px;gap:12px}
.panel{background:#151515;border-radius:8px;padding:12px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:6px 8px;border-bottom:1px solid #222;text-align:center;vertical-align:middle}
.table th{background:#1a1a1a;font-size:0.85rem}
.lifter-cell{text-align:left!important;min-width:180px}
.box{width:85px;height:60px;border-radius:6px;background:#1a1a1a;display:flex;flex-direction:column;justify-content:center;align-items:center;margin:2px auto;border:2px solid #333;cursor:pointer;transition:all 0.15s}
.box:hover{border-color:#666}
.box .w{font-weight:700;font-size:0.95rem}
.box.good{background:#0b6b2b;border-color:#0f0}
.box.bad{background:#6b0b0b;border-color:#f00}
.box.current{border-color:#ffcc00;box-shadow:0 0 8px #ffcc00}
.box.record{background:#1f78d1}
.small{font-size:0.85rem;color:#999}
.meat{position:absolute;right:4px;top:2px;cursor:pointer;font-size:0.8rem;opacity:0.6}
.meat:hover{opacity:1}
.box{position:relative}
.ref-lights{display:flex;gap:8px;margin:8px 0}
.ref-light{width:28px;height:28px;border-radius:50%;background:#333;border:2px solid #555}
.ref-light.voted{background:#888}
.ref-light.good{background:#0b6b2b;border-color:#0f0}
.ref-light.bad{background:#6b0b0b;border-color:#f00}
.popover{position:fixed;background:#1a1a1a;padding:8px 0;border-radius:6px;border:1px solid #444;z-index:1000;min-width:180px}
.popover div{padding:8px 12px;cursor:pointer}
.popover div:hover{background:#333}
.equipment-row{display:flex;gap:8px;align-items:center;margin-bottom:8px}
.equipment-row input{width:70px;padding:6px;background:#1a1a1a;color:#fff;border:1px solid #444;border-radius:4px;text-align:center}
.equipment-row label{font-size:0.85rem;color:#aaa}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title"><?= safe($meet['name']) ?> — Run Plataforma: <?= safe($platform['name']) ?></div>
    <div style="margin-left:auto;display:flex;gap:8px">
      <button class="btn" id="select-first">Seleccionar primer intento</button>
      <button class="btn-ghost" id="toggle-autoscroll">Autoscroll: Activado</button>
    </div>
  </div>

  <div class="layout">
    <div class="panel">
      <div style="overflow:auto;max-height:80vh">
        <table class="table" id="competitors-table">
          <thead>
            <tr>
              <th class="lifter-cell">Lifter</th>
              <th>S1</th><th>S2</th><th>S3</th><th>S4</th>
              <th>B1</th><th>B2</th><th>B3</th><th>B4</th>
              <th>D1</th><th>D2</th><th>D3</th><th>D4</th>
            </tr>
          </thead>
          <tbody id="competitors-body"><tr><td colspan="13">Cargando...</td></tr></tbody>
        </table>
      </div>
    </div>

    <div class="panel">
      <!-- Timer -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div>
          <div class="small">Contador</div>
          <div style="font-size:2.5rem;font-weight:700;font-family:monospace" id="timer-display">01:00</div>
        </div>
        <div>
          <button class="btn" id="timer-start">▶</button>
          <button class="btn-ghost" id="timer-pause">⏸</button>
          <button class="btn-ghost" id="timer-reset">↺</button>
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
        <label class="small">Tiempo:</label>
        <input type="number" id="timer-duration" value="60" min="1" max="300" step="1" style="width:70px;padding:6px;background:#1a1a1a;color:#fff;border:1px solid #444;border-radius:4px;text-align:center"> 
        <span class="small">segundos</span>
        <button class="btn-ghost" id="set-timer" style="padding:6px 10px">Aplicar</button>
      </div>

      <hr style="border-color:#333;margin:12px 0">

      <!-- Referee Lights -->
      <div>
        <div class="small">Luces de jueces</div>
        <div class="ref-lights">
          <div class="ref-light" id="r1"></div>
          <div class="ref-light" id="r2"></div>
          <div class="ref-light" id="r3"></div>
        </div>
        <div class="small" id="ref-summary">Esperando votos...</div>
      </div>

      <hr style="border-color:#333;margin:12px 0">

      <!-- Equipment Config -->
      <div>
        <div class="small" style="margin-bottom:8px">Barra y collarines</div>
        <div class="equipment-row">
          <label>Barra:</label>
          <input type="number" id="bar-weight" value="<?= $bar_weight ?>" step="0.5"> kg
        </div>
        <div class="equipment-row">
          <label>Collarines (c/u):</label>
          <input type="number" id="collar-weight" value="<?= $collar_weight ?>"> kg
        </div>
        <button class="btn-ghost" id="save-equipment" style="width:100%;margin-top:4px">Guardar configuración</button>
      </div>

      <hr style="border-color:#333;margin:12px 0">

      <!-- Rack Heights -->
      <div>
        <div class="small">Racks</div>
        <div id="lifter-name-display" style="font-weight:700;margin:4px 0">--</div>
        <div style="display:flex;gap:8px">
          <div style="flex:1">
            <label class="small">Sentadilla</label>
            <input type="text" id="squat-rack" style="width:100%;padding:6px;background:#1a1a1a;color:#fff;border:1px solid #444;border-radius:4px" disabled>
          </div>
          <div style="flex:1">
            <label class="small">Press Banca</label>
            <input type="text" id="bench-rack" style="width:100%;padding:6px;background:#1a1a1a;color:#fff;border:1px solid #444;border-radius:4px" disabled>
          </div>
        </div>
        <button class="btn-ghost" id="save-rack" style="width:100%;margin-top:8px" disabled>Guardar racks</button>
      </div>
    </div>
  </div>
</div>

<script>
const MEET_ID = <?= json_encode($meet_id) ?>;
const PLATFORM_ID = <?= json_encode($platform_id) ?>;
let state = {competitors:[],current_attempt:null,timer:{},bar_weight:20,collar_weight:2.5};
let autoscroll = true;
let poll = null;
let lastCurrentAttempt = null;

function api(action,payload=null){
  const url = 'run.php?meet='+MEET_ID+'&platform='+PLATFORM_ID+'&ajax='+action;
  if(payload===null) return fetch(url).then(r=>r.json());
  return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(r=>r.json());
}

async function fetchState(){
  const j = await api('state');
  if(!j.ok) return console.error(j);
  state = j;
  render();
}

function render(){
  renderTable();
  renderTimer();
  renderRefLights();
  renderRack();
}

function renderTable(){
  const body = document.getElementById('competitors-body');
  body.innerHTML = '';
  if(!state.competitors?.length){
    body.innerHTML = '<tr><td colspan="13" class="small">Sin competidores</td></tr>';
    return;
  }
  
  state.competitors.forEach(comp => {
    const tr = document.createElement('tr');
    
    // Lifter cell
    const tdL = document.createElement('td');
    tdL.className = 'lifter-cell';
    tdL.innerHTML = `<div style="font-weight:700">${esc(comp.name)}</div>
      <div class="small">Lot: ${esc(comp.lot_number||'-')} • S${esc(comp.session||'-')} • F${esc(comp.flight||'-')}</div>`;
    tr.appendChild(tdL);
    
    // Attempts - siempre renderizar 4 intentos por lift
    const renderAttempts = (arr, liftType) => {
      // Asegurar que siempre hay 4 intentos (rellenar con vacíos si faltan)
      const attempts = [];
      for(let i = 0; i < 4; i++) {
        attempts.push(arr[i] || {exists: false, data: null, weight: null, attempt_number: i+1});
      }
      
      attempts.forEach(at => {
        const td = document.createElement('td');
        const box = document.createElement('div');
        box.className = 'box';
        
        const aid = at.data ? (at.data.id || at.data.attempt_id) : null;
        const weight = at.data?.weight ?? at.weight;
        const success = at.data?.success;
        const isRecord = at.data?.is_record;
        
        // Current highlight
        if(aid && aid == state.current_attempt) box.classList.add('current');
        
        // Status color
        if(isRecord) box.classList.add('record');
        else if(success === true || success === 1) box.classList.add('good');
        else if(success === false || success === 0) box.classList.add('bad');
        
        box.innerHTML = `<div class="w">${weight ? weight+'kg' : '-'}</div>
          <div class="small">${liftType[0]}${at.attempt_number}</div>
          <div class="meat">⋮</div>`;
        
        // Click to edit weight
        box.onclick = async (e) => {
          if(e.target.classList.contains('meat')) return;
          const newW = prompt(`Peso para ${comp.name} - ${liftType} #${at.attempt_number}:`, weight||'');
          if(newW === null) return;
          const payload = {attempt_id: aid || -1, weight: newW};
          if(!aid) Object.assign(payload, {competitor_id:comp.id, lift_type:liftType, attempt_number:at.attempt_number});
          await api('set_weight', payload);
          fetchState();
        };
        
        // Meatball menu
        box.querySelector('.meat').onclick = (e) => {
          e.stopPropagation();
          showPopover(e, comp, at, liftType);
        };
        
        td.appendChild(box);
        tr.appendChild(td);
      });
    };
    
    renderAttempts(comp.squats, 'Squat');
    renderAttempts(comp.bench, 'Bench');
    renderAttempts(comp.deadlift, 'Deadlift');
    
    body.appendChild(tr);
    
    // Autoscroll to current
    if(autoscroll && state.current_attempt && state.current_attempt !== lastCurrentAttempt){
      let found = false;
      ['squats','bench','deadlift'].forEach(s => {
        comp[s].forEach(a => {
          if(a.data && (a.data.id == state.current_attempt || a.data.attempt_id == state.current_attempt)) found = true;
        });
      });
      if(found){
        tr.scrollIntoView({behavior:'smooth',block:'center'});
        lastCurrentAttempt = state.current_attempt;
      }
    }
  });
}

function showPopover(e, comp, at, liftType){
  document.getElementById('pop')?.remove();
  const pop = document.createElement('div');
  pop.id = 'pop';
  pop.className = 'popover';
  pop.style.left = e.pageX + 'px';
  pop.style.top = e.pageY + 'px';
  
  const aid = at.data ? (at.data.id || at.data.attempt_id) : -( comp.id*1000 + at.attempt_number + (liftType==='Bench'?100:(liftType==='Deadlift'?200:0)) );
  
  const actions = [
    {t:'Seleccionar como intento actual', fn: ()=> api('set_current',{attempt_id:aid}).then(fetchState)},
    {t:'Marcar como válido', fn: ()=> {
      const p = {attempt_id:aid, result:'good'};
      if(!at.data) Object.assign(p,{competitor_id:comp.id,lift_type:liftType,attempt_number:at.attempt_number,weight:at.weight});
      api('mark',p).then(fetchState);
    }},
    {t:'Marcar como nulo', fn: ()=> {
      const p = {attempt_id:aid, result:'bad'};
      if(!at.data) Object.assign(p,{competitor_id:comp.id,lift_type:liftType,attempt_number:at.attempt_number,weight:at.weight});
      api('mark',p).then(fetchState);
    }},
    {t:'Record Estatal', fn: ()=> api('record',{attempt_id:aid,record_type:'State'}).then(fetchState)},
    {t:'Record Nacional', fn: ()=> api('record',{attempt_id:aid,record_type:'National'}).then(fetchState)},
    {t:'Record Mundial', fn: ()=> api('record',{attempt_id:aid,record_type:'World'}).then(fetchState)},
  ];
  
  actions.forEach(a => {
    const d = document.createElement('div');
    d.textContent = a.t;
    d.onclick = () => { pop.remove(); a.fn(); };
    pop.appendChild(d);
  });
  
  document.body.appendChild(pop);
  setTimeout(() => document.addEventListener('click', () => pop.remove(), {once:true}), 10);
}

function renderTimer(){
  const t = state.timer || {duration:60};
  let rem = t.duration || 60;
  if(t.running && t.started_at){
    rem = Math.max(0, t.duration - (Math.floor(Date.now()/1000) - t.started_at));
  }
  const mm = String(Math.floor(rem/60)).padStart(2,'0');
  const ss = String(rem%60).padStart(2,'0');
  document.getElementById('timer-display').textContent = mm+':'+ss;
  document.getElementById('timer-display').style.color = rem <= 10 ? '#f00' : (rem <= 30 ? '#ff0' : '#fff');
}

function renderRefLights(){
  const els = [document.getElementById('r1'),document.getElementById('r2'),document.getElementById('r3')];
  els.forEach(el => el.className = 'ref-light');
  
  if(!state.current_attempt){
    document.getElementById('ref-summary').textContent = 'Sin intento activo';
    return;
  }
  
  // Find current attempt's referee_calls
  let calls = [];
  state.competitors.forEach(c => {
    ['squats','bench','deadlift'].forEach(s => {
      c[s].forEach(a => {
        if(a.data && (a.data.id == state.current_attempt || a.data.attempt_id == state.current_attempt)){
          calls = a.data.referee_calls || [];
        }
      });
    });
  });
  
  const allVoted = calls.length >= 3;
  
  if(allVoted){
    // Reveal all votes
    calls.forEach(c => {
      const el = document.getElementById('r'+c.referee);
      if(el) el.classList.add(c.call);
    });
    const goods = calls.filter(c => c.call === 'good').length;
    document.getElementById('ref-summary').textContent = goods >= 2 ? '¡Intento válido!' : '¡Intento nulo!';
  } else {
    // Show who has voted (gray) but not their vote
    calls.forEach(c => {
      const el = document.getElementById('r'+c.referee);
      if(el) el.classList.add('voted');
    });
    document.getElementById('ref-summary').textContent = `${calls.length}/3 votos`;
  }
}

function renderRack(){
  const display = document.getElementById('lifter-name-display');
  const sqInput = document.getElementById('squat-rack');
  const bnInput = document.getElementById('bench-rack');
  const saveBtn = document.getElementById('save-rack');
  
  if(!state.current_attempt){
    display.textContent = '--';
    sqInput.value = ''; bnInput.value = '';
    sqInput.disabled = bnInput.disabled = saveBtn.disabled = true;
    return;
  }
  
  // Find current lifter
  let curr = null;
  state.competitors.forEach(c => {
    ['squats','bench','deadlift'].forEach(s => {
      c[s].forEach(a => {
        if(a.data && (a.data.id == state.current_attempt || a.data.attempt_id == state.current_attempt)) curr = c;
      });
    });
  });
  
  if(!curr){
    display.textContent = '--';
    sqInput.disabled = bnInput.disabled = saveBtn.disabled = true;
    return;
  }
  
  display.textContent = curr.name;
  sqInput.disabled = bnInput.disabled = saveBtn.disabled = false;
  
  // Fetch rack
  api('get_rack&competitor_id='+curr.id).then(r => {
    if(r.ok && r.rack){
      sqInput.value = r.rack.squat || '';
      bnInput.value = r.rack.bench || '';
    }
  });
}

// Event listeners
document.getElementById('timer-start').onclick = async (e) => {
  e.preventDefault();
  const duration = parseInt(document.getElementById('timer-duration').value) || 60;
  await api('timer',{cmd:'start',duration:duration});
  fetchState();
};
document.getElementById('timer-pause').onclick = async (e) => {
  e.preventDefault();
  await api('timer',{cmd:'pause'});
  fetchState();
};
document.getElementById('timer-reset').onclick = async (e) => {
  e.preventDefault();
  const duration = parseInt(document.getElementById('timer-duration').value) || 60;
  await api('timer',{cmd:'reset',duration:duration});
  fetchState();
};

document.getElementById('set-timer').onclick = async (e) => {
  e.preventDefault();
  const duration = parseInt(document.getElementById('timer-duration').value) || 60;
  await api('timer',{cmd:'set_duration',duration:duration});
  fetchState();
};

document.getElementById('save-equipment').onclick = async () => {
  const bar = parseFloat(document.getElementById('bar-weight').value) || 20;
  const collar = parseFloat(document.getElementById('collar-weight').value) || 0;
  await api('update_equipment', {bar_weight:bar, collar_weight:collar});
  alert('Equipment guardado');
};

document.getElementById('save-rack').onclick = async () => {
  let curr = null;
  state.competitors.forEach(c => {
    ['squats','bench','deadlift'].forEach(s => {
      c[s].forEach(a => {
        if(a.data && (a.data.id == state.current_attempt)) curr = c;
      });
    });
  });
  if(!curr) return;
  await api('update_rack', {
    competitor_id: curr.id,
    squat: document.getElementById('squat-rack').value,
    bench: document.getElementById('bench-rack').value
  });
  alert('Rack guardado');
};

document.getElementById('select-first').onclick = async () => {
  await fetchState();
  if(!state.competitors?.length) return alert('Sin competidores');
  const comp = state.competitors[0];
  const at = comp.squats[0];
  const aid = at.data ? at.data.id : -(comp.id*1000 + 1);
  await api('set_current',{attempt_id:aid});
  fetchState();
};

document.getElementById('toggle-autoscroll').onclick = () => {
  autoscroll = !autoscroll;
  document.getElementById('toggle-autoscroll').textContent = 'Autoscroll: ' + (autoscroll ? 'Activado' : 'Desactivado');
};

function esc(s){ return s == null ? '' : String(s); }

// Start polling
fetchState();
poll = setInterval(fetchState, 1000);
</script>
</body>
</html>
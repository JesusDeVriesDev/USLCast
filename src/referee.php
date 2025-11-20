<?php
// referee.php — Tablet del árbitro
session_start();
header('Content-Type: text/html; charset=utf-8');

// DB
$host="localhost"; $dbname="uslcast"; $user="postgres"; $pass="unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname",$user,$pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
]);

function json_out($v){ header('Content-Type: application/json'); echo json_encode($v); exit; }

// PARAMETERS
$meet_id = isset($_GET['meet']) ? (int)$_GET['meet'] : null;
$referee_num = isset($_GET['ref']) ? (int)$_GET['ref'] : null;

if (!$meet_id) die("Missing ?meet=ID");
if (!$referee_num || $referee_num < 1 || $referee_num > 3) die("Missing ?ref=1|2|3");

// Load meet (timer and current_attempt)
$stmt = $pdo->prepare("SELECT * FROM meets WHERE id=:id");
$stmt->execute(['id'=>$meet_id]);
$meet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$meet) die("Meet not found");

$settings = json_decode($meet['settings'] ?? '{}', true);
if (!is_array($settings)) $settings = [];

// ---------------------------------------------------
// AJAX: state (polling)
// ---------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'state') {

    $current_attempt = $settings['current_attempt'] ?? null;
    $attempt = null;

    if ($current_attempt) {
        // Load attempt
        if ($current_attempt > 0) {
            $q = $pdo->prepare("SELECT a.*, c.name, c.flight, c.lot_number 
                                FROM attempts a
                                JOIN competitors c ON c.id=a.competitor_id
                                WHERE a.id=:id");
            $q->execute(['id'=>$current_attempt]);
            $attempt = $q->fetch(PDO::FETCH_ASSOC);
            if ($attempt) {
                $attempt['referee_calls'] = json_decode($attempt['referee_calls'] ?? '[]', true);
            }
        } else {
            // Generated attempt (not persisted yet)
            // Try to obtain info from queue building logic
            // Minimal info: competitor, lift, attempt#
            $attempt = [
                "id" => $current_attempt,
                "weight" => null,
                "lift_type" => null,
                "attempt_number" => null,
                "name" => "Lifter",
                "flight" => "-",
                "lot_number" => "-"
            ];
        }
    }

    json_out([
        "ok"=>true,
        "current_attempt"=>$current_attempt,
        "attempt"=>$attempt
    ]);
}

// ---------------------------------------------------
// AJAX: call (GOOD/BAD)
// ---------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'call' && $_SERVER['REQUEST_METHOD']==='POST') {

    $data = json_decode(file_get_contents("php://input"), true);
    $attempt_id = $data['attempt_id'] ?? null;
    $call = $data['call'] ?? null;
    $ref = $data['referee'] ?? null;

    if (!$attempt_id) json_out(["ok"=>false,"error"=>"Missing attempt_id"]);
    if (!in_array($call,["good","bad"])) json_out(["ok"=>false,"error"=>"Invalid call"]);
    if (!$ref || $ref<1 || $ref>3) json_out(["ok"=>false,"error"=>"Invalid referee"]);

    // If attempt is generated (negative id) → cannot store calls
    if ((int)$attempt_id < 0) {
        json_out(["ok"=>false, "error"=>"Attempt not yet saved, scorer must set weight"]);
    }

    // Load existing referee_calls
    $q = $pdo->prepare("SELECT referee_calls FROM attempts WHERE id=:id");
    $q->execute(['id'=>$attempt_id]);
    $current = $q->fetchColumn();

    $calls = json_decode($current ?? "[]", true);
    if (!is_array($calls)) $calls = [];

    // Replace or add this referee's call
    $found = false;
    foreach ($calls as &$c) {
        if (isset($c['referee']) && (int)$c['referee']===(int)$ref) {
            $c['call'] = $call;
            $found = true;
        }
    }
    if (!$found) {
        $calls[] = ["referee"=>$ref, "call"=>$call];
    }

    // Save
    $upd = $pdo->prepare("UPDATE attempts SET referee_calls=:c WHERE id=:id");
    $upd->execute(['c'=>json_encode($calls), 'id'=>$attempt_id]);

    json_out(["ok"=>true]);
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Referee <?= $referee_num ?> — Lifting</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    body{background:#111;color:#fff;font-family:Arial;margin:0;padding:0;text-align:center}
    .header{padding:12px;font-size:1.2rem;font-weight:700;background:#222}
    .card{margin:20px auto;padding:20px;background:#181818;border-radius:8px;width:90%;max-width:420px;text-align:left}
    .lbl{color:#bbb;font-size:0.9rem;margin-top:6px}
    .bigbtn{
        width:90%;max-width:320px;
        padding:26px;margin:14px auto;
        font-size:2rem;font-weight:700;
        border-radius:10px;border:none;cursor:pointer;
    }
    .good{background:#0b6b2b;color:#fff}
    .bad{background:#6b0b0b;color:#fff}
    .wait{background:#444;color:#aaa}
</style>
</head>
<body>
<div class="header">Referee <?= $referee_num ?></div>

<div id="container">
    <div class="card">
        <div id="lift-info">Esperando intento...</div>
    </div>

    <button class="bigbtn good" id="btn-good">GOOD</button>
    <button class="bigbtn bad" id="btn-bad">BAD</button>

    <div class="lbl">Las luces aparecerán en la mesa del “Run”.</div>
</div>

<script>
const MEET_ID = <?= $meet_id ?>;
const REF = <?= $referee_num ?>;

let current_attempt = null;

// -------------------------------------------------------------------------------------
// Polling del intento actual
// -------------------------------------------------------------------------------------
async function poll() {
    try {
        const r = await fetch(`referee.php?meet=${MEET_ID}&ref=${REF}&ajax=state`);
        const j = await r.json();
        if (!j.ok) return;

        current_attempt = j.current_attempt;
        const a = j.attempt;

        const info = document.getElementById("lift-info");

        if (!current_attempt || !a) {
            info.innerHTML = "<div class='lbl'>Esperando intento actual...</div>";
            return;
        }

        let w = (a.weight===null ? "-" : a.weight+" kg");
        info.innerHTML = `
            <div style="font-size:1.3rem;font-weight:700">${a.name || "Lifter"}</div>
            <div class="lbl">Flight: ${a.flight || "-"} • Lot: ${a.lot_number || "-"}</div>
            <hr style="border-color:#333;margin:12px 0">
            <div><strong>${a.lift_type || "-"}</strong> — Intento #${a.attempt_number || "-"}</div>
            <div class="lbl" style="margin-top:4px">Peso: ${w}</div>
        `;
    } catch(e){
        console.error(e);
    }
}
setInterval(poll, 1000);
poll();

// -------------------------------------------------------------------------------------
// Enviar voto GOOD/BAD
// -------------------------------------------------------------------------------------
async function sendCall(callType){
    if (!current_attempt) {
        alert("No hay intento para votar.");
        return;
    }

    const payload = {
        attempt_id: current_attempt,
        referee: REF,
        call: callType
    };

    const r = await fetch(`referee.php?meet=${MEET_ID}&ref=${REF}&ajax=call`, {
        method:"POST",
        headers:{"Content-Type":"application/json"},
        body: JSON.stringify(payload)
    });
    const j = await r.json();

    if (!j.ok) {
        alert("Error: "+(j.error || "desconocido"));
        return;
    }

    flash(callType);
}

// Pequeño feedback visual
function flash(type){
    const btn = (type==="good") ? document.getElementById("btn-good") : document.getElementById("btn-bad");
    btn.classList.add("wait");
    setTimeout(()=> btn.classList.remove("wait"), 600);
}

// Eventos
document.getElementById("btn-good").onclick = ()=> sendCall("good");
document.getElementById("btn-bad").onclick = ()=> sendCall("bad");

</script>
</body>
</html>

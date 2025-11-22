<?php
require_once 'database.php';
try { $pdo = new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch(Exception $e){ die("DB error: ".$e->getMessage()); }
$action = $_GET['action'] ?? null;

// === LISTAR ===
if ($action === 'list' && isset($_GET['meet_id'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM platforms WHERE meet_id = :m ORDER BY id ASC");
    $stmt->execute(['m' => $_GET['meet_id']]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// === ELIMINAR ===
if ($action === 'delete' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("DELETE FROM platforms WHERE id = :id");
    $stmt->execute(['id' => $_GET['id']]);
    echo json_encode(['message' => '✅ Plataforma eliminada.']);
    exit;
}

// === OBTENER PLATES ===
if ($action === 'get_plates' && isset($_GET['platform_id'])) {
    $pid = (int) $_GET['platform_id'];
    $stmt = $pdo->prepare("SELECT plate_colors FROM platforms WHERE id = :id");
    $stmt->execute(['id' => $pid]);
    $data = $stmt->fetchColumn();

    if ($data === false) {
        echo "<div class='alert alert-danger'>❌ Plataforma no encontrada.</div>";
        exit;
    }

    $plates = [
        "50 KG" => "#00FF00", "25 KG" => "#FF0000", "20 KG" => "#0000FF",
        "15 KG" => "#FFFF00", "10 KG" => "#FFFFFF", "5 KG" => "#000000",
        "2.5 KG" => "#CCCCCC", "2 KG" => "#CCCCCC", "1.25 KG" => "#CCCCCC",
        "1 KG" => "#CCCCCC", "0.5 KG" => "#CCCCCC", "0.25 KG" => "#CCCCCC"
    ];
    $saved = json_decode($data, true) ?: [];

    ob_start();
    echo "<table id='plates-table' class='table table-dark table-bordered text-center'>";
    echo "<thead class='table-light'><tr><th>Disco</th><th># de pares</th><th>Color</th></tr></thead><tbody>";
    foreach ($plates as $w => $c) {
        $pairs = $saved[$w]['pairs'] ?? 0;
        $color = $saved[$w]['color'] ?? $c;
        echo "<tr>
                <td class='plate-weight'>$w</td>
                <td><input type='number' class='form-control bg-dark text-light plate-pairs' value='$pairs' min='0'></td>
                <td><input type='color' class='form-control form-control-color plate-color' value='$color'></td>
              </tr>";
    }
    echo "</tbody></table>";
    echo ob_get_clean();
    exit;
}

// === GUARDAR PLATAFORMAS O DISCO ===
$data = json_decode(file_get_contents("php://input"), true);

if (isset($data['meet_id'], $data['platforms'])) {
    foreach ($data['platforms'] as $p) {
        if ($p['id'] === "new") {
            $stmt = $pdo->prepare("INSERT INTO platforms (meet_id, name) VALUES (:m, :n)");
            $stmt->execute(['m' => $data['meet_id'], 'n' => $p['name']]);
        } else {
            $stmt = $pdo->prepare("UPDATE platforms SET name = :n WHERE id = :id");
            $stmt->execute(['n' => $p['name'], 'id' => $p['id']]);
        }
    }
    echo json_encode(['message' => '✅ Plataformas guardadas correctamente.']);
    exit;
}

if (isset($data['platform_id'], $data['plate_colors'])) {
    $stmt = $pdo->prepare("UPDATE platforms SET plate_colors = :c WHERE id = :id");
    $stmt->execute([
        'c' => json_encode($data['plate_colors']),
        'id' => $data['platform_id']
    ]);
    echo json_encode(['message' => '✅ Configuración de discos guardada.']);
    exit;
}

echo json_encode(['message' => '❌ Acción no válida.']);

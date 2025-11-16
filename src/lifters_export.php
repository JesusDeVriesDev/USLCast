<?php
session_start();
$sessionUserId = $_SESSION['user_id'] ?? $_SESSION['session_user_id'] ?? null;
if (!$sessionUserId) { die("No autorizado"); }

$host = "localhost"; $dbname = "uslcast"; $user = "postgres"; $pass = "unicesmag";
$pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$meet_id = isset($_GET['meet_id']) ? (int)$_GET['meet_id'] : null;
if (!$meet_id) die("ID de competencia no especificado.");

// Verify meet belongs to organizer
$stmt = $pdo->prepare("SELECT name FROM meets WHERE id = :id AND organizer_id = :org");
$stmt->execute(['id'=>$meet_id,'org'=>$sessionUserId]);
$meet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$meet) die("Competencia no encontrada.");

// Get lifters with divisions
$stmt = $pdo->prepare("
  SELECT 
    c.name,
    c.team,
    c.lot_number,
    p.name as platform_name,
    c.session,
    c.flight,
    c.dob,
    c.gender,
    c.body_weight,
    c.rack_height,
    c.membership_number,
    c.email,
    c.phone,
    STRING_AGG(DISTINCT d.name, '; ') as divisions,
    STRING_AGG(DISTINCT cd.declared_weight_class, '; ') as weight_classes
  FROM competitors c
  LEFT JOIN platforms p ON c.platform_id = p.id
  LEFT JOIN competitor_divisions cd ON c.id = cd.competitor_id
  LEFT JOIN divisions d ON cd.division_id = d.id
  WHERE c.meet_id = :mid
  GROUP BY c.id, p.name
  ORDER BY c.lot_number, c.name
");
$stmt->execute(['mid'=>$meet_id]);
$lifters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
$filename = "levantadores_" . preg_replace('/[^a-z0-9]+/', '_', strtolower($meet['name'])) . "_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Output CSV
$output = fopen('php://output', 'w');

// BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header row
fputcsv($output, [
  'Nombre','Equipo','Lote','Plataforma','Sesión','Vuelo','Fecha Nacimiento','Género',
  'Peso Corporal','Altura Squat','Altura Bench','Clase de Peso','División(es)','Membresía','Email','Teléfono'
]);

// Data rows
foreach($lifters as $l) {
  $rack = json_decode($l['rack_height'] ?? '{}', true);
  fputcsv($output, [
    $l['name'],
    $l['team'],
    $l['lot_number'],
    $l['platform_name'],
    $l['session'],
    $l['flight'],
    $l['dob'],
    $l['gender'],
    $l['body_weight'],
    $rack['squat'] ?? '',
    $rack['bench'] ?? '',
    $l['weight_classes'],
    $l['divisions'],
    $l['membership_number'],
    $l['email'],
    $l['phone']
  ]);
}

fclose($output);
exit;
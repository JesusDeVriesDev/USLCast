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

// Get lifters with ALL their divisions (multiple rows per lifter if multiple divisions)
$stmt = $pdo->prepare("
  SELECT 
    c.id,
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
    d.name as division_name,
    d.gender as division_gender,
    d.type as division_type,
    cd.raw_or_equipped,
    cd.declared_weight_class
  FROM competitors c
  LEFT JOIN platforms p ON c.platform_id = p.id
  LEFT JOIN competitor_divisions cd ON c.id = cd.competitor_id
  LEFT JOIN divisions d ON cd.division_id = d.id
  WHERE c.meet_id = :mid
  ORDER BY c.lot_number, c.name, d.name, cd.declared_weight_class
");
$stmt->execute(['mid'=>$meet_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by lifter to show all divisions
$lifters = [];
foreach($rows as $row) {
  $id = $row['id'];
  if (!isset($lifters[$id])) {
    $lifters[$id] = [
      'name' => $row['name'],
      'team' => $row['team'],
      'lot_number' => $row['lot_number'],
      'platform_name' => $row['platform_name'],
      'session' => $row['session'],
      'flight' => $row['flight'],
      'dob' => $row['dob'],
      'gender' => $row['gender'],
      'body_weight' => $row['body_weight'],
      'rack_height' => $row['rack_height'],
      'membership_number' => $row['membership_number'],
      'email' => $row['email'],
      'phone' => $row['phone'],
      'divisions' => []
    ];
  }
  
  if ($row['division_name']) {
    $lifters[$id]['divisions'][] = [
      'name' => $row['division_name'],
      'gender' => $row['division_gender'],
      'type' => $row['division_type'],
      'raw_or_equipped' => $row['raw_or_equipped'],
      'weight_class' => $row['declared_weight_class']
    ];
  }
}

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
  'Peso Corporal','Altura Squat','Altura Bench','Divisiones (todas)','Membresía','Email','Teléfono'
]);

// Data rows
foreach($lifters as $l) {
  $rack = json_decode($l['rack_height'] ?? '{}', true);
  
  // Format all divisions
  $divisionStr = '';
  foreach($l['divisions'] as $div) {
    if ($divisionStr) $divisionStr .= '; ';
    $divisionStr .= $div['name'] . ' ' . $div['gender'] . ' ' . $div['type'] . ' ' . $div['raw_or_equipped'] . ' (' . $div['weight_class'] . ')';
  }
  
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
    $divisionStr,
    $l['membership_number'],
    $l['email'],
    $l['phone']
  ]);
}

fclose($output);
exit;
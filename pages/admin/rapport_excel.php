<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/rapports.php";
exiger_role(['ADMIN']);

$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare(
    "SELECT r.*, u.login, e.nom AS etab
     FROM rapport r
     JOIN utilisateur u ON u.id_user = r.id_user
     LEFT JOIN etablissement e ON e.code_etab = r.code_etab
     WHERE r.id_rapport = ?"
);
$st->execute([$id]);
$r = $st->fetch();
if (!$r) {
    http_response_code(404);
    die("Rapport introuvable.");
}

$d = rapport_donnees($pdo, $r['type'], $r['date_debut'], $r['date_fin'], $r['code_etab'] ?? '');
$titre = RAPPORT_TYPES[$r['type']] ?? $r['type'];

$pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
    ->execute(['RAPPORT_EXPORT', $r['numero'] . " (Excel)", $_SESSION['user']['id']]);

// Empêche qu'une cellule commençant par = + - @ soit interprétée comme une formule par Excel
function csv_sur($v) {
    $v = (string)$v;
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) { return "'" . $v; }
    return $v;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $r['numero'] . '.csv"');
header('Cache-Control: no-store');

echo "\xEF\xBB\xBF";   // marque UTF-8 : Excel affiche correctement les accents
$f = fopen('php://output', 'w');

// Séparateur « ; » : Excel en version française sépare alors les colonnes automatiquement
fputcsv($f, [$titre], ';', '"', '\\');
fputcsv($f, ['Numéro', $r['numero']], ';', '"', '\\');
fputcsv($f, ['Période', rap_fr($r['date_debut']) . ' - ' . rap_fr($r['date_fin'])], ';', '"', '\\');
fputcsv($f, ['Établissement', $r['etab'] ?: 'Tous les établissements'], ';', '"', '\\');
fputcsv($f, ['Généré par', $r['login'] . ' le ' . date('d/m/Y H:i', strtotime($r['date_generation']))], ';', '"', '\\');
fputcsv($f, [], ';', '"', '\\');
fputcsv($f, $d['colonnes'], ';', '"', '\\');
foreach ($d['lignes'] as $ligne) {
    fputcsv($f, array_map('csv_sur', $ligne), ';', '"', '\\');
}
fclose($f);

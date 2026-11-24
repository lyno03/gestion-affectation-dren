<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/agents.php";
exiger_role(['ADMIN', 'RH', 'DIR']);

$f = agents_filtres($_GET);
[$from, $params] = agents_from_where($f);

// Lignes cochées dans la liste (matricules de 5 à 10 chiffres uniquement)
$sel = [];
if (is_string($_GET['sel'] ?? null)) {
    foreach (explode(',', $_GET['sel']) as $m) {
        $m = trim($m);
        if (preg_match('/^[0-9]{5,10}$/', $m)) { $sel[] = $m; }
    }
    $sel = array_slice(array_unique($sel), 0, 500);
}
if ($sel) {
    $from .= " AND a.matricule IN (" . implode(',', array_fill(0, count($sel), '?')) . ")";
    $params = array_merge($params, $sel);
}

$st = $pdo->prepare(
    "SELECT a.matricule, a.nom, a.prenoms, a.sexe, g.libelle AS grade, f.libelle AS fonction,
            e.nom AS etab, c.nom AS cisco, a.statut, a.telephone
     $from ORDER BY a.nom, a.prenoms"
);
$st->execute($params);
$lignes = $st->fetchAll();

$pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
    ->execute(['EXPORT_PERSONNELS', count($lignes) . ' personnel(s) exporté(s)' . ($sel ? ' (sélection)' : ''),
               $_SESSION['user']['id']]);

// Empêche qu'une cellule commençant par = + - @ soit prise pour une formule par Excel
function csv_sur($v) {
    $v = (string)$v;
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) { return "'" . $v; }
    return $v;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="personnels_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

echo "\xEF\xBB\xBF";   // marque UTF-8 : Excel affiche correctement les accents
$out = fopen('php://output', 'w');
fputcsv($out, ['N°', 'Matricule', 'Nom', 'Prénoms', 'Sexe', 'Grade', 'Fonction',
               'Établissement actuel', 'CISCO', 'Statut', 'Téléphone'], ';', '"', '\\');
foreach ($lignes as $i => $r) {
    fputcsv($out, array_map('csv_sur', [
        $i + 1, $r['matricule'], $r['nom'], $r['prenoms'],
        $r['sexe'] === 'F' ? 'Féminin' : ($r['sexe'] === 'M' ? 'Masculin' : ''),
        $r['grade'], $r['fonction'], $r['etab'], $r['cisco'], $r['statut'], $r['telephone'],
    ]), ';', '"', '\\');
}
fclose($out);

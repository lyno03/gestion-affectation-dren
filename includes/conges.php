<?php
// Fonctions communes aux demandes de congé du personnel
require_once __DIR__ . '/demandes.php';   // flash(), dem_str(), dem_date_ok()

// Types de congé : 'fixe' = durée imposée (la date de fin est calculée automatiquement)
const CONGE_TYPES = [
    'Congé annuel'       => ['unite' => 'jours', 'nombre' => 30, 'libelle' => '30 jours'],
    'Congé de maternité' => ['unite' => 'mois',  'nombre' => 3,  'libelle' => '3 mois'],
    'Congé de maladie'   => null,
    'Congé exceptionnel' => null,
];
// code => [libellé, classe de couleur]
const CONGE_STATUTS = [
    'EN_ATTENTE' => ['En attente', 'attente'],
    'ACCEPTEE'   => ['Acceptée', 'acceptee'],
    'REFUSEE'    => ['Refusée', 'refusee'],
    'ANNULEE'    => ['Annulée', 'annulee'],
];

// Date de fin d'un congé à durée fixe (le jour de début compte). Null si la durée est libre.
function conge_calculer_fin($type, $debut) {
    $r = CONGE_TYPES[$type] ?? null;
    if (!$r) { return null; }
    $d = new DateTime($debut);
    $n = $r['nombre'];
    if ($r['unite'] === 'jours') {
        return $d->modify('+' . ($n - 1) . ' days')->format('Y-m-d');
    }
    $jour  = (int)$d->format('j');
    $cible = (clone $d)->modify('first day of this month')->modify("+$n months");
    $max   = (int)$cible->format('t');
    if ($jour > $max) {                       // ex. 30/11 -> fin de février
        return $cible->format('Y-m-') . $max;
    }
    return $cible->setDate((int)$cible->format('Y'), (int)$cible->format('n'), $jour)
                 ->modify('-1 day')->format('Y-m-d');
}

// Nombre de jours calendaires, début et fin inclus
function conge_nb_jours($debut, $fin) {
    return (int)(new DateTime($debut))->diff(new DateTime($fin))->days + 1;
}

// Numéro suivant : CGE-2026-001, CGE-2026-002...
function conge_numero_suivant(PDO $pdo) {
    $an = date('Y');
    $st = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 10) AS UNSIGNED)), 0) FROM demande_conge WHERE numero LIKE ?"
    );
    $st->execute(["CGE-$an-%"]);
    return sprintf('CGE-%s-%03d', $an, (int)$st->fetchColumn() + 1);
}

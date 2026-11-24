<?php
// Fonctions communes à la liste des personnels et à son export

function ag_str($v) {
    return is_string($v) ? trim($v) : '';
}

// Filtres lus dans l'adresse (recherche, fonction, statut, établissement)
function agents_filtres(array $src) {
    return [
        'q'        => ag_str($src['q'] ?? null),
        'fonction' => ag_str($src['fonction'] ?? null),
        'statut'   => ag_str($src['statut'] ?? null),
        'etab'     => ag_str($src['etab'] ?? null),
    ];
}

// Partie FROM + WHERE commune, limitée au périmètre de l'utilisateur connecté
// Retourne [texte SQL, paramètres]
function agents_from_where(array $f) {
    [$per, $pp] = function_exists('filtre_agent') ? filtre_agent('a') : ['1=1', []];

    $sql = " FROM agent a
             LEFT JOIN grade g ON g.code_grade = a.code_grade
             LEFT JOIN fonction f ON f.code_fonction = a.code_fonction
             LEFT JOIN affectation af ON af.matricule = a.matricule AND af.date_fin IS NULL
             LEFT JOIN etablissement e ON e.code_etab = af.code_etab
             LEFT JOIN cisco c ON c.code_cisco = e.code_cisco
             WHERE $per AND (a.nom LIKE ? OR a.prenoms LIKE ? OR a.matricule LIKE ?)";
    $like = '%' . $f['q'] . '%';
    $p = array_merge($pp, [$like, $like, $like]);

    if ($f['fonction'] !== '') { $sql .= " AND a.code_fonction = ?"; $p[] = $f['fonction']; }
    if ($f['statut'] !== '')   { $sql .= " AND a.statut = ?";        $p[] = $f['statut']; }
    if ($f['etab'] !== '')     { $sql .= " AND af.code_etab = ?";    $p[] = $f['etab']; }

    return [$sql, $p];
}

// Classe de couleur du badge de statut
function ag_classe_statut($s) {
    if ($s === 'En poste') { return 'poste'; }
    if (strpos($s, 'Congé') === 0) { return 'conge'; }
    if (strpos($s, 'En attente') === 0) { return 'attente'; }
    if ($s === 'Retraité') { return 'retraite'; }
    return 'autre';
}

<?php
// Fonctions communes au module Rapports (page, consultation, export Excel)

const RAPPORT_TYPES = [
    'PERSONNELS'     => 'Liste des personnels',
    'AFFECTATIONS'   => 'Liste des affectations',
    'DEMANDES'       => 'Demandes de mutation traitées',
    'ETABLISSEMENTS' => 'Rapport par établissement',
    'STATS'          => 'Statistiques générales',
];
const RAPPORT_FORMATS = ['PDF' => 'PDF', 'EXCEL' => 'Excel'];

// Texte reçu d'un formulaire ou d'une adresse (ignore les tableaux)
function rap_str($v, $defaut = '') {
    return is_string($v) ? trim($v) : $defaut;
}

function rap_date_ok($d) {
    if (!is_string($d)) { return false; }
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

function rap_fr($d) {
    return $d ? date('d/m/Y', strtotime($d)) : '—';
}

// Exécute une requête qui renvoie un seul nombre
function rap_val(PDO $pdo, $sql, array $params = []) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

// Chiffres clés de la période (et de l'établissement choisi, s'il y en a un)
function rapport_stats(PDO $pdo, $debut, $fin, $etab = '') {
    $e  = $etab !== '';
    $ce = $e ? " AND af.code_etab = ?" : "";
    $pe = $e ? [$etab] : [];

    if ($e) {
        $personnels = rap_val($pdo,
            "SELECT COUNT(DISTINCT af.matricule) FROM affectation af
             WHERE af.code_etab = ? AND af.date_debut <= ? AND (af.date_fin IS NULL OR af.date_fin >= ?)",
            [$etab, $fin, $debut]);
    } else {
        $personnels = rap_val($pdo,
            "SELECT COUNT(*) FROM agent a WHERE a.date_recrutement IS NULL OR a.date_recrutement <= ?",
            [$fin]);
    }

    $en_poste = rap_val($pdo,
        "SELECT COUNT(DISTINCT af.matricule) FROM affectation af WHERE af.date_fin IS NULL" . $ce, $pe);

    $affectations = rap_val($pdo,
        "SELECT COUNT(*) FROM affectation af WHERE af.date_debut BETWEEN ? AND ?" . $ce,
        array_merge([$debut, $fin], $pe));

    $mois = rap_val($pdo,
        "SELECT COUNT(*) FROM affectation af WHERE af.date_debut BETWEEN ? AND ?" . $ce,
        array_merge([date('Y-m-01'), date('Y-m-t')], $pe));

    // Une « demande traitée » = une mutation accordée sur demande de l'agent
    $demandes = rap_val($pdo,
        "SELECT COUNT(*) FROM affectation af
         WHERE af.motif = 'Mutation sur demande' AND af.date_debut BETWEEN ? AND ?" . $ce,
        array_merge([$debut, $fin], $pe));

    $etablissements = rap_val($pdo,
        "SELECT COUNT(*) FROM etablissement" . ($e ? " WHERE code_etab = ?" : ""), $pe);

    $avec_personnel = rap_val($pdo,
        "SELECT COUNT(DISTINCT af.code_etab) FROM affectation af WHERE af.date_fin IS NULL" . $ce, $pe);

    return compact('personnels', 'en_poste', 'affectations', 'mois', 'demandes',
                   'etablissements', 'avec_personnel');
}

// Colonnes et lignes d'un rapport
function rapport_donnees(PDO $pdo, $type, $debut, $fin, $etab = '') {
    $e = $etab !== '';
    $colonnes = [];
    $lignes = [];

    switch ($type) {

        case 'PERSONNELS':
            $colonnes = ['Matricule', 'Nom et prénoms', 'Grade', 'Fonction', 'Poste actuel', 'Statut'];
            $sql = "SELECT a.matricule, TRIM(CONCAT(a.nom, ' ', COALESCE(a.prenoms, ''))) AS agent,
                           g.libelle AS grade, f.libelle AS fonction,
                           COALESCE(e.nom, 'Sans poste') AS poste, a.statut
                    FROM agent a
                    LEFT JOIN grade g ON g.code_grade = a.code_grade
                    LEFT JOIN fonction f ON f.code_fonction = a.code_fonction
                    LEFT JOIN affectation af ON af.matricule = a.matricule AND af.date_fin IS NULL
                    LEFT JOIN etablissement e ON e.code_etab = af.code_etab
                    WHERE (a.date_recrutement IS NULL OR a.date_recrutement <= ?)";
            $p = [$fin];
            if ($e) {
                $sql .= " AND EXISTS (SELECT 1 FROM affectation x
                                      WHERE x.matricule = a.matricule AND x.code_etab = ?
                                        AND x.date_debut <= ? AND (x.date_fin IS NULL OR x.date_fin >= ?))";
                array_push($p, $etab, $fin, $debut);
            }
            $sql .= " ORDER BY a.nom, a.prenoms";
            $st = $pdo->prepare($sql);
            $st->execute($p);
            foreach ($st as $r) {
                $lignes[] = [$r['matricule'], $r['agent'], $r['grade'] ?? '—',
                             $r['fonction'] ?? '—', $r['poste'], $r['statut']];
            }
            break;

        case 'AFFECTATIONS':
        case 'DEMANDES':
            $colonnes = ["Date d'effet", 'Agent', 'Matricule', 'Établissement', 'CISCO', 'Motif', 'Référence'];
            $sql = "SELECT af.date_debut, TRIM(CONCAT(a.nom, ' ', COALESCE(a.prenoms, ''))) AS agent,
                           a.matricule, e.nom AS etab, c.nom AS cisco, af.motif,
                           COALESCE(af.reference_acte, '—') AS ref
                    FROM affectation af
                    JOIN agent a ON a.matricule = af.matricule
                    JOIN etablissement e ON e.code_etab = af.code_etab
                    JOIN cisco c ON c.code_cisco = e.code_cisco
                    WHERE af.date_debut BETWEEN ? AND ?";
            $p = [$debut, $fin];
            if ($type === 'DEMANDES') { $sql .= " AND af.motif = 'Mutation sur demande'"; }
            if ($e) { $sql .= " AND af.code_etab = ?"; $p[] = $etab; }
            $sql .= " ORDER BY af.date_debut DESC, af.id_affectation DESC";
            $st = $pdo->prepare($sql);
            $st->execute($p);
            foreach ($st as $r) {
                $lignes[] = [rap_fr($r['date_debut']), $r['agent'], $r['matricule'],
                             $r['etab'], $r['cisco'], $r['motif'], $r['ref']];
            }
            break;

        case 'ETABLISSEMENTS':
            $colonnes = ['Établissement', 'CISCO', 'Type', 'Effectif actuel', 'Arrivées (période)', 'Départs (période)'];
            $sql = "SELECT e.nom, c.nom AS cisco, e.type,
                      (SELECT COUNT(*) FROM affectation a WHERE a.code_etab = e.code_etab AND a.date_fin IS NULL) AS effectif,
                      (SELECT COUNT(*) FROM affectation a WHERE a.code_etab = e.code_etab AND a.date_debut BETWEEN ? AND ?) AS arrivees,
                      (SELECT COUNT(*) FROM affectation a WHERE a.code_etab = e.code_etab AND a.date_fin BETWEEN ? AND ?) AS departs
                    FROM etablissement e
                    JOIN cisco c ON c.code_cisco = e.code_cisco";
            $p = [$debut, $fin, $debut, $fin];
            if ($e) { $sql .= " WHERE e.code_etab = ?"; $p[] = $etab; }
            $sql .= " ORDER BY c.nom, e.nom";
            $st = $pdo->prepare($sql);
            $st->execute($p);
            foreach ($st as $r) {
                $lignes[] = [$r['nom'], $r['cisco'], $r['type'],
                             (int)$r['effectif'], (int)$r['arrivees'], (int)$r['departs']];
            }
            break;

        case 'STATS':
            $colonnes = ['Indicateur', 'Valeur'];
            $s = rapport_stats($pdo, $debut, $fin, $etab);
            $lignes = [
                ['Personnels', $s['personnels']],
                ['Agents actuellement en poste', $s['en_poste']],
                ['Affectations effectuées (période)', $s['affectations']],
                ['Demandes de mutation traitées (période)', $s['demandes']],
                ['Établissements', $s['etablissements']],
                ['Établissements avec du personnel', $s['avec_personnel']],
                ['Établissements sans personnel', max(0, $s['etablissements'] - $s['avec_personnel'])],
            ];
            if (!$e) {
                $lignes[] = ["Agents en attente d'affectation", rap_val($pdo,
                    "SELECT COUNT(*) FROM agent a WHERE NOT EXISTS
                     (SELECT 1 FROM affectation af WHERE af.matricule = a.matricule AND af.date_fin IS NULL)")];
            }
            break;
    }

    return ['colonnes' => $colonnes, 'lignes' => $lignes];
}

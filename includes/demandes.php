<?php
// Fonctions communes au circuit des demandes d'affectation

const DEMANDE_MOTIFS = ['Première affectation', 'Mutation sur demande', 'Nécessité de service',
                        'Retour de congé', 'Promotion'];
// code => [libellé, classe de couleur]
const DEMANDE_STATUTS = [
    'EN_ATTENTE' => ['En attente', 'attente'],
    'ACCEPTEE'   => ['Acceptée', 'acceptee'],
    'REFUSEE'    => ['Refusée', 'refusee'],
];

function dem_str($v) {
    return is_string($v) ? trim($v) : '';
}

function dem_date_ok($d) {
    if (!is_string($d)) { return false; }
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

// Message affiché une seule fois après une redirection
function flash($type, $message) {
    $_SESSION['flash'] = [$type, $message];
}
function flash_lire() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// Numéro suivant : DEM-2026-001, DEM-2026-002...
function dem_numero_suivant(PDO $pdo) {
    $an = date('Y');
    $st = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 10) AS UNSIGNED)), 0) FROM demande WHERE numero LIKE ?"
    );
    $st->execute(["DEM-$an-%"]);
    return sprintf('DEM-%s-%03d', $an, (int)$st->fetchColumn() + 1);
}

// Crée l'affectation d'un agent : ferme l'ancienne, ouvre la nouvelle, met à jour le statut.
// À appeler dans une transaction. Lance une RuntimeException si une règle de gestion n'est pas respectée.
// Retourne l'identifiant de la nouvelle affectation.
function appliquer_affectation(PDO $pdo, $matricule, $code_etab, $date_debut, $motif, $reference, $code_acte) {
    if (!$pdo->inTransaction()) {
        throw new LogicException("appliquer_affectation() doit être appelée dans une transaction.");
    }

    $st = $pdo->prepare("SELECT date_recrutement FROM agent WHERE matricule = ? FOR UPDATE");
    $st->execute([$matricule]);
    $agent = $st->fetch();
    if (!$agent) { throw new RuntimeException("Agent introuvable."); }

    $st = $pdo->prepare("SELECT COUNT(*) FROM etablissement WHERE code_etab = ?");
    $st->execute([$code_etab]);
    if ($st->fetchColumn() == 0) { throw new RuntimeException("Établissement introuvable."); }

    // « Première affectation » seulement si l'agent n'a aucun historique
    if ($motif === 'Première affectation') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM affectation WHERE matricule = ?");
        $st->execute([$matricule]);
        if ($st->fetchColumn() > 0) {
            throw new RuntimeException("Le motif « Première affectation » ne convient pas : cet agent a déjà un historique.");
        }
    }

    if ($agent['date_recrutement'] && $date_debut < $agent['date_recrutement']) {
        throw new RuntimeException("La date d'effet est antérieure au recrutement de l'agent.");
    }

    $st = $pdo->prepare(
        "SELECT id_affectation, code_etab, date_debut
         FROM affectation WHERE matricule = ? AND date_fin IS NULL FOR UPDATE"
    );
    $st->execute([$matricule]);
    $actuelle = $st->fetch();

    if ($actuelle) {
        if ($actuelle['code_etab'] === $code_etab) {
            throw new RuntimeException("L'agent est déjà affecté à ce poste.");
        }
        if ($date_debut <= $actuelle['date_debut']) {
            throw new RuntimeException("La date d'effet doit être postérieure au début de l'affectation actuelle ("
                . date("d/m/Y", strtotime($actuelle['date_debut'])) . ").");
        }
        // L'ancienne affectation se termine la veille de la nouvelle
        $pdo->prepare("UPDATE affectation SET date_fin = DATE_SUB(?, INTERVAL 1 DAY) WHERE id_affectation = ?")
            ->execute([$date_debut, $actuelle['id_affectation']]);
    }

    $pdo->prepare(
        "INSERT INTO affectation (matricule, code_etab, date_debut, motif, reference_acte, code_acte)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$matricule, $code_etab, $date_debut, $motif, $reference ?: null, $code_acte ?: null]);
    $id = (int)$pdo->lastInsertId();

    // Un agent en attente devient « En poste » ; un agent en congé garde son statut de congé
    $pdo->prepare("UPDATE agent SET statut = 'En poste' WHERE matricule = ? AND statut = 'En attente d''affectation'")
        ->execute([$matricule]);

    return $id;
}

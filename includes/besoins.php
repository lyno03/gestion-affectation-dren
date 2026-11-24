<?php
// Fonctions communes aux demandes de personnel (besoins des établissements)

const BESOIN_CATEGORIES = ['ADMINISTRATIF' => 'Administratif', 'ENSEIGNANT' => 'Enseignant'];
const BESOIN_URGENCES   = ['NORMALE' => 'Normale', 'URGENTE' => 'Urgente'];
// code => [libellé, classe de couleur]
const BESOIN_STATUTS = [
    'EN_ATTENTE' => ['En attente', 'attente'],
    'ACCEPTEE'   => ['Acceptée', 'acceptee'],
    'REFUSEE'    => ['Refusée', 'refusee'],
];

function bes_str($v) {
    return is_string($v) ? trim($v) : '';
}

// Message affiché une seule fois après une redirection
function bes_flash($type, $message) {
    $_SESSION['flash_bes'] = [$type, $message];
}
function bes_flash_lire() {
    $f = $_SESSION['flash_bes'] ?? null;
    unset($_SESSION['flash_bes']);
    return $f;
}

// Les scripts SQL ont-ils été exécutés ? (colonne categorie, tables demande_personnel et poste_a_pourvoir)
function bes_tables_ok(PDO $pdo) {
    try {
        $pdo->query("SELECT categorie FROM fonction LIMIT 1");
        $pdo->query("SELECT 1 FROM demande_personnel LIMIT 1");
        $pdo->query("SELECT 1 FROM poste_a_pourvoir LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Numéro suivant : DP-2026-001, DP-2026-002...
function bes_numero_suivant(PDO $pdo) {
    $an = date('Y');
    $st = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 9) AS UNSIGNED)), 0) FROM demande_personnel WHERE numero LIKE ?"
    );
    $st->execute(["DP-$an-%"]);
    return sprintf('DP-%s-%03d', $an, (int)$st->fetchColumn() + 1);
}

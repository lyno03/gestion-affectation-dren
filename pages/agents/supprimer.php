<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN']);

// Cette page n'accepte que l'envoi du formulaire de la liste
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: liste.php");
    exit;
}
if (!verifier_csrf($_POST['csrf'] ?? '')) {
    die("Requête invalide.");
}

$matricule = is_string($_POST['matricule'] ?? null) ? trim($_POST['matricule']) : '';

$st = $pdo->prepare("SELECT matricule, nom, prenoms FROM agent WHERE matricule = ?");
$st->execute([$matricule]);
$agent = $st->fetch();

if (!$agent) {
    header("Location: liste.php?m=inconnu");
    exit;
}

// Tout personnel a au moins son affectation initiale. On ne le supprime que s'il n'a connu
// aucun mouvement : une seule affectation, aucune situation, aucune demande.
// L'historique d'un personnel qui a bougé n'est jamais effacé (règle RG6).
$st = $pdo->prepare("SELECT COUNT(*) FROM affectation WHERE matricule = ?");
$st->execute([$matricule]);
$nb_affectations = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM situation WHERE matricule = ?");
$st->execute([$matricule]);
$nb_situations = (int)$st->fetchColumn();

$nb_demandes = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM demande WHERE matricule = ?");
    $st->execute([$matricule]);
    $nb_demandes = (int)$st->fetchColumn();
} catch (PDOException $e) { /* le module des demandes n'est pas installé */ }

if ($nb_affectations > 1 || $nb_situations > 0 || $nb_demandes > 0) {
    header("Location: liste.php?m=histo");
    exit;
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM affectation WHERE matricule = ?")->execute([$matricule]);
    $pdo->prepare("DELETE FROM agent WHERE matricule = ?")->execute([$matricule]);
    $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
        ->execute(['AGENT_SUPPR', "Agent $matricule ({$agent['nom']}) supprimé", $_SESSION['user']['id']]);
    $pdo->commit();
    header("Location: liste.php?m=suppr");
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    header("Location: liste.php?m=erreur");
}
exit;

<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/conges.php";
exiger_role(['DIR', 'RH', 'CISCO', 'ETAB']);   // DIR décide ; les autres peuvent seulement annuler leur demande

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: liste.php");
    exit;
}
if (!verifier_csrf($_POST['csrf'] ?? '')) { die("Requête invalide."); }

$id = (int)($_POST['id_conge'] ?? 0);
$decision = dem_str($_POST['decision'] ?? null);
$role = $_SESSION['user']['role'];
$uid  = $_SESSION['user']['id'];

try {
    $pdo->beginTransaction();

    // Verrouille la demande pendant le traitement (deux clics ne peuvent pas la traiter deux fois)
    $st = $pdo->prepare("SELECT * FROM demande_conge WHERE id_conge = ? FOR UPDATE");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) { throw new RuntimeException("Demande introuvable."); }
    if ($c['statut'] !== 'EN_ATTENTE') { throw new RuntimeException("Cette demande a déjà été traitée."); }

    if ($decision === 'annuler') {
        if ($role === 'DIR' || (int)$c['id_user'] !== (int)$uid) {
            throw new RuntimeException("Vous ne pouvez annuler que vos propres demandes.");
        }
        $pdo->prepare("UPDATE demande_conge SET statut = 'ANNULEE', date_decision = NOW() WHERE id_conge = ?")
            ->execute([$id]);
        $action = 'CONGE_ANNULE';
        $message = "La demande " . $c['numero'] . " a été annulée.";

    } elseif ($decision === 'accepter' || $decision === 'refuser') {
        if ($role !== 'DIR') { throw new RuntimeException("Seul le directeur régional peut décider."); }

        if ($decision === 'refuser') {
            $motif = dem_str($_POST['motif_refus'] ?? null);
            if (mb_strlen($motif) < 5)   { throw new RuntimeException("Indiquez le motif du refus (5 caractères au minimum)."); }
            if (mb_strlen($motif) > 255) { throw new RuntimeException("Le motif du refus est limité à 255 caractères."); }
            $pdo->prepare(
                "UPDATE demande_conge SET statut = 'REFUSEE', motif_refus = ?, date_decision = NOW(), id_decideur = ?
                 WHERE id_conge = ?"
            )->execute([$motif, $uid, $id]);
            $action = 'CONGE_REFUSE';
            $message = "La demande " . $c['numero'] . " a été refusée.";
        } else {
            $pdo->prepare(
                "UPDATE demande_conge SET statut = 'ACCEPTEE', date_decision = NOW(), id_decideur = ? WHERE id_conge = ?"
            )->execute([$uid, $id]);
            $action = 'CONGE_ACCEPTE';
            $message = "La demande " . $c['numero'] . " a été acceptée.";
        }
    } else {
        throw new RuntimeException("Décision invalide.");
    }

    $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
        ->execute([$action, $c['numero'] . " : " . $c['type_conge'], $uid]);
    $pdo->commit();
    flash('succes', $message);

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash('erreur', $e->getMessage());
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash('erreur', "Erreur technique : l'opération n'a pas été enregistrée.");
}

header("Location: liste.php" . ($role === 'DIR' ? "?statut=EN_ATTENTE" : ""));
exit;

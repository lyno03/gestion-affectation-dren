<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/demandes.php";
exiger_role(['DIR']);   // seul le directeur régional décide

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: liste.php");
    exit;
}
if (!verifier_csrf($_POST['csrf'] ?? '')) {
    die("Requête invalide.");
}

$id = (int)($_POST['id_demande'] ?? 0);
$decision = dem_str($_POST['decision'] ?? null);
$aujourdhui = date('Y-m-d');

try {
    $pdo->beginTransaction();

    // Verrouille la demande pendant la décision (deux clics ne peuvent pas la traiter deux fois)
    $st = $pdo->prepare("SELECT * FROM demande WHERE id_demande = ? FOR UPDATE");
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) { throw new RuntimeException("Demande introuvable."); }
    if ($d['statut'] !== 'EN_ATTENTE') { throw new RuntimeException("Cette demande a déjà été traitée."); }

    if ($decision === 'refuser') {
        $motif_refus = dem_str($_POST['motif_refus'] ?? null);
        if (mb_strlen($motif_refus) < 5) {
            throw new RuntimeException("Indiquez le motif du refus (5 caractères au minimum).");
        }
        if (mb_strlen($motif_refus) > 255) {
            throw new RuntimeException("Le motif du refus est limité à 255 caractères.");
        }
        $pdo->prepare(
            "UPDATE demande SET statut = 'REFUSEE', motif_refus = ?, date_decision = NOW(), id_decideur = ?
             WHERE id_demande = ?"
        )->execute([$motif_refus, $_SESSION['user']['id'], $id]);
        $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
            ->execute(['DEMANDE_REFUSEE', $d['numero'] . " refusée", $_SESSION['user']['id']]);
        $message = "La demande " . $d['numero'] . " a été refusée.";

    } elseif ($decision === 'accepter') {
        $date_effet = dem_str($_POST['date_effet'] ?? null);
        $code_acte  = dem_str($_POST['code_acte'] ?? null);
        $reference  = dem_str($_POST['reference_acte'] ?? null);

        if (!dem_date_ok($date_effet)) { throw new RuntimeException("Date d'effet invalide."); }
        if ($date_effet > $aujourdhui) { throw new RuntimeException("La date d'effet ne peut pas être dans le futur."); }
        if (mb_strlen($reference) > 60) { throw new RuntimeException("Référence trop longue (60 caractères maximum)."); }
        if ($code_acte !== '') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM type_acte WHERE code_acte = ?");
            $st->execute([$code_acte]);
            if ($st->fetchColumn() == 0) { throw new RuntimeException("Type d'acte invalide."); }
        }

        // Crée l'affectation : ferme l'ancienne, ouvre la nouvelle, met à jour le statut
        $id_affectation = appliquer_affectation(
            $pdo, $d['matricule'], $d['code_etab_demande'], $date_effet, $d['motif'], $reference, $code_acte
        );

        $pdo->prepare(
            "UPDATE demande SET statut = 'ACCEPTEE', date_decision = NOW(), id_decideur = ?,
                    reference_acte = ?, code_acte = ?, id_affectation = ?
             WHERE id_demande = ?"
        )->execute([$_SESSION['user']['id'], $reference ?: null, $code_acte ?: null, $id_affectation, $id]);

        $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
            ->execute(['DEMANDE_ACCEPTEE',
                       $d['numero'] . " acceptée : agent {$d['matricule']} vers {$d['code_etab_demande']} au $date_effet",
                       $_SESSION['user']['id']]);
        $message = "La demande " . $d['numero'] . " a été acceptée : l'affectation est enregistrée.";

    } else {
        throw new RuntimeException("Décision invalide.");
    }

    $pdo->commit();
    flash('succes', $message);

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash('erreur', $e->getMessage());
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash('erreur', "Erreur technique : la décision n'a pas été enregistrée.");
}

header("Location: liste.php?statut=EN_ATTENTE");
exit;

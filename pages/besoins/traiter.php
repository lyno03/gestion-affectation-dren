<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/besoins.php";
exiger_role(['DIR']);   // seul le directeur régional décide

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: liste.php");
    exit;
}
if (!verifier_csrf($_POST['csrf'] ?? '')) {
    die("Requête invalide.");
}

$id = (int)($_POST['id_dp'] ?? 0);
$decision = bes_str($_POST['decision'] ?? null);

try {
    $pdo->beginTransaction();

    // Verrouille la demande pendant la décision : elle ne peut pas être traitée deux fois
    $st = $pdo->prepare("SELECT * FROM demande_personnel WHERE id_dp = ? FOR UPDATE");
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) { throw new RuntimeException("Demande introuvable."); }
    if ($d['statut'] !== 'EN_ATTENTE') { throw new RuntimeException("Cette demande a déjà été traitée."); }

    $observation = bes_str($_POST['observation'] ?? null);
    if (mb_strlen($observation) > 255) {
        throw new RuntimeException("L'observation est limitée à 255 caractères.");
    }

    if ($decision === 'refuser') {
        if (mb_strlen($observation) < 5) {
            throw new RuntimeException("Indiquez le motif du refus (5 caractères au minimum).");
        }
        $pdo->prepare(
            "UPDATE demande_personnel SET statut = 'REFUSEE', observation = ?, date_decision = NOW(), id_decideur = ?
             WHERE id_dp = ?"
        )->execute([$observation, $_SESSION['user']['id'], $id]);
        $action = 'DEMANDE_PERS_REFUSEE';
        $details = $d['numero'] . " refusée";
        $message = "La demande " . $d['numero'] . " a été refusée.";

    } elseif ($decision === 'accepter') {
        $accorde = filter_var($_POST['nombre_accorde'] ?? null, FILTER_VALIDATE_INT);
        if ($accorde === false || $accorde < 1 || $accorde > (int)$d['nombre']) {
            throw new RuntimeException("Le nombre accordé doit être compris entre 1 et " . (int)$d['nombre'] . ".");
        }
        $pdo->prepare(
            "UPDATE demande_personnel SET statut = 'ACCEPTEE', nombre_accorde = ?, observation = ?,
                    date_decision = NOW(), id_decideur = ?
             WHERE id_dp = ?"
        )->execute([$accorde, $observation ?: null, $_SESSION['user']['id'], $id]);

        // L'acceptation déclenche l'affectation : un poste à pourvoir par personnel accordé,
        // transmis au responsable RH qui choisira les personnels à affecter
        $ins = $pdo->prepare(
            "INSERT INTO poste_a_pourvoir (id_dp, code_etab, code_fonction, precision_poste) VALUES (?, ?, ?, ?)"
        );
        for ($i = 0; $i < $accorde; $i++) {
            $ins->execute([$id, $d['code_etab'], $d['code_fonction'], $d['precision_poste']]);
        }

        $action = 'DEMANDE_PERS_ACCEPTEE';
        $details = $d['numero'] . " acceptée : $accorde poste(s) à pourvoir pour {$d['code_etab']}";
        $message = "La demande " . $d['numero'] . " a été acceptée : $accorde poste(s) à pourvoir transmis au responsable RH pour affectation.";

    } else {
        throw new RuntimeException("Décision invalide.");
    }

    $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
        ->execute([$action, $details, $_SESSION['user']['id']]);
    $pdo->commit();
    bes_flash('succes', $message);

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bes_flash('erreur', $e->getMessage());
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bes_flash('erreur', "Erreur technique : la décision n'a pas été enregistrée.");
}

header("Location: liste.php?statut=EN_ATTENTE");
exit;

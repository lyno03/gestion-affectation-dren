<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN', 'RH']);
// Seul l'administrateur peut créer une affectation directement ; les autres passent par une demande
if ($_SESSION['user']['role'] !== 'ADMIN') {
    header("Location: /dren/pages/demandes/nouvelle.php" . (isset($_GET['m']) && is_string($_GET['m']) ? "?m=" . urlencode($_GET['m']) : ""));
    exit;
}

$titre_page = "Nouvelle affectation";
$erreurs = [];
$v = [];
$motifs = ['Première affectation', 'Mutation sur demande', 'Nécessité de service',
           'Retour de congé', 'Promotion'];

function date_valide($d) {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

// Listes pour les menus déroulants
$agents = $pdo->query(
    "SELECT a.matricule, a.nom, a.prenoms, e.nom AS etab
     FROM agent a
     LEFT JOIN affectation af ON af.matricule = a.matricule AND af.date_fin IS NULL
     LEFT JOIN etablissement e ON e.code_etab = af.code_etab
     ORDER BY a.nom"
)->fetchAll();
$etabs = $pdo->query(
    "SELECT e.code_etab, e.nom, c.nom AS cisco
     FROM etablissement e JOIN cisco c ON c.code_cisco = e.code_cisco
     ORDER BY c.nom, e.nom"
)->fetchAll();
$actes = $pdo->query("SELECT code_acte, libelle FROM type_acte ORDER BY libelle")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }

    foreach (['matricule','code_etab','date_debut','motif','code_acte','reference_acte'] as $champ) {
        $v[$champ] = trim($_POST[$champ] ?? '');
    }

    // 1. Validations simples
    if ($v['matricule'] === '')  { $erreurs[] = "Choisissez un agent."; }
    if ($v['code_etab'] === '')  { $erreurs[] = "Choisissez le nouveau poste."; }
    if (!date_valide($v['date_debut'])) {
        $erreurs[] = "Date d'effet invalide.";
    } elseif ($v['date_debut'] > date('Y-m-d')) {
        $erreurs[] = "La date d'effet ne peut pas être dans le futur.";
    }
    if (!in_array($v['motif'], $motifs)) { $erreurs[] = "Choisissez un motif."; }
    if (strlen($v['reference_acte']) > 60) { $erreurs[] = "Référence trop longue (60 caractères maximum)."; }

    // 2. Enregistrement dans une transaction
    if (!$erreurs) {
        try {
            $pdo->beginTransaction();

            // Verrouille la ligne de l'agent pendant l'opération
            $stmt = $pdo->prepare("SELECT date_recrutement FROM agent WHERE matricule = ? FOR UPDATE");
            $stmt->execute([$v['matricule']]);
            $agent = $stmt->fetch();
            if (!$agent) {
                throw new RuntimeException("Agent introuvable.");
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM etablissement WHERE code_etab = ?");
            $stmt->execute([$v['code_etab']]);
            if ($stmt->fetchColumn() == 0) {
                throw new RuntimeException("Établissement introuvable.");
            }

            // « Première affectation » n'est possible que si l'agent n'a aucun historique
            if ($v['motif'] === 'Première affectation') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM affectation WHERE matricule = ?");
                $stmt->execute([$v['matricule']]);
                if ($stmt->fetchColumn() > 0) {
                    throw new RuntimeException("Le motif « Première affectation » ne convient pas : cet agent a déjà un historique.");
                }
            }

            if ($agent['date_recrutement'] && $v['date_debut'] < $agent['date_recrutement']) {
                throw new RuntimeException("La date d'effet est antérieure au recrutement de l'agent.");
            }

            // Affectation actuelle (sans date de fin)
            $stmt = $pdo->prepare(
                "SELECT id_affectation, code_etab, date_debut
                 FROM affectation WHERE matricule = ? AND date_fin IS NULL FOR UPDATE"
            );
            $stmt->execute([$v['matricule']]);
            $actuelle = $stmt->fetch();

            if ($actuelle) {
                if ($actuelle['code_etab'] === $v['code_etab']) {
                    throw new RuntimeException("L'agent est déjà affecté à ce poste.");
                }
                if ($v['date_debut'] <= $actuelle['date_debut']) {
                    throw new RuntimeException("La date d'effet doit être postérieure au début de l'affectation actuelle ("
                        . date("d/m/Y", strtotime($actuelle['date_debut'])) . ").");
                }
                // Clôture l'ancienne affectation la veille de la nouvelle
                $pdo->prepare(
                    "UPDATE affectation SET date_fin = DATE_SUB(?, INTERVAL 1 DAY)
                     WHERE id_affectation = ?"
                )->execute([$v['date_debut'], $actuelle['id_affectation']]);
            }

            // Crée la nouvelle affectation
            $pdo->prepare(
                "INSERT INTO affectation (matricule, code_etab, date_debut, motif, reference_acte, code_acte)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $v['matricule'], $v['code_etab'], $v['date_debut'], $v['motif'],
                $v['reference_acte'] ?: null, $v['code_acte'] ?: null
            ]);

            // L'agent est maintenant en poste
            $pdo->prepare("UPDATE agent SET statut = 'En poste' WHERE matricule = ?")
                ->execute([$v['matricule']]);

            // Trace dans le journal
            $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                ->execute(['AFFECTATION',
                           "Agent {$v['matricule']} vers {$v['code_etab']} au {$v['date_debut']}",
                           $_SESSION['user']['id']]);

            $pdo->commit();
            header("Location: historique.php?ok=1");
            exit;

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = "Erreur technique : l'affectation n'a pas été enregistrée.";
        }
    }
}

// Agent présélectionné (depuis une fiche) ou valeur déjà saisie
$sel = $v['matricule'] ?? ($_GET['m'] ?? '');

require "../../includes/header.php";
?>
<div class="carte">
    <h2>Nouvelle affectation</h2>

    <?php if ($erreurs): ?>
        <div class="erreur">
            <?php foreach ($erreurs as $e): ?>
                <?= htmlspecialchars($e) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">

        <div class="champ">
            <label for="matricule">Agent *</label>
            <select id="matricule" name="matricule">
                <option value="">-- Choisir --</option>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= htmlspecialchars($a['matricule']) ?>"
                        <?= $sel === $a['matricule'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['nom'] . ' ' . $a['prenoms'] . ' (' . $a['matricule'] . ') – ' . ($a['etab'] ?? 'sans poste')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="champ">
            <label for="code_etab">Nouveau poste *</label>
            <select id="code_etab" name="code_etab">
                <option value="">-- Choisir --</option>
                <?php foreach ($etabs as $e): ?>
                    <option value="<?= htmlspecialchars($e['code_etab']) ?>"
                        <?= ($v['code_etab'] ?? '') === $e['code_etab'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['nom'] . ' – ' . $e['cisco']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="champ">
            <label for="date_debut">Date d'effet *</label>
            <input type="date" id="date_debut" name="date_debut" max="<?= date('Y-m-d') ?>"
                   value="<?= htmlspecialchars($v['date_debut'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="champ">
            <label for="motif">Motif *</label>
            <select id="motif" name="motif">
                <option value="">-- Choisir --</option>
                <?php foreach ($motifs as $m): ?>
                    <option <?= ($v['motif'] ?? '') === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="champ">
            <label for="code_acte">Type d'acte</label>
            <select id="code_acte" name="code_acte">
                <option value="">-- Aucun --</option>
                <?php foreach ($actes as $a): ?>
                    <option value="<?= htmlspecialchars($a['code_acte']) ?>"
                        <?= ($v['code_acte'] ?? '') === $a['code_acte'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="champ">
            <label for="reference_acte">Référence de l'acte</label>
            <input type="text" id="reference_acte" name="reference_acte" maxlength="60"
                   placeholder="Ex. Note 120/DREN/26"
                   value="<?= htmlspecialchars($v['reference_acte'] ?? '') ?>">
        </div>

        <button class="btn" type="submit">Enregistrer l'affectation</button>
        <a class="btn sec" href="historique.php">Annuler</a>
    </form>
</div>
<?php require "../../includes/footer.php"; ?>
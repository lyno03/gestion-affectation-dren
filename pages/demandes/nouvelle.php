<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/demandes.php";
exiger_role(['RH', 'CISCO', 'ETAB']);

$titre_page = "Nouvelle demande";
$aujourdhui = date('Y-m-d');
$erreurs = [];
$v = [];

// La table des demandes existe-t-elle ?
try {
    $pdo->query("SELECT 1 FROM demande LIMIT 1");
    $table_ok = true;
} catch (PDOException $ex) {
    $table_ok = false;
}

// Personnels que l'utilisateur a le droit de proposer (son périmètre)
[$per, $pp] = function_exists('filtre_agent') ? filtre_agent('a') : ['1=1', []];
$sql = "SELECT a.matricule, a.nom, a.prenoms, e.nom AS etab"
     . ($table_ok ? ", (SELECT COUNT(*) FROM demande d WHERE d.matricule = a.matricule AND d.statut = 'EN_ATTENTE') AS en_cours"
                  : ", 0 AS en_cours")
     . " FROM agent a
         LEFT JOIN affectation af ON af.matricule = a.matricule AND af.date_fin IS NULL
         LEFT JOIN etablissement e ON e.code_etab = af.code_etab
         WHERE $per ORDER BY a.nom, a.prenoms";
$st = $pdo->prepare($sql);
$st->execute($pp);
$agents = $st->fetchAll();

$etabs = $pdo->query(
    "SELECT e.code_etab, e.nom, c.nom AS cisco
     FROM etablissement e JOIN cisco c ON c.code_cisco = e.code_cisco
     ORDER BY c.nom, e.nom"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }

    foreach (['matricule', 'code_etab', 'motif', 'date_souhaitee', 'commentaire'] as $c) {
        $v[$c] = dem_str($_POST[$c] ?? null);
    }

    if (!$table_ok) { $erreurs[] = "La table « demande » n'existe pas encore : exécutez d'abord le script SQL."; }
    if ($v['matricule'] === '') { $erreurs[] = "Choisissez le personnel concerné."; }
    if ($v['code_etab'] === '') { $erreurs[] = "Choisissez le poste souhaité."; }
    if (!in_array($v['motif'], DEMANDE_MOTIFS, true)) { $erreurs[] = "Choisissez un motif."; }
    if ($v['date_souhaitee'] !== '') {
        if (!dem_date_ok($v['date_souhaitee'])) {
            $erreurs[] = "Date souhaitée invalide.";
        } elseif ($v['date_souhaitee'] < $aujourdhui) {
            $erreurs[] = "La date souhaitée ne peut pas être dans le passé.";
        }
    }
    if (mb_strlen($v['commentaire']) > 255) { $erreurs[] = "La justification est limitée à 255 caractères."; }

    // Le personnel doit appartenir au périmètre de l'expéditeur
    if (!$erreurs && !agent_visible($pdo, $v['matricule'])) {
        $erreurs[] = "Ce personnel n'appartient pas à votre périmètre.";
    }

    if (!$erreurs) {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare("SELECT COUNT(*) FROM agent WHERE matricule = ?");
            $st->execute([$v['matricule']]);
            if ($st->fetchColumn() == 0) { throw new RuntimeException("Personnel introuvable."); }

            $st = $pdo->prepare("SELECT COUNT(*) FROM etablissement WHERE code_etab = ?");
            $st->execute([$v['code_etab']]);
            if ($st->fetchColumn() == 0) { throw new RuntimeException("Établissement introuvable."); }

            $st = $pdo->prepare("SELECT code_etab FROM affectation WHERE matricule = ? AND date_fin IS NULL");
            $st->execute([$v['matricule']]);
            $origine = $st->fetchColumn() ?: null;
            if ($origine === $v['code_etab']) {
                throw new RuntimeException("Le personnel est déjà affecté à ce poste.");
            }

            if ($v['motif'] === 'Première affectation') {
                $st = $pdo->prepare("SELECT COUNT(*) FROM affectation WHERE matricule = ?");
                $st->execute([$v['matricule']]);
                if ($st->fetchColumn() > 0) {
                    throw new RuntimeException("Le motif « Première affectation » ne convient pas : ce personnel a déjà un historique.");
                }
            }

            $st = $pdo->prepare("SELECT COUNT(*) FROM demande WHERE matricule = ? AND statut = 'EN_ATTENTE'");
            $st->execute([$v['matricule']]);
            if ($st->fetchColumn() > 0) {
                throw new RuntimeException("Une demande est déjà en attente de décision pour ce personnel.");
            }

            $numero = dem_numero_suivant($pdo);
            $pdo->prepare(
                "INSERT INTO demande (numero, matricule, code_etab_origine, code_etab_demande, motif,
                                      date_souhaitee, commentaire, id_demandeur)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$numero, $v['matricule'], $origine, $v['code_etab'], $v['motif'],
                        $v['date_souhaitee'] ?: null, $v['commentaire'] ?: null, $_SESSION['user']['id']]);

            $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                ->execute(['DEMANDE_ENVOYEE', "$numero : agent {$v['matricule']} vers {$v['code_etab']}",
                           $_SESSION['user']['id']]);
            $pdo->commit();

            flash('succes', "La demande $numero a bien été envoyée au directeur régional.");
            header("Location: liste.php");
            exit;

        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = ($ex->getCode() === '23000')
                ? "Une demande est déjà en attente de décision pour ce personnel."
                : "Erreur technique : la demande n'a pas été envoyée.";
        }
    }
}

$sel = $v['matricule'] ?? dem_str($_GET['m'] ?? null);

require "../../includes/header.php";
?>
<p class="sous-titre">Envoyez une demande d'affectation au directeur régional, qui l'acceptera ou la refusera.</p>

<?php if (!$table_ok): ?>
    <div class="erreur">
        La table « demande » n'existe pas encore. Dans phpMyAdmin, onglet <b>SQL</b> de la base <b>dren_db</b>,
        exécutez le script <b>demande.sql</b>, puis rechargez cette page.
    </div>
<?php endif; ?>

<div class="carte">
    <h2>Nouvelle demande d'affectation</h2>

    <?php if ($erreurs): ?>
        <div class="erreur">
            <?php foreach ($erreurs as $er): ?><?= htmlspecialchars($er) ?><br><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">

        <div class="champ">
            <label for="matricule">Personnel concerné *</label>
            <select id="matricule" name="matricule">
                <option value="">-- Choisir --</option>
                <?php foreach ($agents as $a): ?>
                    <option value="<?= htmlspecialchars($a['matricule']) ?>"
                        <?= $sel === $a['matricule'] ? 'selected' : '' ?>
                        <?= (int)$a['en_cours'] > 0 ? 'disabled' : '' ?>>
                        <?= htmlspecialchars($a['nom'] . ' ' . $a['prenoms'] . ' (' . $a['matricule'] . ') – ' . ($a['etab'] ?? 'sans poste')) ?>
                        <?= (int)$a['en_cours'] > 0 ? ' [demande en cours]' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="champ">
            <label for="code_etab">Poste souhaité *</label>
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
            <label for="motif">Motif *</label>
            <select id="motif" name="motif">
                <option value="">-- Choisir --</option>
                <?php foreach (DEMANDE_MOTIFS as $m): ?>
                    <option <?= ($v['motif'] ?? '') === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="champ">
            <label for="date_souhaitee">Date souhaitée (facultatif)</label>
            <input type="date" id="date_souhaitee" name="date_souhaitee" min="<?= $aujourdhui ?>"
                   value="<?= htmlspecialchars($v['date_souhaitee'] ?? '') ?>">
        </div>

        <div class="champ">
            <label for="commentaire">Justification (facultatif, 255 caractères maximum)</label>
            <textarea id="commentaire" name="commentaire" rows="3" maxlength="255"><?= htmlspecialchars($v['commentaire'] ?? '') ?></textarea>
        </div>

        <button class="btn" type="submit" <?= $table_ok ? '' : 'disabled' ?>>Envoyer la demande</button>
        <a class="btn sec" href="liste.php">Annuler</a>
    </form>
</div>
<?php require "../../includes/footer.php"; ?>

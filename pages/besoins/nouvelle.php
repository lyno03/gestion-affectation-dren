<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/besoins.php";
exiger_role(['ETAB']);   // seul un chef d'établissement demande du personnel, pour son établissement

$titre_page = "Demande de personnel";
$erreurs = [];
$v = [];
$tables_ok = bes_tables_ok($pdo);

// L'établissement du chef connecté (pris dans son compte, jamais dans le formulaire)
$code_etab = $_SESSION['user']['code_etab'] ?? '';
$st = $pdo->prepare(
    "SELECT e.nom, e.type, c.nom AS cisco
     FROM etablissement e JOIN cisco c ON c.code_cisco = e.code_cisco
     WHERE e.code_etab = ?"
);
$st->execute([$code_etab]);
$etab = $st->fetch();

$fonctions = [];
$effectif = ['ADMINISTRATIF' => 0, 'ENSEIGNANT' => 0];
if ($tables_ok && $etab) {
    $fonctions = $pdo->query("SELECT code_fonction, libelle, categorie FROM fonction ORDER BY categorie, libelle")->fetchAll();

    // Effectif actuel de l'établissement, par catégorie : utile pour justifier la demande
    $st = $pdo->prepare(
        "SELECT fo.categorie, COUNT(*) AS nb
         FROM affectation af
         JOIN agent a ON a.matricule = af.matricule
         JOIN fonction fo ON fo.code_fonction = a.code_fonction
         WHERE af.code_etab = ? AND af.date_fin IS NULL
         GROUP BY fo.categorie"
    );
    $st->execute([$code_etab]);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $cat => $nb) {
        if (isset($effectif[$cat])) { $effectif[$cat] = (int)$nb; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tables_ok && $etab) {

    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }

    foreach (['categorie', 'code_fonction', 'precision_poste', 'nombre', 'urgence', 'justification'] as $c) {
        $v[$c] = bes_str($_POST[$c] ?? null);
    }

    if (!isset(BESOIN_CATEGORIES[$v['categorie']])) {
        $erreurs[] = "Choisissez le type de personnel : administratif ou enseignant.";
    }

    // La fonction doit exister et appartenir à la catégorie choisie
    $fonction = null;
    foreach ($fonctions as $fo) {
        if ($fo['code_fonction'] === $v['code_fonction']) { $fonction = $fo; }
    }
    if (!$fonction) {
        $erreurs[] = "Choisissez la fonction demandée.";
    } elseif ($fonction['categorie'] !== $v['categorie']) {
        $erreurs[] = "Cette fonction ne correspond pas au type de personnel choisi.";
    }

    if (mb_strlen($v['precision_poste']) > 80) { $erreurs[] = "La précision est limitée à 80 caractères."; }

    $nombre = filter_var($v['nombre'], FILTER_VALIDATE_INT);
    if ($nombre === false || $nombre < 1 || $nombre > 20) {
        $erreurs[] = "Le nombre de personnels demandés doit être compris entre 1 et 20.";
    }
    if (!isset(BESOIN_URGENCES[$v['urgence']])) { $erreurs[] = "Choisissez le niveau d'urgence."; }
    if (mb_strlen($v['justification']) < 10) {
        $erreurs[] = "Expliquez le besoin dans la justification (10 caractères au minimum).";
    } elseif (mb_strlen($v['justification']) > 500) {
        $erreurs[] = "La justification est limitée à 500 caractères.";
    }

    if (!$erreurs) {
        try {
            $pdo->beginTransaction();

            // Évite d'envoyer deux fois la même demande en attente
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM demande_personnel
                 WHERE code_etab = ? AND code_fonction = ? AND precision_poste <=> ? AND statut = 'EN_ATTENTE'"
            );
            $st->execute([$code_etab, $v['code_fonction'], $v['precision_poste'] ?: null]);
            if ($st->fetchColumn() > 0) {
                throw new RuntimeException("Une demande identique est déjà en attente de décision.");
            }

            $numero = bes_numero_suivant($pdo);
            $pdo->prepare(
                "INSERT INTO demande_personnel (numero, code_etab, code_fonction, precision_poste, nombre,
                                                urgence, justification, id_demandeur)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$numero, $code_etab, $v['code_fonction'], $v['precision_poste'] ?: null,
                        $nombre, $v['urgence'], $v['justification'], $_SESSION['user']['id']]);

            $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                ->execute(['DEMANDE_PERS_ENVOYEE', "$numero : $nombre x {$fonction['libelle']} pour $code_etab",
                           $_SESSION['user']['id']]);
            $pdo->commit();

            bes_flash('succes', "La demande de personnel $numero a bien été envoyée au directeur régional.");
            header("Location: liste.php");
            exit;

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = "Erreur technique : la demande n'a pas été envoyée.";
        }
    }
}

require "../../includes/header.php";
?>
<p class="sous-titre">Exprimez le besoin en personnel de votre établissement : le directeur régional l'acceptera ou la refusera.</p>

<?php if (!$etab): ?>
    <div class="erreur">Votre compte n'est rattaché à aucun établissement : contactez l'administrateur.</div>
<?php elseif (!$tables_ok): ?>
    <div class="erreur">
        Le module n'est pas encore installé. Dans phpMyAdmin, onglet <b>SQL</b> de la base <b>dren_db</b>,
        exécutez le script <b>demande_personnel.sql</b>, puis rechargez cette page.
    </div>
<?php else: ?>
<div class="carte">
    <h2><?= htmlspecialchars($etab['nom']) ?></h2>
    <p><small><?= htmlspecialchars($etab['type']) ?> · <?= htmlspecialchars($etab['cisco']) ?></small></p>
    <p>
        Effectif actuel :
        <span class="badge"><?= $effectif['ENSEIGNANT'] ?> enseignant(s)</span>
        <span class="badge"><?= $effectif['ADMINISTRATIF'] ?> administratif(s)</span>
    </p>
</div>

<div class="carte">
    <h3>Nouvelle demande de personnel</h3>

    <?php if ($erreurs): ?>
        <div class="erreur">
            <?php foreach ($erreurs as $er): ?><?= htmlspecialchars($er) ?><br><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">

        <div class="champ">
            <label>Type de personnel *</label>
            <label><input type="radio" name="categorie" value="ADMINISTRATIF" required
                <?= ($v['categorie'] ?? '') === 'ADMINISTRATIF' ? 'checked' : '' ?>> Personnel administratif</label>
            <label><input type="radio" name="categorie" value="ENSEIGNANT"
                <?= ($v['categorie'] ?? '') === 'ENSEIGNANT' ? 'checked' : '' ?>> Personnel enseignant</label>
        </div>

        <div class="champ">
            <label for="code_fonction">Fonction demandée *</label>
            <select id="code_fonction" name="code_fonction" required>
                <option value="">-- Choisir --</option>
                <?php foreach ($fonctions as $fo): ?>
                    <option value="<?= htmlspecialchars($fo['code_fonction']) ?>" data-cat="<?= htmlspecialchars($fo['categorie']) ?>"
                        <?= ($v['code_fonction'] ?? '') === $fo['code_fonction'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($fo['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="champ">
            <label for="precision_poste">Précision (matière enseignée, poste exact…) – facultatif</label>
            <input type="text" id="precision_poste" name="precision_poste" maxlength="80"
                   placeholder="Ex. Mathématiques, Secrétaire de direction"
                   value="<?= htmlspecialchars($v['precision_poste'] ?? '') ?>">
        </div>

        <div class="champ">
            <label for="nombre">Nombre de personnels demandés * (1 à 20)</label>
            <input type="number" id="nombre" name="nombre" min="1" max="20" required
                   value="<?= htmlspecialchars($v['nombre'] ?? '1') ?>">
        </div>

        <div class="champ">
            <label for="urgence">Urgence *</label>
            <select id="urgence" name="urgence">
                <?php foreach (BESOIN_URGENCES as $code => $lib): ?>
                    <option value="<?= $code ?>" <?= ($v['urgence'] ?? 'NORMALE') === $code ? 'selected' : '' ?>><?= $lib ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="champ">
            <label for="justification">Justification * (10 à 500 caractères)</label>
            <textarea id="justification" name="justification" rows="4" minlength="10" maxlength="500" required><?= htmlspecialchars($v['justification'] ?? '') ?></textarea>
        </div>

        <button class="btn" type="submit">Envoyer la demande</button>
        <a class="btn sec" href="liste.php">Annuler</a>
    </form>
</div>

<script>
// La liste des fonctions suit le type de personnel choisi
(function () {
    var fonction = document.getElementById('code_fonction');
    function filtrer() {
        var coche = document.querySelector('input[name="categorie"]:checked');
        var cat = coche ? coche.value : '';
        Array.prototype.forEach.call(fonction.options, function (o) {
            if (!o.value) return;
            var ok = !cat || o.dataset.cat === cat;
            o.hidden = !ok;
            o.disabled = !ok;
        });
        if (fonction.selectedOptions[0] && fonction.selectedOptions[0].disabled) fonction.value = '';
    }
    document.querySelectorAll('input[name="categorie"]').forEach(function (r) {
        r.addEventListener('change', filtrer);
    });
    filtrer();
})();
</script>
<?php endif; ?>
<?php require "../../includes/footer.php"; ?>

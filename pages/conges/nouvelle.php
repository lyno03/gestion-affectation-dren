<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/conges.php";
exiger_role(['RH', 'CISCO', 'ETAB']);   // ni l'administrateur ni le directeur ne demandent de congé

$titre_page = "Demande de congé";
$aujourdhui = date('Y-m-d');
$erreurs = [];
$v = [];

try {
    $pdo->query("SELECT 1 FROM demande_conge LIMIT 1");
    $table_ok = true;
} catch (PDOException $ex) {
    $table_ok = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf'] ?? '')) { die("Requête invalide."); }

    foreach (['type_conge', 'date_debut', 'date_fin', 'commentaire'] as $c) {
        $v[$c] = dem_str($_POST[$c] ?? null);
    }

    if (!$table_ok) { $erreurs[] = "La table « demande_conge » n'existe pas encore : exécutez d'abord le script conge.sql."; }
    if (!array_key_exists($v['type_conge'], CONGE_TYPES)) { $erreurs[] = "Choisissez le type de congé."; }
    if (!dem_date_ok($v['date_debut'])) {
        $erreurs[] = "Date de début invalide.";
    } elseif ($v['date_debut'] < $aujourdhui) {
        $erreurs[] = "La date de début ne peut pas être dans le passé.";
    }

    // Congé annuel (30 jours) et maternité (3 mois) : la durée est imposée par le serveur
    if (!$erreurs && CONGE_TYPES[$v['type_conge']]) {
        $v['date_fin'] = conge_calculer_fin($v['type_conge'], $v['date_debut']);
    }
    if (!$erreurs && !dem_date_ok($v['date_fin'])) {
        $erreurs[] = "Date de fin invalide.";
    } elseif (!$erreurs && $v['date_fin'] < $v['date_debut']) {
        $erreurs[] = "La date de fin ne peut pas précéder la date de début.";
    }
    if (mb_strlen($v['commentaire']) > 255) { $erreurs[] = "La justification est limitée à 255 caractères."; }

    if (!$erreurs) {
        try {
            $pdo->beginTransaction();

            // Pas de chevauchement avec une autre demande en attente ou acceptée
            $st = $pdo->prepare(
                "SELECT numero FROM demande_conge
                 WHERE id_user = ? AND statut IN ('EN_ATTENTE','ACCEPTEE')
                   AND date_debut <= ? AND date_fin >= ? LIMIT 1 FOR UPDATE"
            );
            $st->execute([$_SESSION['user']['id'], $v['date_fin'], $v['date_debut']]);
            if ($autre = $st->fetchColumn()) {
                throw new RuntimeException("Ces dates chevauchent votre demande $autre.");
            }

            $numero = conge_numero_suivant($pdo);
            $nb = conge_nb_jours($v['date_debut'], $v['date_fin']);
            $pdo->prepare(
                "INSERT INTO demande_conge (numero, id_user, type_conge, date_debut, date_fin, nb_jours, commentaire)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$numero, $_SESSION['user']['id'], $v['type_conge'], $v['date_debut'],
                        $v['date_fin'], $nb, $v['commentaire'] ?: null]);

            $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                ->execute(['CONGE_ENVOYE', "$numero : {$v['type_conge']} du {$v['date_debut']} au {$v['date_fin']}",
                           $_SESSION['user']['id']]);
            $pdo->commit();

            flash('succes', "Votre demande $numero a bien été envoyée au directeur régional.");
            header("Location: liste.php");
            exit;
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = "Erreur technique : la demande n'a pas été envoyée.";
        }
    }
}

require "../../includes/header.php";
?>
<p class="sous-titre">Envoyez une demande de congé au directeur régional, qui l'acceptera ou la refusera.</p>

<?php if (!$table_ok): ?>
    <div class="erreur">
        La table « demande_conge » n'existe pas encore. Dans phpMyAdmin, onglet <b>SQL</b> de la base <b>dren_db</b>,
        exécutez le script <b>conge.sql</b>, puis rechargez cette page.
    </div>
<?php endif; ?>

<div class="carte">
    <h2>Nouvelle demande de congé</h2>

    <?php if ($erreurs): ?>
        <div class="erreur"><?php foreach ($erreurs as $er): ?><?= htmlspecialchars($er) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">

        <div class="champ">
            <label for="type_conge">Type de congé *</label>
            <select id="type_conge" name="type_conge">
                <option value="">-- Choisir --</option>
                <?php foreach (CONGE_TYPES as $t => $r): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= ($v['type_conge'] ?? '') === $t ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t . ($r ? ' (' . $r['libelle'] . ')' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="champ">
            <label for="date_debut">Date de début *</label>
            <input type="date" id="date_debut" name="date_debut" min="<?= $aujourdhui ?>"
                   value="<?= htmlspecialchars($v['date_debut'] ?? '') ?>">
        </div>

        <div class="champ">
            <label for="date_fin">Date de fin *</label>
            <input type="date" id="date_fin" name="date_fin" min="<?= $aujourdhui ?>"
                   value="<?= htmlspecialchars($v['date_fin'] ?? '') ?>">
            <small id="aide_fin" style="display:none;color:#64748b"></small>
        </div>

        <div class="champ">
            <label for="commentaire">Justification (facultatif, 255 caractères maximum)</label>
            <textarea id="commentaire" name="commentaire" rows="3" maxlength="255"><?= htmlspecialchars($v['commentaire'] ?? '') ?></textarea>
        </div>

        <button class="btn" type="submit" <?= $table_ok ? '' : 'disabled' ?>>Envoyer la demande</button>
        <a class="btn sec" href="liste.php">Annuler</a>
    </form>
</div>

<script>
// Durées fixes : congé annuel = 30 jours, congé de maternité = 3 mois
(function () {
    var regles = <?= json_encode(CONGE_TYPES, JSON_UNESCAPED_UNICODE) ?>;
    var type = document.getElementById('type_conge');
    var debut = document.getElementById('date_debut');
    var fin = document.getElementById('date_fin');
    var aide = document.getElementById('aide_fin');

    function fmt(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function calculer(r, str) {
        var p = str.split('-');
        var y = +p[0], m = +p[1] - 1, j = +p[2];
        if (r.unite === 'jours') { return fmt(new Date(y, m, j + r.nombre - 1)); }
        var max = new Date(y, m + r.nombre + 1, 0).getDate();
        if (j > max) { return fmt(new Date(y, m + r.nombre, max)); }
        return fmt(new Date(y, m + r.nombre, j - 1));
    }
    function maj() {
        var r = regles[type.value];
        if (r && debut.value) {
            fin.value = calculer(r, debut.value);
            fin.readOnly = true;
            aide.style.display = 'block';
            aide.textContent = type.value + ' : durée fixe de ' + r.libelle + ', la date de fin est calculée automatiquement.';
        } else {
            fin.readOnly = false;
            aide.style.display = 'none';
            if (debut.value) { fin.min = debut.value; }
        }
    }
    type.addEventListener('change', maj);
    debut.addEventListener('change', maj);
    maj();
})();
</script>
<?php require "../../includes/footer.php"; ?>

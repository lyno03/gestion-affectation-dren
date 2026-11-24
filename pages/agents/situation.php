<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN', 'RH']);

$titre_page = "Situations administratives";
$matricule = $_GET['m'] ?? '';

$stmt = $pdo->prepare("SELECT matricule, nom, prenoms, date_recrutement, statut FROM agent WHERE matricule = ?");
$stmt->execute([$matricule]);
$agent = $stmt->fetch();
if (!$agent) {
    http_response_code(404);
    die("Agent introuvable.");
}

$types = ['Congé annuel', 'Congé de maladie', 'Congé de maternité', 'Détachement', 'Disponibilité'];

// Types de situation à durée fixe : la date de fin est calculée automatiquement
$durees_fixes = [
    'Congé annuel'       => ['unite' => 'jours', 'nombre' => 30, 'libelle' => '30 jours'],
    'Congé de maternité' => ['unite' => 'mois',  'nombre' => 3,  'libelle' => '3 mois'],
];

// Date de fin d'un congé à durée fixe (jour de début inclus)
function calculer_fin($type, $debut, $durees) {
    if (!isset($durees[$type])) { return null; }
    $d = new DateTime($debut);
    $n = $durees[$type]['nombre'];
    if ($durees[$type]['unite'] === 'jours') {
        return $d->modify('+' . ($n - 1) . ' days')->format('Y-m-d');
    }
    // mois : même jour, 3 mois plus tard, moins un jour (ex. 01/11 -> 31/01)
    $jour = (int)$d->format('j');
    $cible = (clone $d)->modify('first day of this month')->modify("+$n months");
    $max = (int)$cible->format('t');
    if ($jour > $max) {                      // ex. 30/11 -> fin de février
        return $cible->format('Y-m-') . $max;
    }
    return $cible->setDate((int)$cible->format('Y'), (int)$cible->format('n'), $jour)
                 ->modify('-1 day')->format('Y-m-d');
}
$erreurs = [];
$v = [];

function date_valide($d) {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}
function date_fr($d) { return $d ? date("d/m/Y", strtotime($d)) : "—"; }

// Recalcule le statut de l'agent d'après ses situations et ses affectations
function recalculer_statut(PDO $pdo, $matricule) {
    $stmt = $pdo->prepare("SELECT statut FROM agent WHERE matricule = ?");
    $stmt->execute([$matricule]);
    if ($stmt->fetchColumn() === 'Retraité') { return; }

    $stmt = $pdo->prepare(
        "SELECT type FROM situation
         WHERE matricule = ? AND date_debut <= CURDATE() AND (date_fin IS NULL OR date_fin >= CURDATE())
         ORDER BY date_debut DESC LIMIT 1"
    );
    $stmt->execute([$matricule]);
    $type = $stmt->fetchColumn();

    if ($type) {
        $statut = $type;
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM affectation WHERE matricule = ? AND date_fin IS NULL");
        $stmt->execute([$matricule]);
        $statut = $stmt->fetchColumn() > 0 ? 'En poste' : "En attente d'affectation";
    }
    $pdo->prepare("UPDATE agent SET statut = ? WHERE matricule = ?")->execute([$statut, $matricule]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }
    $action = $_POST['action'] ?? '';

    try {
        // ---- Ajouter une situation ----
        if ($action === 'ajouter') {
            foreach (['type', 'date_debut', 'date_fin'] as $c) {
                $v[$c] = trim($_POST[$c] ?? '');
            }

            if (!in_array($v['type'], $types)) { $erreurs[] = "Choisissez le type de situation."; }

            // Congé annuel (30 jours) et maternité (3 mois) : durée imposée par le serveur
            if (isset($durees_fixes[$v['type']]) && date_valide($v['date_debut'])) {
                $v['date_fin'] = calculer_fin($v['type'], $v['date_debut'], $durees_fixes);
            }
            if (!date_valide($v['date_debut'])) {
                $erreurs[] = "Date de début invalide.";
            } elseif ($v['date_debut'] > date('Y-m-d')) {
                $erreurs[] = "La date de début ne peut pas être dans le futur.";
            } elseif ($agent['date_recrutement'] && $v['date_debut'] < $agent['date_recrutement']) {
                $erreurs[] = "La date de début est antérieure au recrutement de l'agent.";
            }
            if ($v['date_fin'] !== '') {
                if (!date_valide($v['date_fin'])) {
                    $erreurs[] = "Date de fin invalide.";
                } elseif (date_valide($v['date_debut']) && $v['date_fin'] < $v['date_debut']) {
                    $erreurs[] = "La date de fin ne peut pas précéder la date de début.";
                }
            }

            // Deux situations ne doivent pas se chevaucher
            if (!$erreurs) {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM situation
                     WHERE matricule = ? AND date_debut <= ? AND (date_fin IS NULL OR date_fin >= ?)"
                );
                $stmt->execute([$matricule, $v['date_fin'] ?: '9999-12-31', $v['date_debut']]);
                if ($stmt->fetchColumn() > 0) {
                    $erreurs[] = "Cette période chevauche une situation déjà enregistrée pour cet agent.";
                }
            }

            if (!$erreurs) {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO situation (matricule, type, date_debut, date_fin) VALUES (?, ?, ?, ?)")
                    ->execute([$matricule, $v['type'], $v['date_debut'], $v['date_fin'] ?: null]);
                recalculer_statut($pdo, $matricule);
                $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                    ->execute(['SITUATION_AJOUT', "Agent $matricule : {$v['type']} dès le {$v['date_debut']}", $_SESSION['user']['id']]);
                $pdo->commit();
                header("Location: situation.php?m=" . urlencode($matricule) . "&ok=1");
                exit;
            }
        }

        // ---- Clôturer une situation en cours ----
        if ($action === 'cloturer') {
            $id = (int)($_POST['id_situation'] ?? 0);
            $fin = trim($_POST['date_fin'] ?? '');

            $stmt = $pdo->prepare("SELECT * FROM situation WHERE id_situation = ? AND matricule = ? AND date_fin IS NULL");
            $stmt->execute([$id, $matricule]);
            $sit = $stmt->fetch();

            if (!$sit) {
                $erreurs[] = "Situation introuvable ou déjà clôturée.";
            } elseif (!date_valide($fin)) {
                $erreurs[] = "Date de fin invalide.";
            } elseif ($fin < $sit['date_debut']) {
                $erreurs[] = "La date de fin ne peut pas précéder le début (" . date_fr($sit['date_debut']) . ").";
            } elseif ($fin > date('Y-m-d')) {
                $erreurs[] = "La date de fin ne peut pas être dans le futur.";
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE situation SET date_fin = ? WHERE id_situation = ?")->execute([$fin, $id]);
                recalculer_statut($pdo, $matricule);
                $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                    ->execute(['SITUATION_CLOTURE', "Agent $matricule : {$sit['type']} clôturé le $fin", $_SESSION['user']['id']]);
                $pdo->commit();
                header("Location: situation.php?m=" . urlencode($matricule) . "&ok=1");
                exit;
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $erreurs[] = "Erreur technique : l'opération n'a pas été enregistrée.";
    }
}

$stmt = $pdo->prepare("SELECT * FROM situation WHERE matricule = ? ORDER BY date_debut DESC, id_situation DESC");
$stmt->execute([$matricule]);
$situations = $stmt->fetchAll();

require "../../includes/header.php";
?>
<div class="carte">
    <h2>Situations de <?= htmlspecialchars($agent['nom'] . ' ' . $agent['prenoms']) ?></h2>
    <p>Matricule <?= htmlspecialchars($agent['matricule']) ?> · Statut actuel :
       <span class="badge"><?= htmlspecialchars($agent['statut']) ?></span></p>

    <?php if (isset($_GET['ok'])): ?>
        <div class="succes">L'opération a bien été enregistrée.</div>
    <?php endif; ?>
    <?php if ($erreurs): ?>
        <div class="erreur">
            <?php foreach ($erreurs as $e): ?>
                <?= htmlspecialchars($e) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="carte tableau">
    <h3>Historique des situations</h3>
    <?php if ($situations): ?>
    <table>
        <tr><th>Type</th><th>Début</th><th>Fin</th><th></th></tr>
        <?php foreach ($situations as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['type']) ?></td>
            <td><?= date_fr($s['date_debut']) ?></td>
            <td><?= $s['date_fin'] ? date_fr($s['date_fin']) : '<span class="badge">En cours</span>' ?></td>
            <td>
                <?php if ($s['date_fin'] === null): ?>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap">
                    <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                    <input type="hidden" name="action" value="cloturer">
                    <input type="hidden" name="id_situation" value="<?= (int)$s['id_situation'] ?>">
                    <input type="date" name="date_fin" value="<?= date('Y-m-d') ?>"
                           min="<?= htmlspecialchars($s['date_debut']) ?>" max="<?= date('Y-m-d') ?>">
                    <button class="btn sec" type="submit">Clôturer</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else: ?>
        <p>Aucune situation enregistrée.</p>
    <?php endif; ?>
</div>

<div class="carte">
    <h3>Ajouter une situation</h3>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
        <input type="hidden" name="action" value="ajouter">

        <div class="champ">
            <label for="type">Type *</label>
            <select id="type" name="type">
                <option value="">-- Choisir --</option>
                <?php foreach ($types as $t): ?>
                    <option <?= ($v['type'] ?? '') === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="date_debut">Date de début *</label>
            <input type="date" id="date_debut" name="date_debut" max="<?= date('Y-m-d') ?>"
                   value="<?= htmlspecialchars($v['date_debut'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="champ">
            <label for="date_fin">Date de fin (laisser vide si la situation est en cours)</label>
            <input type="date" id="date_fin" name="date_fin" value="<?= htmlspecialchars($v['date_fin'] ?? '') ?>">
            <small id="aide_fin" style="display:none;color:#64748b"></small>
        </div>

        <button class="btn" type="submit">Enregistrer la situation</button>
        <a class="btn sec" href="fiche.php?m=<?= urlencode($matricule) ?>">← Retour à la fiche</a>
    </form>
</div>
<script>
// Durées fixes : congé annuel = 30 jours, congé de maternité = 3 mois
(function () {
    var regles = <?= json_encode($durees_fixes, JSON_UNESCAPED_UNICODE) ?>;
    var type = document.getElementById('type');
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
        var max = new Date(y, m + r.nombre + 1, 0).getDate();   // nb de jours du mois cible
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
        }
    }
    type.addEventListener('change', maj);
    debut.addEventListener('change', maj);
    maj();
})();
</script>
<?php require "../../includes/footer.php"; ?>
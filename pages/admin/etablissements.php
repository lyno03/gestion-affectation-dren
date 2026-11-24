<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN']);

$titre_page = "Établissements";
$types   = ['Primaire', 'Collège', 'Lycée', 'Administration'];
$erreurs = [];
$v = [];

$ciscos = $pdo->query("SELECT code_cisco, nom FROM cisco ORDER BY nom")->fetchAll();
$codes_cisco = array_column($ciscos, 'code_cisco');

// Établissement en cours de modification (adresse ?e=E01)
$edit = null;
if (!empty($_GET['e'])) {
    $stmt = $pdo->prepare("SELECT * FROM etablissement WHERE code_etab = ?");
    $stmt->execute([$_GET['e']]);
    $edit = $stmt->fetch() ?: null;
}
$code_form = $edit ? $edit['code_etab'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }
    $action = $_POST['action'] ?? '';

    try {
        // ---- Ajouter ou modifier ----
        if ($action === 'ajouter' || $action === 'modifier') {
            foreach (['nom', 'type', 'commune', 'code_cisco'] as $c) {
                $v[$c] = trim($_POST[$c] ?? '');
            }
            $code = trim($_POST['code_etab'] ?? '');
            if ($action === 'modifier') { $code_form = $code; }

            if ($v['nom'] === '' || mb_strlen($v['nom']) > 120) {
                $erreurs[] = "Le nom est obligatoire (120 caractères maximum).";
            }
            if (!in_array($v['type'], $types)) { $erreurs[] = "Choisissez le type d'établissement."; }
            if (mb_strlen($v['commune']) > 80) { $erreurs[] = "Commune trop longue (80 caractères maximum)."; }
            if (!in_array($v['code_cisco'], $codes_cisco)) { $erreurs[] = "Choisissez la CISCO."; }

            // Un même nom ne peut pas exister deux fois dans la même CISCO
            if (!$erreurs) {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM etablissement WHERE nom = ? AND code_cisco = ? AND code_etab <> ?"
                );
                $stmt->execute([$v['nom'], $v['code_cisco'], $code]);
                if ($stmt->fetchColumn() > 0) {
                    $erreurs[] = "Un établissement de ce nom existe déjà dans cette CISCO.";
                }
            }

            if (!$erreurs && $action === 'modifier') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM etablissement WHERE code_etab = ?");
                $stmt->execute([$code]);
                if ($stmt->fetchColumn() == 0) { $erreurs[] = "Établissement introuvable."; }
            }

            if (!$erreurs) {
                if ($action === 'ajouter') {
                    // Code automatique : E + numéro suivant (E10, E11...)
                    $max = (int)$pdo->query(
                        "SELECT MAX(CAST(SUBSTRING(code_etab, 2) AS UNSIGNED))
                         FROM etablissement WHERE code_etab REGEXP '^E[0-9]+$'"
                    )->fetchColumn();
                    $nouveau = 'E' . str_pad($max + 1, 2, '0', STR_PAD_LEFT);

                    $pdo->prepare(
                        "INSERT INTO etablissement (code_etab, nom, type, commune, code_cisco)
                         VALUES (?, ?, ?, ?, ?)"
                    )->execute([$nouveau, $v['nom'], $v['type'], $v['commune'] ?: null, $v['code_cisco']]);
                    $detail = "Établissement $nouveau créé : {$v['nom']}";
                    $journal = 'ETAB_AJOUT';
                } else {
                    $pdo->prepare(
                        "UPDATE etablissement SET nom = ?, type = ?, commune = ?, code_cisco = ?
                         WHERE code_etab = ?"
                    )->execute([$v['nom'], $v['type'], $v['commune'] ?: null, $v['code_cisco'], $code]);
                    $detail = "Établissement $code modifié";
                    $journal = 'ETAB_MODIF';
                }
                $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                    ->execute([$journal, $detail, $_SESSION['user']['id']]);
                header("Location: etablissements.php?ok=1");
                exit;
            }
        }

        // ---- Supprimer (seulement si aucune affectation, même ancienne) ----
        if ($action === 'supprimer') {
            $code = trim($_POST['code_etab'] ?? '');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM affectation WHERE code_etab = ?");
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() > 0) {
                $erreurs[] = "Suppression impossible : des affectations (même anciennes) sont liées à cet établissement.";
            } else {
                $pdo->prepare("DELETE FROM etablissement WHERE code_etab = ?")->execute([$code]);
                $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                    ->execute(['ETAB_SUPPR', "Établissement $code supprimé", $_SESSION['user']['id']]);
                header("Location: etablissements.php?ok=1");
                exit;
            }
        }
    } catch (PDOException $e) {
        $erreurs[] = "Erreur technique : l'opération n'a pas été enregistrée.";
    }
}

$f = $v ?: ($edit ?: []);

$liste = $pdo->query(
    "SELECT e.code_etab, e.nom, e.type, e.commune, c.nom AS cisco,
            (SELECT COUNT(*) FROM affectation a WHERE a.code_etab = e.code_etab AND a.date_fin IS NULL) AS effectif,
            (SELECT COUNT(*) FROM affectation a WHERE a.code_etab = e.code_etab) AS total
     FROM etablissement e
     JOIN cisco c ON c.code_cisco = e.code_cisco
     ORDER BY c.nom, e.nom"
)->fetchAll();

require "../../includes/header.php";
?>
<div class="carte">
    <h2>Gestion des établissements</h2>
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
    <table>
        <tr><th>Code</th><th>Établissement</th><th>Type</th><th>CISCO</th><th>Effectif</th><th></th></tr>
        <?php foreach ($liste as $l): ?>
        <tr>
            <td><?= htmlspecialchars($l['code_etab']) ?></td>
            <td><?= htmlspecialchars($l['nom']) ?><br><small><?= htmlspecialchars($l['commune'] ?? '') ?></small></td>
            <td><?= htmlspecialchars($l['type']) ?></td>
            <td><?= htmlspecialchars($l['cisco']) ?></td>
            <td><?= (int)$l['effectif'] ?></td>
            <td class="actions">
                <a class="btn sec" href="?e=<?= urlencode($l['code_etab']) ?>">Modifier</a>
                <?php if ((int)$l['total'] === 0): ?>
                <form method="post" onsubmit="return confirm('Supprimer cet établissement ?');">
                    <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="code_etab" value="<?= htmlspecialchars($l['code_etab']) ?>">
                    <button class="btn danger" type="submit">Supprimer</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="carte">
    <h3><?= $code_form ? 'Modifier l\'établissement ' . htmlspecialchars($code_form) : 'Ajouter un établissement' ?></h3>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
        <input type="hidden" name="action" value="<?= $code_form ? 'modifier' : 'ajouter' ?>">
        <input type="hidden" name="code_etab" value="<?= htmlspecialchars($code_form) ?>">

        <div class="champ">
            <label for="nom">Nom *</label>
            <input type="text" id="nom" name="nom" maxlength="120" value="<?= htmlspecialchars($f['nom'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="type">Type *</label>
            <select id="type" name="type">
                <option value="">-- Choisir --</option>
                <?php foreach ($types as $t): ?>
                    <option <?= ($f['type'] ?? '') === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="commune">Commune</label>
            <input type="text" id="commune" name="commune" maxlength="80" value="<?= htmlspecialchars($f['commune'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="code_cisco">CISCO *</label>
            <select id="code_cisco" name="code_cisco">
                <option value="">-- Choisir --</option>
                <?php foreach ($ciscos as $c): ?>
                    <option value="<?= htmlspecialchars($c['code_cisco']) ?>"
                        <?= ($f['code_cisco'] ?? '') === $c['code_cisco'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn" type="submit"><?= $code_form ? 'Enregistrer les modifications' : 'Ajouter' ?></button>
        <?php if ($code_form): ?><a class="btn sec" href="etablissements.php">Annuler</a><?php endif; ?>
    </form>
</div>
<?php require "../../includes/footer.php"; ?>
<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN', 'RH']);

$titre_page = "Modifier un agent";
$matricule = $_GET['m'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM agent WHERE matricule = ?");
$stmt->execute([$matricule]);
$agent = $stmt->fetch();
if (!$agent) {
    http_response_code(404);
    die("Agent introuvable.");
}

function date_valide($d) {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

$grades    = $pdo->query("SELECT code_grade, libelle FROM grade ORDER BY libelle")->fetchAll();
$fonctions = $pdo->query("SELECT code_fonction, libelle FROM fonction ORDER BY libelle")->fetchAll();

$champs  = ['nom','prenoms','sexe','date_naissance','cin','telephone',
            'date_recrutement','code_grade','code_fonction'];
$erreurs = [];
$v = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }

    foreach ($champs as $c) {
        $v[$c] = trim($_POST[$c] ?? '');
    }

    // Le CIN est affiché par groupes de 3 chiffres ; seul ce format exact est accepté
    if (preg_match('/^[0-9]{3} [0-9]{3} [0-9]{3} [0-9]{3}$/', $v['cin'])) {
        $v['cin'] = str_replace(' ', '', $v['cin']);
    }

    // Validations
    if ($v['nom'] === '') { $erreurs[] = "Le nom est obligatoire."; }
    if (!in_array($v['sexe'], ['M', 'F'])) { $erreurs[] = "Choisissez le sexe."; }
    if ($v['date_naissance'] !== '') {
        if (!date_valide($v['date_naissance'])) {
            $erreurs[] = "Date de naissance invalide.";
        } elseif ($v['date_naissance'] > date('Y-m-d')) {
            $erreurs[] = "La date de naissance ne peut pas être dans le futur.";
        }
    }
    if ($v['date_recrutement'] !== '' && !date_valide($v['date_recrutement'])) {
        $erreurs[] = "Date de recrutement invalide.";
    }
    if ($v['telephone'] !== '' && !preg_match('/^[0-9 +]{9,18}$/', $v['telephone'])) {
        $erreurs[] = "Numéro de téléphone invalide.";
    }
    if ($v['code_grade'] === '' || $v['code_fonction'] === '') {
        $erreurs[] = "Choisissez le grade et la fonction.";
    }
    if ($v['cin'] !== '' && !preg_match('/^[0-9]{12}$/', $v['cin'])) {
        $erreurs[] = "Le CIN doit contenir exactement 12 chiffres (sans lettre, point, virgule ni espace ajouté à la main).";
    }

    // CIN déjà attribué à un AUTRE agent ?
    if (!$erreurs && $v['cin'] !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM agent WHERE cin = ? AND matricule <> ?");
        $stmt->execute([$v['cin'], $matricule]);
        if ($stmt->fetchColumn() > 0) {
            $erreurs[] = "Ce CIN est déjà attribué à un autre agent.";
        }
    }

    // La date de recrutement ne peut pas suivre la première affectation
    if (!$erreurs && $v['date_recrutement'] !== '') {
        $stmt = $pdo->prepare("SELECT MIN(date_debut) FROM affectation WHERE matricule = ?");
        $stmt->execute([$matricule]);
        $premiere = $stmt->fetchColumn();
        if ($premiere && $v['date_recrutement'] > $premiere) {
            $erreurs[] = "La date de recrutement ne peut pas être postérieure à la première affectation ("
                       . date("d/m/Y", strtotime($premiere)) . ").";
        }
    }

    // Enregistrement
    if (!$erreurs) {
        try {
            $pdo->prepare(
                "UPDATE agent SET nom = ?, prenoms = ?, sexe = ?, date_naissance = ?, cin = ?,
                                  telephone = ?, date_recrutement = ?, code_grade = ?, code_fonction = ?
                 WHERE matricule = ?"
            )->execute([
                $v['nom'], $v['prenoms'], $v['sexe'],
                $v['date_naissance'] ?: null, $v['cin'] ?: null,
                $v['telephone'] ?: null, $v['date_recrutement'] ?: null,
                $v['code_grade'], $v['code_fonction'], $matricule
            ]);
            $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                ->execute(['MODIF_AGENT', "Agent $matricule modifié", $_SESSION['user']['id']]);
            header("Location: fiche.php?m=" . urlencode($matricule) . "&ok=1");
            exit;
        } catch (PDOException $e) {
            $erreurs[] = "Erreur technique : les modifications n'ont pas été enregistrées.";
        }
    }
} else {
    // Première ouverture : on remplit le formulaire avec les données de la base
    foreach ($champs as $c) {
        $v[$c] = $agent[$c] ?? '';
    }
    if ($v['cin'] !== '') {
        $v['cin'] = trim(chunk_split($v['cin'], 3, ' '));
    }
}

require "../../includes/header.php";
?>
<div class="carte">
    <h2>Modifier l'agent <?= htmlspecialchars($matricule) ?></h2>

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
            <label>Matricule (non modifiable)</label>
            <input type="text" value="<?= htmlspecialchars($matricule) ?>" disabled>
        </div>
        <div class="champ">
            <label for="nom">Nom *</label>
            <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($v['nom']) ?>">
        </div>
        <div class="champ">
            <label for="prenoms">Prénoms</label>
            <input type="text" id="prenoms" name="prenoms" value="<?= htmlspecialchars($v['prenoms']) ?>">
        </div>
        <div class="champ">
            <label for="sexe">Sexe *</label>
            <select id="sexe" name="sexe">
                <option value="">-- Choisir --</option>
                <option value="M" <?= $v['sexe'] === 'M' ? 'selected' : '' ?>>Masculin</option>
                <option value="F" <?= $v['sexe'] === 'F' ? 'selected' : '' ?>>Féminin</option>
            </select>
        </div>
        <div class="champ">
            <label for="date_naissance">Date de naissance</label>
            <input type="date" id="date_naissance" name="date_naissance"
                   value="<?= htmlspecialchars($v['date_naissance']) ?>">
        </div>
        <div class="champ">
            <label for="cin">CIN (12 chiffres)</label>
            <input type="text" id="cin" name="cin" maxlength="15"
                   inputmode="numeric" autocomplete="off" placeholder="000 000 000 000"
                   pattern="[0-9]{3} [0-9]{3} [0-9]{3} [0-9]{3}" title="12 chiffres exactement"
                   value="<?= htmlspecialchars($v['cin']) ?>">
        </div>
        <div class="champ">
            <label for="telephone">Téléphone</label>
            <input type="text" id="telephone" name="telephone" value="<?= htmlspecialchars($v['telephone']) ?>">
        </div>
        <div class="champ">
            <label for="date_recrutement">Date de recrutement</label>
            <input type="date" id="date_recrutement" name="date_recrutement"
                   value="<?= htmlspecialchars($v['date_recrutement']) ?>">
        </div>
        <div class="champ">
            <label for="code_grade">Grade *</label>
            <select id="code_grade" name="code_grade">
                <option value="">-- Choisir --</option>
                <?php foreach ($grades as $g): ?>
                    <option value="<?= htmlspecialchars($g['code_grade']) ?>"
                        <?= $v['code_grade'] === $g['code_grade'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($g['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="code_fonction">Fonction *</label>
            <select id="code_fonction" name="code_fonction">
                <option value="">-- Choisir --</option>
                <?php foreach ($fonctions as $f): ?>
                    <option value="<?= htmlspecialchars($f['code_fonction']) ?>"
                        <?= $v['code_fonction'] === $f['code_fonction'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($f['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn" type="submit">Enregistrer les modifications</button>
        <a class="btn sec" href="fiche.php?m=<?= urlencode($matricule) ?>">Annuler</a>
    </form>
</div>

<script>
const cin = document.getElementById('cin');
function formaterCIN() {
    const pos = cin.selectionStart ?? cin.value.length;
    const avant = cin.value.slice(0, pos).replace(/\D/g, '').length;
    const chiffres = cin.value.replace(/\D/g, '').slice(0, 12);
    const formate = chiffres.replace(/(\d{3})(?=\d)/g, '$1 ');
    cin.value = formate;
    if (document.activeElement === cin) {
        let n = 0, p = 0;
        while (p < formate.length && n < avant) { if (formate[p] !== ' ') n++; p++; }
        cin.setSelectionRange(p, p);
    }
}
cin.addEventListener('input', formaterCIN);
cin.addEventListener('keydown', e => { if (e.key === ' ') e.preventDefault(); });
formaterCIN();
</script>
<?php require "../../includes/footer.php"; ?>
<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN', 'RH']);

$titre_page = "Ajouter un personnel";
$aujourdhui = date('Y-m-d');
$erreurs = [];
$v = [];  // valeurs saisies, pour ne pas les perdre en cas d'erreur

function date_valide($d) {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

$grades    = $pdo->query("SELECT code_grade, libelle FROM grade ORDER BY libelle")->fetchAll();
$fonctions = $pdo->query("SELECT code_fonction, libelle FROM fonction ORDER BY libelle")->fetchAll();
$etabs     = $pdo->query(
    "SELECT e.code_etab, e.nom, c.nom AS cisco
     FROM etablissement e JOIN cisco c ON c.code_cisco = e.code_cisco
     ORDER BY c.nom, e.nom"
)->fetchAll();
$actes     = $pdo->query("SELECT code_acte, libelle FROM type_acte ORDER BY libelle")->fetchAll();
$codes_etab  = array_column($etabs, 'code_etab');
$codes_actes = array_column($actes, 'code_acte');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }

    // 1. Récupérer et nettoyer les champs
    foreach (['matricule', 'nom', 'prenoms', 'sexe', 'date_naissance', 'cin', 'telephone',
              'date_recrutement', 'code_grade', 'code_fonction',
              'code_etab', 'date_service', 'code_acte', 'reference_acte'] as $champ) {
        $v[$champ] = is_string($_POST[$champ] ?? null) ? trim($_POST[$champ]) : '';
    }

    // Le CIN est affiché par groupes de 3 chiffres ; seul ce format exact est accepté
    if (preg_match('/^[0-9]{3} [0-9]{3} [0-9]{3} [0-9]{3}$/', $v['cin'])) {
        $v['cin'] = str_replace(' ', '', $v['cin']);
    }

    // 2. Valider
    if (!preg_match('/^[0-9]{5,10}$/', $v['matricule'])) {
        $erreurs[] = "Le matricule doit contenir de 5 à 10 chiffres.";
    }
    if ($v['nom'] === '') {
        $erreurs[] = "Le nom est obligatoire.";
    }
    if (!in_array($v['sexe'], ['M', 'F'])) {
        $erreurs[] = "Choisissez le sexe.";
    }
    if ($v['date_naissance'] !== '') {
        if (!date_valide($v['date_naissance'])) {
            $erreurs[] = "Date de naissance invalide.";
        } elseif ($v['date_naissance'] > $aujourdhui) {
            $erreurs[] = "La date de naissance ne peut pas être dans le futur.";
        }
    }
    if ($v['date_recrutement'] !== '') {
        if (!date_valide($v['date_recrutement'])) {
            $erreurs[] = "Date de recrutement invalide.";
        } elseif ($v['date_recrutement'] > $aujourdhui) {
            $erreurs[] = "La date de recrutement ne peut pas être dans le futur.";
        }
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

    // Poste obligatoire : tout personnel doit être affecté à un établissement dès son enregistrement
    if (!in_array($v['code_etab'], $codes_etab, true)) {
        $erreurs[] = "Le poste (établissement) est obligatoire : un personnel ne peut pas être enregistré sans poste.";
    }
    if (!date_valide($v['date_service'])) {
        $erreurs[] = "Date de prise de service invalide.";
    } elseif ($v['date_service'] > $aujourdhui) {
        $erreurs[] = "La date de prise de service ne peut pas être dans le futur.";
    } elseif ($v['date_recrutement'] !== '' && date_valide($v['date_recrutement'])
              && $v['date_service'] < $v['date_recrutement']) {
        $erreurs[] = "La date de prise de service ne peut pas précéder la date de recrutement.";
    }
    if ($v['code_acte'] !== '' && !in_array($v['code_acte'], $codes_actes, true)) {
        $erreurs[] = "Type d'acte invalide.";
    }
    if (mb_strlen($v['reference_acte']) > 60) {
        $erreurs[] = "Référence de l'acte trop longue (60 caractères maximum).";
    }

    // 3. Le matricule ou le CIN existent-ils déjà ?
    if (!$erreurs) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM agent WHERE matricule = ?");
        $stmt->execute([$v['matricule']]);
        if ($stmt->fetchColumn() > 0) {
            $erreurs[] = "Ce matricule existe déjà.";
        }
    }
    if (!$erreurs && $v['cin'] !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM agent WHERE cin = ?");
        $stmt->execute([$v['cin']]);
        if ($stmt->fetchColumn() > 0) {
            $erreurs[] = "Ce CIN est déjà attribué à un autre agent.";
        }
    }

    // 4. Enregistrer le personnel ET sa première affectation, ensemble ou pas du tout
    if (!$erreurs) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO agent (matricule, nom, prenoms, sexe, date_naissance, cin,
                                    telephone, date_recrutement, statut, code_grade, code_fonction)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'En poste', ?, ?)"
            )->execute([
                $v['matricule'], $v['nom'], $v['prenoms'], $v['sexe'],
                $v['date_naissance'] ?: null, $v['cin'] ?: null,
                $v['telephone'] ?: null, $v['date_recrutement'] ?: null,
                $v['code_grade'], $v['code_fonction']
            ]);

            $pdo->prepare(
                "INSERT INTO affectation (matricule, code_etab, date_debut, motif, reference_acte, code_acte)
                 VALUES (?, ?, ?, 'Première affectation', ?, ?)"
            )->execute([
                $v['matricule'], $v['code_etab'], $v['date_service'],
                $v['reference_acte'] ?: null, $v['code_acte'] ?: null
            ]);

            $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                ->execute(['AGENT_AJOUT',
                           "Agent {$v['matricule']} créé et affecté à {$v['code_etab']} au {$v['date_service']}",
                           $_SESSION['user']['id']]);

            $pdo->commit();
            header("Location: liste.php?ok=1");
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = ($e->getCode() === '23000')
                ? "Ce matricule ou ce CIN existe déjà."
                : "Erreur technique : le personnel n'a pas été enregistré.";
        }
    }
}

require "../../includes/header.php";
?>
<p class="sous-titre">Tout personnel est enregistré avec son poste : il est affecté à un établissement dès sa création.</p>

<div class="carte">
    <h2>Ajouter un personnel</h2>

    <?php if ($erreurs): ?>
        <div class="erreur">
            <?php foreach ($erreurs as $e): ?>
                <?= htmlspecialchars($e) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">

        <h3>Identité</h3>
        <div class="champ">
            <label for="matricule">Matricule *</label>
            <input type="text" id="matricule" name="matricule"
                   value="<?= htmlspecialchars($v['matricule'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="nom">Nom *</label>
            <input type="text" id="nom" name="nom"
                   value="<?= htmlspecialchars($v['nom'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="prenoms">Prénoms</label>
            <input type="text" id="prenoms" name="prenoms"
                   value="<?= htmlspecialchars($v['prenoms'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="sexe">Sexe *</label>
            <select id="sexe" name="sexe">
                <option value="">-- Choisir --</option>
                <option value="M" <?= ($v['sexe'] ?? '') === 'M' ? 'selected' : '' ?>>Masculin</option>
                <option value="F" <?= ($v['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>Féminin</option>
            </select>
        </div>
        <div class="champ">
            <label for="date_naissance">Date de naissance</label>
            <input type="date" id="date_naissance" name="date_naissance" max="<?= $aujourdhui ?>"
                   value="<?= htmlspecialchars($v['date_naissance'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="cin">CIN (12 chiffres)</label>
            <input type="text" id="cin" name="cin" maxlength="15"
                   inputmode="numeric" autocomplete="off" placeholder="000 000 000 000"
                   pattern="[0-9]{3} [0-9]{3} [0-9]{3} [0-9]{3}" title="12 chiffres exactement"
                   value="<?= htmlspecialchars($v['cin'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="telephone">Téléphone</label>
            <input type="text" id="telephone" name="telephone"
                   value="<?= htmlspecialchars($v['telephone'] ?? '') ?>">
        </div>

        <h3>Carrière</h3>
        <div class="champ">
            <label for="date_recrutement">Date de recrutement</label>
            <input type="date" id="date_recrutement" name="date_recrutement" max="<?= $aujourdhui ?>"
                   value="<?= htmlspecialchars($v['date_recrutement'] ?? '') ?>">
        </div>
        <div class="champ">
            <label for="code_grade">Grade *</label>
            <select id="code_grade" name="code_grade">
                <option value="">-- Choisir --</option>
                <?php foreach ($grades as $g): ?>
                    <option value="<?= htmlspecialchars($g['code_grade']) ?>"
                        <?= ($v['code_grade'] ?? '') === $g['code_grade'] ? 'selected' : '' ?>>
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
                        <?= ($v['code_fonction'] ?? '') === $f['code_fonction'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($f['libelle']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <h3>Poste (obligatoire)</h3>
        <div class="champ">
            <label for="code_etab">Établissement d'affectation *</label>
            <select id="code_etab" name="code_etab" required>
                <option value="">-- Choisir --</option>
                <?php foreach ($etabs as $et): ?>
                    <option value="<?= htmlspecialchars($et['code_etab']) ?>"
                        <?= ($v['code_etab'] ?? '') === $et['code_etab'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($et['nom'] . ' – ' . $et['cisco']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="date_service">Date de prise de service *</label>
            <input type="date" id="date_service" name="date_service" max="<?= $aujourdhui ?>" required
                   value="<?= htmlspecialchars($v['date_service'] ?? $aujourdhui) ?>">
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
                   placeholder="Ex. Décision 120/DREN/26"
                   value="<?= htmlspecialchars($v['reference_acte'] ?? '') ?>">
        </div>

        <button class="btn" type="submit">Enregistrer</button>
        <a class="btn sec" href="liste.php">Annuler</a>
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

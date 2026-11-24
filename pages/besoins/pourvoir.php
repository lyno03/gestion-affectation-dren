<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/besoins.php";
require_once "../../includes/demandes.php";   // fournit appliquer_affectation()
exiger_role(['RH', 'ADMIN', 'DIR']);

$titre_page = "Postes à pourvoir";
$role = $_SESSION['user']['role'];
$peut_agir = in_array($role, ['RH', 'ADMIN']);   // le directeur consulte seulement
$aujourdhui = date('Y-m-d');

$flash = bes_flash_lire();
$tables_ok = bes_tables_ok($pdo);

// Personnels qui ont déjà une demande d'affectation en attente : on ne les propose pas
$en_attente = [];
try {
    $en_attente = $pdo->query("SELECT matricule FROM demande WHERE statut = 'EN_ATTENTE'")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { /* le module des demandes d'affectation n'est pas installé */ }

// ================= Affecter un personnel à un poste =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tables_ok) {

    if (!$peut_agir) {
        http_response_code(403);
        die("Accès refusé : seul le responsable RH affecte les personnels.");
    }
    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }

    $id_poste   = (int)($_POST['id_poste'] ?? 0);
    $matricule  = bes_str($_POST['matricule'] ?? null);
    $date_effet = bes_str($_POST['date_effet'] ?? null);
    $code_acte  = bes_str($_POST['code_acte'] ?? null);
    $reference  = bes_str($_POST['reference_acte'] ?? null);

    try {
        $pdo->beginTransaction();

        // Verrouille le poste : deux clics ne peuvent pas le pourvoir deux fois
        $st = $pdo->prepare(
            "SELECT p.*, d.numero FROM poste_a_pourvoir p
             JOIN demande_personnel d ON d.id_dp = p.id_dp
             WHERE p.id_poste = ? FOR UPDATE"
        );
        $st->execute([$id_poste]);
        $poste = $st->fetch();
        if (!$poste) { throw new RuntimeException("Poste introuvable."); }
        if ($poste['statut'] !== 'A_POURVOIR') { throw new RuntimeException("Ce poste a déjà été pourvu."); }

        if ($matricule === '') { throw new RuntimeException("Choisissez le personnel à affecter."); }
        $st = $pdo->prepare("SELECT matricule, code_fonction, statut FROM agent WHERE matricule = ?");
        $st->execute([$matricule]);
        $agent = $st->fetch();
        if (!$agent) { throw new RuntimeException("Personnel introuvable."); }
        if ($agent['code_fonction'] !== $poste['code_fonction']) {
            throw new RuntimeException("La fonction du personnel ne correspond pas à celle du poste.");
        }
        if ($agent['statut'] === 'Retraité') { throw new RuntimeException("Ce personnel est retraité."); }
        if (in_array($matricule, $en_attente, true)) {
            throw new RuntimeException("Ce personnel a déjà une demande d'affectation en attente de décision.");
        }

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
            $pdo, $matricule, $poste['code_etab'], $date_effet, 'Nécessité de service', $reference, $code_acte
        );

        $pdo->prepare(
            "UPDATE poste_a_pourvoir SET statut = 'POURVU', matricule = ?, id_affectation = ?,
                    date_pourvu = NOW(), id_pourvoyeur = ?
             WHERE id_poste = ?"
        )->execute([$matricule, $id_affectation, $_SESSION['user']['id'], $id_poste]);

        $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
            ->execute(['POSTE_POURVU',
                       $poste['numero'] . " : agent $matricule affecté à {$poste['code_etab']} au $date_effet",
                       $_SESSION['user']['id']]);
        $pdo->commit();
        bes_flash('succes', "Le personnel a été affecté : le poste de la demande " . $poste['numero'] . " est pourvu.");

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        bes_flash('erreur', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        bes_flash('erreur', "Erreur technique : l'affectation n'a pas été enregistrée.");
    }
    header("Location: pourvoir.php");
    exit;
}

// ================= Données de la page =================
$ouverts = [];
$pourvus = [];
$candidats = [];   // personnels disponibles, rangés par fonction
$actes = [];

if ($tables_ok) {
    $ouverts = $pdo->query(
        "SELECT p.*, d.numero, d.urgence, d.date_decision, e.nom AS etab, c.nom AS cisco, fo.libelle AS fonction
         FROM poste_a_pourvoir p
         JOIN demande_personnel d ON d.id_dp = p.id_dp
         JOIN etablissement e ON e.code_etab = p.code_etab
         JOIN cisco c ON c.code_cisco = e.code_cisco
         JOIN fonction fo ON fo.code_fonction = p.code_fonction
         WHERE p.statut = 'A_POURVOIR'
         ORDER BY (d.urgence = 'URGENTE') DESC, p.date_creation, p.id_poste"
    )->fetchAll();

    $pourvus = $pdo->query(
        "SELECT p.*, d.numero, e.nom AS etab, fo.libelle AS fonction,
                TRIM(CONCAT(a.nom, ' ', COALESCE(a.prenoms, ''))) AS agent_nom, u.login AS pourvoyeur
         FROM poste_a_pourvoir p
         JOIN demande_personnel d ON d.id_dp = p.id_dp
         JOIN etablissement e ON e.code_etab = p.code_etab
         JOIN fonction fo ON fo.code_fonction = p.code_fonction
         JOIN agent a ON a.matricule = p.matricule
         LEFT JOIN utilisateur u ON u.id_user = p.id_pourvoyeur
         WHERE p.statut = 'POURVU'
         ORDER BY p.date_pourvu DESC LIMIT 20"
    )->fetchAll();

    if ($peut_agir && $ouverts) {
        $st = $pdo->query(
            "SELECT a.matricule, a.nom, a.prenoms, a.code_fonction, a.statut,
                    af.code_etab, e.nom AS etab
             FROM agent a
             LEFT JOIN affectation af ON af.matricule = a.matricule AND af.date_fin IS NULL
             LEFT JOIN etablissement e ON e.code_etab = af.code_etab
             WHERE a.statut <> 'Retraité'
             ORDER BY a.nom, a.prenoms"
        );
        foreach ($st as $a) {
            if (in_array($a['matricule'], $en_attente, true)) { continue; }
            $candidats[$a['code_fonction']][] = $a;
        }
        $actes = $pdo->query("SELECT code_acte, libelle FROM type_acte ORDER BY libelle")->fetchAll();
    }
}

require "../../includes/header.php";
?>
<p class="sous-titre">
    <?= $peut_agir ? "Chaque demande de personnel acceptée crée des postes à pourvoir : affectez-y un personnel de la même fonction."
                   : "Suivez l'avancement des affectations issues des demandes de personnel acceptées." ?>
</p>

<?php if ($flash): ?>
    <div class="<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>
<?php if (!$tables_ok): ?>
    <div class="erreur">
        Le module n'est pas encore installé. Dans phpMyAdmin, onglet <b>SQL</b> de la base <b>dren_db</b>,
        exécutez le script <b>poste_a_pourvoir.sql</b>, puis rechargez cette page.
    </div>
<?php endif; ?>

<div class="carte tableau">
    <h3>Postes à pourvoir (<?= count($ouverts) ?>)</h3>
    <table>
        <tr><th>Demande</th><th>Établissement</th><th>Poste</th><th>Urgence</th><th>Accordé le</th>
            <th><?= $peut_agir ? 'Affectation' : 'État' ?></th></tr>
        <?php foreach ($ouverts as $p): ?>
        <tr>
            <td><?= htmlspecialchars($p['numero']) ?></td>
            <td><?= htmlspecialchars($p['etab']) ?><br><small><?= htmlspecialchars($p['cisco']) ?></small></td>
            <td><b><?= htmlspecialchars($p['fonction']) ?></b>
                <?php if ($p['precision_poste']): ?><br><small><?= htmlspecialchars($p['precision_poste']) ?></small><?php endif; ?></td>
            <td><span class="urg <?= $p['urgence'] === 'URGENTE' ? 'urgente' : '' ?>"><?= htmlspecialchars(BESOIN_URGENCES[$p['urgence']] ?? $p['urgence']) ?></span></td>
            <td><?= date('d/m/Y', strtotime($p['date_decision'])) ?></td>
            <td>
                <?php if ($peut_agir): ?>
                    <?php
                    $liste = array_filter($candidats[$p['code_fonction']] ?? [], function ($a) use ($p) {
                        return $a['code_etab'] !== $p['code_etab'];   // pas déjà dans cet établissement
                    });
                    ?>
                    <?php if ($liste): ?>
                    <details class="action">
                        <summary class="btn">Affecter un personnel</summary>
                        <form method="post" class="panneau">
                            <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                            <input type="hidden" name="id_poste" value="<?= (int)$p['id_poste'] ?>">
                            <div class="champ">
                                <label>Personnel (<?= htmlspecialchars($p['fonction']) ?>)</label>
                                <select name="matricule" required>
                                    <option value="">-- Choisir --</option>
                                    <?php foreach ($liste as $a): ?>
                                        <option value="<?= htmlspecialchars($a['matricule']) ?>">
                                            <?= htmlspecialchars(trim($a['nom'] . ' ' . $a['prenoms']) . ' (' . $a['matricule'] . ') – '
                                                . ($a['etab'] ?? 'sans poste')
                                                . ($a['statut'] !== 'En poste' ? ' [' . $a['statut'] . ']' : '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="champ">
                                <label>Date d'effet</label>
                                <input type="date" name="date_effet" value="<?= $aujourdhui ?>" max="<?= $aujourdhui ?>" required>
                            </div>
                            <div class="champ">
                                <label>Type d'acte</label>
                                <select name="code_acte">
                                    <option value="">-- Aucun --</option>
                                    <?php foreach ($actes as $ac): ?>
                                        <option value="<?= htmlspecialchars($ac['code_acte']) ?>"><?= htmlspecialchars($ac['libelle']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="champ">
                                <label>Référence de l'acte</label>
                                <input type="text" name="reference_acte" maxlength="60" placeholder="Ex. Décision 120/DREN/26">
                            </div>
                            <button class="btn" type="submit">Confirmer l'affectation</button>
                        </form>
                    </details>
                    <?php else: ?>
                        <small>Aucun personnel disponible avec cette fonction.</small>
                    <?php endif; ?>
                <?php else: ?>
                    <small>En attente d'affectation par le responsable RH</small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$ouverts): ?>
        <tr><td colspan="6">Aucun poste à pourvoir pour le moment.</td></tr>
        <?php endif; ?>
    </table>
</div>

<div class="carte tableau">
    <h3>Derniers postes pourvus</h3>
    <table>
        <tr><th>Demande</th><th>Établissement</th><th>Poste</th><th>Personnel affecté</th><th>Date</th><th>Décision</th></tr>
        <?php foreach ($pourvus as $p): ?>
        <tr>
            <td><?= htmlspecialchars($p['numero']) ?></td>
            <td><?= htmlspecialchars($p['etab']) ?></td>
            <td><?= htmlspecialchars($p['fonction']) ?>
                <?php if ($p['precision_poste']): ?><br><small><?= htmlspecialchars($p['precision_poste']) ?></small><?php endif; ?></td>
            <td><a href="/dren/pages/agents/fiche.php?m=<?= urlencode($p['matricule']) ?>"><?= htmlspecialchars($p['agent_nom']) ?></a></td>
            <td><?= date('d/m/Y', strtotime($p['date_pourvu'])) ?><br><small>par <?= htmlspecialchars($p['pourvoyeur'] ?? '—') ?></small></td>
            <td><a href="/dren/pages/affectations/decision.php?id=<?= (int)$p['id_affectation'] ?>" target="_blank">Voir la décision</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$pourvus): ?>
        <tr><td colspan="6">Aucun poste pourvu pour le moment.</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php require "../../includes/footer.php"; ?>

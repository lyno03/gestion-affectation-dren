<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/demandes.php";
exiger_role(['ADMIN', 'RH', 'DIR', 'CISCO', 'ETAB']);

$titre_page = "Demandes d'affectation";
$role = $_SESSION['user']['role'];
$est_dir = ($role === 'DIR');
$peut_envoyer = in_array($role, ['RH', 'CISCO', 'ETAB']);
$voit_fiches = in_array($role, ['ADMIN', 'RH', 'DIR']);
// Les chefs de CISCO et d'établissement ne voient que les demandes qu'ils ont envoyées
$limite_perso = in_array($role, ['CISCO', 'ETAB']);
$aujourdhui = date('Y-m-d');

$flash = flash_lire();

try {
    $pdo->query("SELECT 1 FROM demande LIMIT 1");
    $table_ok = true;
} catch (PDOException $ex) {
    $table_ok = false;
}

// Onglet choisi (le directeur arrive sur « En attente »)
$statut = dem_str($_GET['statut'] ?? null);
if ($statut !== '' && !isset(DEMANDE_STATUTS[$statut])) { $statut = ''; }
if (!isset($_GET['statut']) && $est_dir) { $statut = 'EN_ATTENTE'; }

$demandes = [];
$compteurs = ['EN_ATTENTE' => 0, 'ACCEPTEE' => 0, 'REFUSEE' => 0];
$actes = [];

if ($table_ok) {
    $perso = $limite_perso ? " WHERE d.id_demandeur = ?" : "";
    $pp = $limite_perso ? [$_SESSION['user']['id']] : [];

    $st = $pdo->prepare("SELECT d.statut, COUNT(*) FROM demande d $perso GROUP BY d.statut");
    $st->execute($pp);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $code => $nb) {
        if (isset($compteurs[$code])) { $compteurs[$code] = (int)$nb; }
    }

    $where = [];
    $params = [];
    if ($limite_perso) { $where[] = "d.id_demandeur = ?"; $params[] = $_SESSION['user']['id']; }
    if ($statut !== '') { $where[] = "d.statut = ?"; $params[] = $statut; }

    $st = $pdo->prepare(
        "SELECT d.*, TRIM(CONCAT(a.nom, ' ', COALESCE(a.prenoms, ''))) AS agent_nom,
                eo.nom AS origine, ed.nom AS destination,
                u.login AS demandeur, ud.login AS decideur
         FROM demande d
         JOIN agent a ON a.matricule = d.matricule
         LEFT JOIN etablissement eo ON eo.code_etab = d.code_etab_origine
         JOIN etablissement ed ON ed.code_etab = d.code_etab_demande
         JOIN utilisateur u ON u.id_user = d.id_demandeur
         LEFT JOIN utilisateur ud ON ud.id_user = d.id_decideur"
         . ($where ? " WHERE " . implode(" AND ", $where) : "")
         . " ORDER BY (d.statut = 'EN_ATTENTE') DESC, d.date_demande DESC, d.id_demande DESC LIMIT 100"
    );
    $st->execute($params);
    $demandes = $st->fetchAll();

    if ($est_dir) {
        $actes = $pdo->query("SELECT code_acte, libelle FROM type_acte ORDER BY libelle")->fetchAll();
    }
}
$total = array_sum($compteurs);

function date_fr($d) { return $d ? date('d/m/Y', strtotime($d)) : '—'; }

require "../../includes/header.php";
?>
<p class="sous-titre">
    <?= $est_dir ? "Examinez les demandes d'affectation reçues, puis acceptez-les ou refusez-les."
                 : "Suivez les demandes d'affectation et la décision du directeur régional." ?>
</p>

<?php if ($flash): ?>
    <div class="<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>
<?php if (!$table_ok): ?>
    <div class="erreur">
        La table « demande » n'existe pas encore. Dans phpMyAdmin, onglet <b>SQL</b> de la base <b>dren_db</b>,
        exécutez le script <b>demande.sql</b>, puis rechargez cette page.
    </div>
<?php endif; ?>

<div class="carte">
    <div class="entete-liste">
        <div class="onglets">
            <a href="?statut=EN_ATTENTE" class="<?= $statut === 'EN_ATTENTE' ? 'sel' : '' ?>">
                En attente <b><?= $compteurs['EN_ATTENTE'] ?></b></a>
            <a href="?statut=ACCEPTEE" class="<?= $statut === 'ACCEPTEE' ? 'sel' : '' ?>">
                Acceptées <b><?= $compteurs['ACCEPTEE'] ?></b></a>
            <a href="?statut=REFUSEE" class="<?= $statut === 'REFUSEE' ? 'sel' : '' ?>">
                Refusées <b><?= $compteurs['REFUSEE'] ?></b></a>
            <a href="?statut=" class="<?= $statut === '' ? 'sel' : '' ?>">Toutes <b><?= $total ?></b></a>
        </div>
        <?php if ($peut_envoyer): ?>
            <a class="btn" href="nouvelle.php"><?= dren_ico('plus') ?> Nouvelle demande</a>
        <?php endif; ?>
    </div>
</div>

<div class="carte tableau">
    <table>
        <tr>
            <th>N°</th><th>Date</th><th>Personnel</th><th>Changement de poste</th>
            <th>Motif</th><th>Envoyée par</th><th>Statut</th><th><?= $est_dir ? 'Décision' : 'Détail' ?></th>
        </tr>
        <?php foreach ($demandes as $d): ?>
        <tr>
            <td><?= htmlspecialchars($d['numero']) ?></td>
            <td><?= date('d/m/Y', strtotime($d['date_demande'])) ?></td>
            <td>
                <?php if ($voit_fiches): ?>
                    <a href="/dren/pages/agents/fiche.php?m=<?= urlencode($d['matricule']) ?>"><?= htmlspecialchars($d['agent_nom']) ?></a>
                <?php else: ?>
                    <?= htmlspecialchars($d['agent_nom']) ?>
                <?php endif; ?>
                <br><small><?= htmlspecialchars($d['matricule']) ?></small>
            </td>
            <td>
                <?= htmlspecialchars($d['origine'] ?? 'Sans poste') ?>
                <span class="fleche" aria-label="vers">→</span>
                <b><?= htmlspecialchars($d['destination']) ?></b>
                <?php if ($d['date_souhaitee']): ?><br><small>Souhaitée pour le <?= date_fr($d['date_souhaitee']) ?></small><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($d['motif']) ?>
                <?php if ($d['commentaire']): ?><br><small><?= htmlspecialchars($d['commentaire']) ?></small><?php endif; ?></td>
            <td><?= htmlspecialchars($d['demandeur']) ?></td>
            <td><span class="dem <?= DEMANDE_STATUTS[$d['statut']][1] ?>"><?= DEMANDE_STATUTS[$d['statut']][0] ?></span></td>
            <td>
                <?php if ($d['statut'] === 'EN_ATTENTE'): ?>
                    <?php if ($est_dir): ?>
                        <details class="action">
                            <summary class="btn">Accepter</summary>
                            <form method="post" action="traiter.php" class="panneau">
                                <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                                <input type="hidden" name="id_demande" value="<?= (int)$d['id_demande'] ?>">
                                <input type="hidden" name="decision" value="accepter">
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
                                <button class="btn" type="submit">Confirmer l'acceptation</button>
                            </form>
                        </details>
                        <details class="action">
                            <summary class="btn danger">Refuser</summary>
                            <form method="post" action="traiter.php" class="panneau">
                                <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                                <input type="hidden" name="id_demande" value="<?= (int)$d['id_demande'] ?>">
                                <input type="hidden" name="decision" value="refuser">
                                <div class="champ">
                                    <label>Motif du refus (obligatoire)</label>
                                    <textarea name="motif_refus" rows="3" minlength="5" maxlength="255" required></textarea>
                                </div>
                                <button class="btn danger" type="submit">Confirmer le refus</button>
                            </form>
                        </details>
                    <?php else: ?>
                        <small>En attente de décision du directeur régional</small>
                    <?php endif; ?>

                <?php elseif ($d['statut'] === 'ACCEPTEE'): ?>
                    <small>Acceptée le <?= date('d/m/Y', strtotime($d['date_decision'])) ?> par <?= htmlspecialchars($d['decideur'] ?? '—') ?>
                        <?= $d['reference_acte'] ? '<br>Réf. ' . htmlspecialchars($d['reference_acte']) : '' ?></small>
                    <?php if ($voit_fiches && $d['id_affectation']): ?>
                        <br><a href="/dren/pages/affectations/decision.php?id=<?= (int)$d['id_affectation'] ?>" target="_blank">Voir la décision</a>
                    <?php endif; ?>

                <?php else: ?>
                    <small>Refusée le <?= date('d/m/Y', strtotime($d['date_decision'])) ?> par <?= htmlspecialchars($d['decideur'] ?? '—') ?>
                        <br><b>Motif :</b> <?= htmlspecialchars($d['motif_refus'] ?? '') ?></small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$demandes): ?>
        <tr><td colspan="8">Aucune demande dans cette catégorie.</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php require "../../includes/footer.php"; ?>

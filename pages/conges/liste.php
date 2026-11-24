<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/conges.php";
exiger_role(['ADMIN', 'DIR', 'RH', 'CISCO', 'ETAB']);

$titre_page = "Demandes de congé";
$role = $_SESSION['user']['role'];
$est_dir = ($role === 'DIR');
$peut_envoyer = in_array($role, ['RH', 'CISCO', 'ETAB']);
$limite_perso = $peut_envoyer;          // chacun ne voit que ses propres demandes ; DIR et ADMIN voient tout

$flash = flash_lire();

try {
    $pdo->query("SELECT 1 FROM demande_conge LIMIT 1");
    $table_ok = true;
} catch (PDOException $ex) {
    $table_ok = false;
}

$statut = dem_str($_GET['statut'] ?? null);
if ($statut !== '' && !isset(CONGE_STATUTS[$statut])) { $statut = ''; }
if (!isset($_GET['statut']) && $est_dir) { $statut = 'EN_ATTENTE'; }

$libelles_roles = ['ADMIN' => 'Administrateur', 'RH' => 'Responsable RH', 'DIR' => 'Directeur régional',
                   'CISCO' => 'Chef de CISCO', 'ETAB' => "Chef d'établissement"];

$conges = [];
$compteurs = ['EN_ATTENTE' => 0, 'ACCEPTEE' => 0, 'REFUSEE' => 0, 'ANNULEE' => 0];

if ($table_ok) {
    $perso = $limite_perso ? " WHERE c.id_user = ?" : "";
    $pp = $limite_perso ? [$_SESSION['user']['id']] : [];

    $st = $pdo->prepare("SELECT c.statut, COUNT(*) FROM demande_conge c $perso GROUP BY c.statut");
    $st->execute($pp);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $code => $nb) {
        if (isset($compteurs[$code])) { $compteurs[$code] = (int)$nb; }
    }

    $where = []; $params = [];
    if ($limite_perso) { $where[] = "c.id_user = ?"; $params[] = $_SESSION['user']['id']; }
    if ($statut !== '') { $where[] = "c.statut = ?"; $params[] = $statut; }

    $st = $pdo->prepare(
        "SELECT c.*, u.login, u.nom_complet, u.code_role, ud.login AS decideur
         FROM demande_conge c
         JOIN utilisateur u ON u.id_user = c.id_user
         LEFT JOIN utilisateur ud ON ud.id_user = c.id_decideur"
         . ($where ? " WHERE " . implode(" AND ", $where) : "")
         . " ORDER BY (c.statut = 'EN_ATTENTE') DESC, c.date_demande DESC, c.id_conge DESC LIMIT 100"
    );
    $st->execute($params);
    $conges = $st->fetchAll();
}
$total = array_sum($compteurs);

function date_fr($d) { return $d ? date('d/m/Y', strtotime($d)) : '—'; }

require "../../includes/header.php";
?>
<p class="sous-titre">
    <?= $est_dir ? "Examinez les demandes de congé du personnel, puis acceptez-les ou refusez-les."
                 : ($peut_envoyer ? "Envoyez vos demandes de congé et suivez la décision du directeur régional."
                                  : "Consultation des demandes de congé du personnel.") ?>
</p>

<?php if ($flash): ?>
    <div class="<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
<?php endif; ?>
<?php if (!$table_ok): ?>
    <div class="erreur">
        La table « demande_conge » n'existe pas encore. Dans phpMyAdmin, onglet <b>SQL</b> de la base <b>dren_db</b>,
        exécutez le script <b>conge.sql</b>, puis rechargez cette page.
    </div>
<?php endif; ?>

<div class="carte">
    <div class="entete-liste">
        <div class="onglets">
            <a href="?statut=EN_ATTENTE" class="<?= $statut === 'EN_ATTENTE' ? 'sel' : '' ?>">En attente <b><?= $compteurs['EN_ATTENTE'] ?></b></a>
            <a href="?statut=ACCEPTEE" class="<?= $statut === 'ACCEPTEE' ? 'sel' : '' ?>">Acceptées <b><?= $compteurs['ACCEPTEE'] ?></b></a>
            <a href="?statut=REFUSEE" class="<?= $statut === 'REFUSEE' ? 'sel' : '' ?>">Refusées <b><?= $compteurs['REFUSEE'] ?></b></a>
            <a href="?statut=" class="<?= $statut === '' ? 'sel' : '' ?>">Toutes <b><?= $total ?></b></a>
        </div>
        <?php if ($peut_envoyer): ?>
            <a class="btn" href="nouvelle.php"><?= dren_ico('plus') ?> Demande de congé</a>
        <?php endif; ?>
    </div>
</div>

<div class="carte tableau">
    <table>
        <tr>
            <th>N°</th><th>Date</th>
            <?php if (!$limite_perso): ?><th>Demandeur</th><?php endif; ?>
            <th>Congé</th><th>Période</th><th>Statut</th><th><?= $est_dir ? 'Décision' : 'Détail' ?></th>
        </tr>
        <?php foreach ($conges as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['numero']) ?></td>
            <td><?= date('d/m/Y', strtotime($c['date_demande'])) ?></td>
            <?php if (!$limite_perso): ?>
            <td><?= htmlspecialchars(trim((string)$c['nom_complet']) !== '' ? $c['nom_complet'] : $c['login']) ?>
                <br><small><?= htmlspecialchars($libelles_roles[$c['code_role']] ?? $c['code_role']) ?></small></td>
            <?php endif; ?>
            <td><b><?= htmlspecialchars($c['type_conge']) ?></b>
                <?php if ($c['commentaire']): ?><br><small><?= htmlspecialchars($c['commentaire']) ?></small><?php endif; ?></td>
            <td>Du <?= date_fr($c['date_debut']) ?> au <?= date_fr($c['date_fin']) ?>
                <br><small><?= (int)$c['nb_jours'] ?> jour(s)</small></td>
            <td><span class="dem <?= CONGE_STATUTS[$c['statut']][1] ?>"><?= CONGE_STATUTS[$c['statut']][0] ?></span></td>
            <td>
                <?php if ($c['statut'] === 'EN_ATTENTE'): ?>
                    <?php if ($est_dir): ?>
                        <form method="post" action="traiter.php" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                            <input type="hidden" name="id_conge" value="<?= (int)$c['id_conge'] ?>">
                            <input type="hidden" name="decision" value="accepter">
                            <button class="btn" type="submit">Accepter</button>
                        </form>
                        <details class="action">
                            <summary class="btn danger">Refuser</summary>
                            <form method="post" action="traiter.php" class="panneau">
                                <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                                <input type="hidden" name="id_conge" value="<?= (int)$c['id_conge'] ?>">
                                <input type="hidden" name="decision" value="refuser">
                                <div class="champ">
                                    <label>Motif du refus (obligatoire)</label>
                                    <textarea name="motif_refus" rows="3" minlength="5" maxlength="255" required></textarea>
                                </div>
                                <button class="btn danger" type="submit">Confirmer le refus</button>
                            </form>
                        </details>
                    <?php elseif ($limite_perso): ?>
                        <small>En attente de décision du directeur régional</small><br>
                        <form method="post" action="traiter.php" style="display:inline"
                              onsubmit="return confirm('Annuler cette demande de congé ?');">
                            <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
                            <input type="hidden" name="id_conge" value="<?= (int)$c['id_conge'] ?>">
                            <input type="hidden" name="decision" value="annuler">
                            <button class="btn danger" type="submit">Annuler ma demande</button>
                        </form>
                    <?php else: ?>
                        <small>En attente de décision</small>
                    <?php endif; ?>

                <?php elseif ($c['statut'] === 'ACCEPTEE'): ?>
                    <small>Acceptée le <?= date('d/m/Y', strtotime($c['date_decision'])) ?> par <?= htmlspecialchars($c['decideur'] ?? '—') ?></small>
                <?php elseif ($c['statut'] === 'REFUSEE'): ?>
                    <small>Refusée le <?= date('d/m/Y', strtotime($c['date_decision'])) ?> par <?= htmlspecialchars($c['decideur'] ?? '—') ?>
                        <br><b>Motif :</b> <?= htmlspecialchars($c['motif_refus'] ?? '') ?></small>
                <?php else: ?>
                    <small>Annulée par le demandeur</small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$conges): ?>
        <tr><td colspan="<?= $limite_perso ? 6 : 7 ?>">Aucune demande de congé dans cette catégorie.</td></tr>
        <?php endif; ?>
    </table>
</div>
<?php require "../../includes/footer.php"; ?>

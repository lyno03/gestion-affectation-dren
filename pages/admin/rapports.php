<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/rapports.php";
exiger_role(['ADMIN']);

$titre_page = "Rapports";
$aujourdhui = date('Y-m-d');
$erreurs = [];
$avert = '';

$etabs = $pdo->query("SELECT code_etab, nom FROM etablissement ORDER BY nom")->fetchAll();
$codes_etab = array_column($etabs, 'code_etab');

// La table des rapports existe-t-elle ?
try {
    $pdo->query("SELECT 1 FROM rapport LIMIT 1");
    $table_ok = true;
} catch (PDOException $ex) {
    $table_ok = false;
}

// ================= Génération d'un rapport =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifier_csrf($_POST['csrf'] ?? '')) {
        die("Requête invalide.");
    }

    $g_du     = rap_str($_POST['du'] ?? null);
    $g_au     = rap_str($_POST['au'] ?? null);
    $g_type   = rap_str($_POST['type'] ?? null);
    $g_etab   = rap_str($_POST['etab'] ?? null);
    $g_format = rap_str($_POST['format'] ?? null);
    $type_choisi = $g_type;
    if ($g_type === '') { $g_type = 'STATS'; }   // « toutes catégories » = statistiques générales

    if (!$table_ok) {
        $erreurs[] = "La table « rapport » n'existe pas encore : exécutez d'abord le script SQL.";
    }
    if (!isset(RAPPORT_TYPES[$g_type])) { $erreurs[] = "Type de rapport invalide."; }
    if (!rap_date_ok($g_du) || !rap_date_ok($g_au)) {
        $erreurs[] = "Période invalide.";
    } elseif ($g_du > $g_au) {
        $erreurs[] = "La date de début ne peut pas suivre la date de fin.";
    } elseif ($g_au > $aujourdhui) {
        $erreurs[] = "La date de fin ne peut pas être dans le futur.";
    }
    if ($g_etab !== '' && !in_array($g_etab, $codes_etab, true)) { $erreurs[] = "Établissement invalide."; }
    if (!isset(RAPPORT_FORMATS[$g_format])) { $erreurs[] = "Choisissez le format du rapport."; }

    if (!$erreurs) {
        try {
            $an = date('Y');
            $st = $pdo->prepare(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 10) AS UNSIGNED)), 0)
                 FROM rapport WHERE numero LIKE ?"
            );
            $st->execute(["RAP-$an-%"]);
            $numero = sprintf('RAP-%s-%03d', $an, (int)$st->fetchColumn() + 1);

            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO rapport (numero, type, date_debut, date_fin, code_etab, format, id_user)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$numero, $g_type, $g_du, $g_au, $g_etab ?: null, strtoupper($g_format),
                        $_SESSION['user']['id']]);
            $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
                ->execute(['RAPPORT_GENERE', "$numero : " . RAPPORT_TYPES[$g_type] . " du $g_du au $g_au",
                           $_SESSION['user']['id']]);
            $pdo->commit();

            header("Location: rapports.php?" . http_build_query([
                'du' => $g_du, 'au' => $g_au, 'type' => $type_choisi, 'etab' => $g_etab,
                'ok' => 1, 'num' => $numero,
            ]));
            exit;
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erreurs[] = "Erreur technique : le rapport n'a pas été généré.";
        }
    }
    // En cas d'erreur, on réaffiche les filtres tels que saisis
    $_GET = ['du' => $g_du, 'au' => $g_au, 'type' => $type_choisi, 'etab' => $g_etab];
}

// ================= Filtres =================
$du = rap_str($_GET['du'] ?? null, date('Y-m-01'));
$au = rap_str($_GET['au'] ?? null, $aujourdhui);
if (!rap_date_ok($du)) { $du = date('Y-m-01'); }
if (!rap_date_ok($au)) { $au = $aujourdhui; }
if ($au > $aujourdhui) { $au = $aujourdhui; }
if ($du > $au) {
    $du = $au;
    $avert = "La date de début était postérieure à la date de fin : elle a été ramenée à la date de fin.";
}
$type = rap_str($_GET['type'] ?? null);
if (!isset(RAPPORT_TYPES[$type])) { $type = ''; }
$etab = rap_str($_GET['etab'] ?? null);
if (!in_array($etab, $codes_etab, true)) { $etab = ''; }

// ================= Chiffres clés =================
$s = rapport_stats($pdo, $du, $au, $etab);

// ================= Graphique : affectations par établissement =================
$p = [$du, $au];
$sql = "SELECT e.nom, COUNT(af.id_affectation) AS nb
        FROM etablissement e
        LEFT JOIN affectation af ON af.code_etab = e.code_etab AND af.date_debut BETWEEN ? AND ?";
if ($etab !== '') { $sql .= " WHERE e.code_etab = ?"; $p[] = $etab; }
$sql .= " GROUP BY e.code_etab, e.nom ORDER BY nb DESC, e.nom LIMIT 8";
$st = $pdo->prepare($sql);
$st->execute($p);
$barres = $st->fetchAll();
$max_barre = 0;
foreach ($barres as $b) { $max_barre = max($max_barre, (int)$b['nb']); }

// ================= Graphique : types de rapports =================
$couleurs = ['PERSONNELS' => '#2f7bf0', 'AFFECTATIONS' => '#2fb67a', 'DEMANDES' => '#f5a623',
             'ETABLISSEMENTS' => '#8b5cf6', 'STATS' => '#14b8a6'];
$par_type = array_fill_keys(array_keys(RAPPORT_TYPES), 0);
$recents = [];
$nb_total_rapports = 0;
$limite = isset($_GET['tous']) ? 100 : 10;

if ($table_ok) {
    foreach ($pdo->query("SELECT type, COUNT(*) AS nb FROM rapport GROUP BY type") as $r) {
        if (isset($par_type[$r['type']])) { $par_type[$r['type']] = (int)$r['nb']; }
    }
    $nb_total_rapports = array_sum($par_type);

    $st = $pdo->prepare(
        "SELECT r.*, u.login, e.nom AS etab
         FROM rapport r
         JOIN utilisateur u ON u.id_user = r.id_user
         LEFT JOIN etablissement e ON e.code_etab = r.code_etab
         WHERE (? = '' OR r.type = ?)
         ORDER BY r.date_generation DESC, r.id_rapport DESC
         LIMIT " . (int)$limite
    );
    $st->execute([$type, $type]);
    $recents = $st->fetchAll();
}

$filtres_url = http_build_query(['du' => $du, 'au' => $au, 'type' => $type, 'etab' => $etab]);

require "../../includes/header.php";
?>
<p class="sous-titre">Consultez et générez les différents rapports du système.</p>

<?php if (!$table_ok): ?>
    <div class="erreur">
        La table « rapport » n'existe pas encore. Dans phpMyAdmin, onglet <b>SQL</b> de la base
        <b>dren_db</b>, exécutez le script <b>rapport.sql</b>, puis rechargez cette page.
    </div>
<?php endif; ?>
<?php if (isset($_GET['ok'])): ?>
    <?php $num = rap_str($_GET['num'] ?? null); ?>
    <div class="succes">
        Le rapport <?= preg_match('/^RAP-\d{4}-\d{3,}$/', $num) ? '<b>' . htmlspecialchars($num) . '</b> ' : '' ?>a bien été généré.
        Retrouvez-le dans « Rapports récents ».
    </div>
<?php endif; ?>
<?php if ($erreurs): ?>
    <div class="erreur">
        <?php foreach ($erreurs as $er): ?><?= htmlspecialchars($er) ?><br><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($avert): ?><div class="alerte"><?= htmlspecialchars($avert) ?></div><?php endif; ?>

<!-- ===== Filtres et génération ===== -->
<div class="carte">
    <div class="rap-barre">
        <form method="get" id="filtres" class="grow">
            <div class="champ">
                <label for="du">Période</label>
                <div class="periode">
                    <input type="date" id="du" name="du" value="<?= htmlspecialchars($du) ?>" max="<?= $aujourdhui ?>">
                    <span aria-hidden="true">→</span>
                    <input type="date" id="au" name="au" value="<?= htmlspecialchars($au) ?>" max="<?= $aujourdhui ?>" aria-label="Date de fin">
                </div>
            </div>
            <div class="champ">
                <label for="type">Type de rapport</label>
                <select id="type" name="type">
                    <option value="">Toutes les catégories</option>
                    <?php foreach (RAPPORT_TYPES as $code => $lib): ?>
                        <option value="<?= $code ?>" <?= $type === $code ? 'selected' : '' ?>><?= htmlspecialchars($lib) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ">
                <label for="etab">Établissement</label>
                <select id="etab" name="etab">
                    <option value="">Tous les établissements</option>
                    <?php foreach ($etabs as $et): ?>
                        <option value="<?= htmlspecialchars($et['code_etab']) ?>" <?= $etab === $et['code_etab'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($et['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ"><button class="btn sec" type="submit">Appliquer</button></div>
        </form>

        <form method="post" id="generation">
            <input type="hidden" name="csrf" value="<?= jeton_csrf() ?>">
            <input type="hidden" name="du" value="<?= htmlspecialchars($du) ?>">
            <input type="hidden" name="au" value="<?= htmlspecialchars($au) ?>">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
            <input type="hidden" name="etab" value="<?= htmlspecialchars($etab) ?>">
            <div class="champ">
                <label for="format">Format</label>
                <select id="format" name="format">
                    <option value="PDF">PDF</option>
                    <option value="EXCEL">Excel</option>
                </select>
            </div>
            <div class="champ">
                <button class="btn" type="submit" <?= $table_ok ? '' : 'disabled' ?>>
                    <?= dren_ico('download') ?> Générer le rapport
                </button>
            </div>
        </form>
    </div>
    <small>La période et l'établissement mettent à jour les chiffres et le graphique. Le type filtre la liste des rapports
        récents et choisit le rapport à générer (« toutes les catégories » génère les statistiques générales).</small>
</div>

<!-- ===== Chiffres clés ===== -->
<div class="kpis">
    <div class="kpi bleu">
        <span class="kpi-ico"><?= dren_ico('users') ?></span>
        <div><span class="t">Total des personnels</span><b><?= $s['personnels'] ?></b>
            <small><?= $s['en_poste'] ?> actuellement en poste</small></div>
    </div>
    <div class="kpi vert">
        <span class="kpi-ico"><?= dren_ico('repeat') ?></span>
        <div><span class="t">Affectations effectuées</span><b><?= $s['affectations'] ?></b>
            <small><?= $s['mois'] ?> ce mois-ci</small></div>
    </div>
    <div class="kpi orange">
        <span class="kpi-ico"><?= dren_ico('file') ?></span>
        <div><span class="t">Demandes traitées</span><b><?= $s['demandes'] ?></b>
            <small>mutations sur demande</small></div>
    </div>
    <div class="kpi violet">
        <span class="kpi-ico"><?= dren_ico('school') ?></span>
        <div><span class="t">Établissements</span><b><?= $s['etablissements'] ?></b>
            <small><?= $s['avec_personnel'] ?> avec du personnel</small></div>
    </div>
</div>

<!-- ===== Graphiques ===== -->
<div class="rap-graphes">
    <div class="carte">
        <h3>Répartition des affectations par établissement</h3>
        <p><small>Du <?= rap_fr($du) ?> au <?= rap_fr($au) ?></small></p>
        <?php if ($max_barre === 0): ?>
            <p>Aucune affectation sur cette période.</p>
        <?php else: ?>
            <div class="bar-chart" role="img" aria-label="Affectations par établissement">
                <?php foreach ($barres as $b): $h = $b['nb'] / $max_barre; ?>
                    <div class="bar-col">
                        <div class="bar-zone">
                            <span class="bar-val"><?= (int)$b['nb'] ?></span>
                            <div class="bar-fill" style="--h:<?= number_format($h, 3, '.', '') ?>"></div>
                        </div>
                        <span class="bar-lab" title="<?= htmlspecialchars($b['nom']) ?>"><?= htmlspecialchars($b['nom']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="carte">
        <h3>Types de rapports</h3>
        <div class="donut-bloc">
            <svg class="donut" viewBox="0 0 42 42" role="img" aria-label="Répartition des rapports par type">
                <circle cx="21" cy="21" r="15.9155" fill="none" stroke="var(--ln)" stroke-width="6"/>
                <?php $cum = 0; if ($nb_total_rapports > 0): foreach ($par_type as $code => $nb): if ($nb === 0) continue;
                    $pct = $nb / $nb_total_rapports * 100; ?>
                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="<?= $couleurs[$code] ?>" stroke-width="6"
                            stroke-dasharray="<?= number_format($pct, 2, '.', '') ?> <?= number_format(100 - $pct, 2, '.', '') ?>"
                            stroke-dashoffset="<?= number_format(25 - $cum, 2, '.', '') ?>"/>
                    <?php $cum += $pct; endforeach; endif; ?>
                <text x="21" y="20" text-anchor="middle" font-size="3.6">Total</text>
                <text x="21" y="25" text-anchor="middle" font-size="3.6" font-weight="700"><?= $nb_total_rapports ?> rapport<?= $nb_total_rapports > 1 ? 's' : '' ?></text>
            </svg>
            <ul class="legende">
                <?php foreach (RAPPORT_TYPES as $code => $lib): ?>
                    <li><i style="background:<?= $couleurs[$code] ?>"></i><span><?= htmlspecialchars($lib) ?></span><b><?= $par_type[$code] ?></b></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<!-- ===== Rapports récents ===== -->
<div class="carte tableau">
    <div class="entete-liste">
        <h3>Rapports récents<?= $type !== '' ? ' – ' . htmlspecialchars(RAPPORT_TYPES[$type]) : '' ?></h3>
        <?php if (!isset($_GET['tous']) && $nb_total_rapports > 10): ?>
            <a href="?<?= htmlspecialchars($filtres_url) ?>&amp;tous=1">Voir tous les rapports →</a>
        <?php endif; ?>
    </div>
    <table>
        <tr>
            <th>N°</th><th>Type de rapport</th><th>Période</th><th>Date de génération</th>
            <th>Généré par</th><th>Format</th><th>Action</th>
        </tr>
        <?php foreach ($recents as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['numero']) ?></td>
            <td><?= htmlspecialchars(RAPPORT_TYPES[$r['type']] ?? $r['type']) ?>
                <?php if ($r['etab']): ?><br><small><?= htmlspecialchars($r['etab']) ?></small><?php endif; ?></td>
            <td><?= rap_fr($r['date_debut']) ?> - <?= rap_fr($r['date_fin']) ?></td>
            <td><?= date('d/m/Y H:i', strtotime($r['date_generation'])) ?></td>
            <td><?= htmlspecialchars($r['login']) ?></td>
            <td><span class="fmt <?= $r['format'] === 'PDF' ? 'pdf' : 'xls' ?>"><?= $r['format'] === 'PDF' ? 'PDF' : 'Excel' ?></span></td>
            <td class="actions">
                <a class="ico-act" href="rapport_voir.php?id=<?= (int)$r['id_rapport'] ?>" target="_blank"
                   title="Consulter" aria-label="Consulter <?= htmlspecialchars($r['numero']) ?>"><?= dren_ico('eye') ?></a>
                <?php if ($r['format'] === 'PDF'): ?>
                    <a class="ico-act" href="rapport_voir.php?id=<?= (int)$r['id_rapport'] ?>&amp;imprimer=1" target="_blank"
                       title="Télécharger en PDF" aria-label="Télécharger <?= htmlspecialchars($r['numero']) ?> en PDF"><?= dren_ico('download') ?></a>
                <?php else: ?>
                    <a class="ico-act" href="rapport_excel.php?id=<?= (int)$r['id_rapport'] ?>"
                       title="Télécharger pour Excel" aria-label="Télécharger <?= htmlspecialchars($r['numero']) ?> pour Excel"><?= dren_ico('download') ?></a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$recents): ?>
        <tr><td colspan="7">Aucun rapport généré pour le moment.</td></tr>
        <?php endif; ?>
    </table>
</div>

<script>
// Les filtres s'appliquent dès qu'on change une valeur
document.querySelectorAll('#filtres select, #filtres input[type="date"]').forEach(function (el) {
    el.addEventListener('change', function () { document.getElementById('filtres').submit(); });
});
</script>
<?php require "../../includes/footer.php"; ?>

<?php
require "../../config/database.php";
require "../../includes/auth.php";
require_once "../../includes/rapports.php";
exiger_role(['ADMIN']);

$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare(
    "SELECT r.*, u.login, e.nom AS etab
     FROM rapport r
     JOIN utilisateur u ON u.id_user = r.id_user
     LEFT JOIN etablissement e ON e.code_etab = r.code_etab
     WHERE r.id_rapport = ?"
);
$st->execute([$id]);
$r = $st->fetch();
if (!$r) {
    http_response_code(404);
    die("Rapport introuvable.");
}

$d = rapport_donnees($pdo, $r['type'], $r['date_debut'], $r['date_fin'], $r['code_etab'] ?? '');
$titre = RAPPORT_TYPES[$r['type']] ?? $r['type'];
$imprimer = isset($_GET['imprimer']);

// Une impression ou un export de données personnelles laisse une trace
if ($imprimer) {
    $pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
        ->execute(['RAPPORT_EXPORT', $r['numero'] . " (PDF)", $_SESSION['user']['id']]);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/dren/assets/img/logo-dren.png">
    <title><?= htmlspecialchars($r['numero']) ?> – <?= htmlspecialchars($titre) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12pt; color: #000; background: #e8ecec; margin: 0; }
        .outils { background: #1f5fc4; padding: 10px 20px; }
        .outils a, .outils button { color: #fff; background: none; border: 1px solid #fff; padding: 6px 12px;
            border-radius: 20px; text-decoration: none; cursor: pointer; font-size: 14px; margin-right: 8px; }
        .page { background: #fff; max-width: 29cm; margin: 20px auto; padding: 1.6cm; }
        .entete { text-align: center; font-size: 10.5pt; margin-bottom: 14px; }
        .logos-doc { display: flex; justify-content: center; gap: 14px; margin-bottom: 6px; }
        .logos-doc img { width: 2.2cm; height: 2.2cm; object-fit: contain; }
        h1 { text-align: center; font-size: 17pt; margin: 14px 0 4px; text-transform: uppercase; }
        .num { text-align: center; margin-bottom: 14px; }
        .meta { font-size: 10.5pt; margin-bottom: 14px; border: 1px solid #999; padding: 8px 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5pt; }
        th, td { border: 1px solid #888; padding: 5px 8px; text-align: left; vertical-align: top; }
        th { background: #e6eefb; }
        .pied { margin-top: 14px; font-size: 9.5pt; color: #444; }
        @media print {
            body { background: #fff; }
            .outils { display: none; }
            .page { margin: 0; padding: 0; max-width: none; }
            @page { size: A4 landscape; margin: 1.5cm; }
            tr { page-break-inside: avoid; }
            thead { display: table-header-group; }
        }
    </style>
</head>
<body>
<div class="outils">
    <button onclick="window.print()">Imprimer / Enregistrer en PDF</button>
    <a href="rapport_excel.php?id=<?= (int)$r['id_rapport'] ?>">Télécharger pour Excel</a>
    <a href="rapports.php">← Retour aux rapports</a>
</div>

<div class="page">
    <div class="entete">
        <div class="logos-doc">
            <img src="/dren/assets/img/logo-men.png" alt="Ministère de l'Éducation Nationale">
            <img src="/dren/assets/img/logo-dren.png" alt="DREN Atsimo Andrefana">
        </div>
        MINISTÈRE DE L'ÉDUCATION NATIONALE – DIRECTION RÉGIONALE ATSIMO ANDREFANA
    </div>
    <h1><?= htmlspecialchars($titre) ?></h1>
    <div class="num">N° <?= htmlspecialchars($r['numero']) ?></div>

    <div class="meta">
        <b>Période :</b> du <?= rap_fr($r['date_debut']) ?> au <?= rap_fr($r['date_fin']) ?><br>
        <b>Établissement :</b> <?= $r['etab'] ? htmlspecialchars($r['etab']) : 'Tous les établissements' ?><br>
        <b>Généré le :</b> <?= date('d/m/Y à H:i', strtotime($r['date_generation'])) ?>
        par <?= htmlspecialchars($r['login']) ?><br>
        <b>Nombre de lignes :</b> <?= count($d['lignes']) ?>
    </div>

    <table>
        <thead>
            <tr><?php foreach ($d['colonnes'] as $c): ?><th><?= htmlspecialchars($c) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
            <?php foreach ($d['lignes'] as $ligne): ?>
            <tr><?php foreach ($ligne as $cellule): ?><td><?= htmlspecialchars((string)$cellule) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php if (!$d['lignes']): ?>
            <tr><td colspan="<?= max(1, count($d['colonnes'])) ?>">Aucune donnée pour ces critères.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="pied">
        Données extraites de la base le <?= date('d/m/Y à H:i') ?> à partir des critères enregistrés à la génération.
    </div>
</div>

<?php if ($imprimer): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>

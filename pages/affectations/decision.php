<?php
require "../../config/database.php";
require "../../includes/auth.php";
exiger_role(['ADMIN', 'RH', 'DIR']);

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT af.*, a.nom, a.prenoms, a.sexe, a.matricule,
            g.libelle AS grade, f.libelle AS fonction,
            e.nom AS etab, e.type AS etab_type, e.commune, c.nom AS cisco,
            ta.libelle AS type_acte
     FROM affectation af
     JOIN agent a ON a.matricule = af.matricule
     LEFT JOIN grade g ON g.code_grade = a.code_grade
     LEFT JOIN fonction f ON f.code_fonction = a.code_fonction
     JOIN etablissement e ON e.code_etab = af.code_etab
     JOIN cisco c ON c.code_cisco = e.code_cisco
     LEFT JOIN type_acte ta ON ta.code_acte = af.code_acte
     WHERE af.id_affectation = ?"
);
$stmt->execute([$id]);
$d = $stmt->fetch();
if (!$d) {
    http_response_code(404);
    die("Affectation introuvable.");
}

// Poste précédent de l'agent (s'il existe)
$stmt = $pdo->prepare(
    "SELECT e.nom FROM affectation x
     JOIN etablissement e ON e.code_etab = x.code_etab
     WHERE x.matricule = ? AND (x.date_debut < ? OR (x.date_debut = ? AND x.id_affectation < ?))
     ORDER BY x.date_debut DESC, x.id_affectation DESC LIMIT 1"
);
$stmt->execute([$d['matricule'], $d['date_debut'], $d['date_debut'], $id]);
$ancien = $stmt->fetchColumn();

// Date en toutes lettres : 16 octobre 2026
function date_longue($date) {
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
             'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($date);
    return (int)date('j', $t) . ($date === '' ? '' : ((int)date('j', $t) === 1 ? 'er' : '')) . ' '
         . $mois[(int)date('n', $t)] . ' ' . date('Y', $t);
}

$civilite = $d['sexe'] === 'F' ? 'Madame' : ($d['sexe'] === 'M' ? 'Monsieur' : "L'agent");
$accord   = $d['sexe'] === 'F' ? 'affectée' : 'affecté';
$numero   = $d['reference_acte'] ?: '........ / DREN / ' . date('Y', strtotime($d['date_debut']));
$nature   = $d['type_acte'] ?: 'Décision';

// Trace de l'édition dans le journal
$pdo->prepare("INSERT INTO journal (action, details, id_user) VALUES (?, ?, ?)")
    ->execute(['DECISION_EDITEE', "Affectation n°$id (agent {$d['matricule']})", $_SESSION['user']['id']]);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/dren/assets/img/logo-dren.png">
    <title>Décision d'affectation – <?= htmlspecialchars($d['nom']) ?></title>
    <style>
        body { font-family: "Times New Roman", serif; font-size: 14pt; line-height: 1.5;
               color: #000; background: #e8ecec; margin: 0; }
        .outils { background: #1f5a6b; padding: 10px 20px; font-family: Arial, sans-serif; }
        .outils a, .outils button { color: #fff; background: none; border: 1px solid #fff;
               padding: 6px 12px; border-radius: 4px; text-decoration: none; cursor: pointer;
               font-size: 14px; margin-right: 8px; }
        .page { background: #fff; max-width: 21cm; margin: 20px auto; padding: 2cm; }
        .entete { display: flex; justify-content: space-between; gap: 20px; font-size: 11pt;
                  text-align: center; }
        .entete div { flex: 1; }
        .logos-doc { display: flex; justify-content: center; gap: 14px; margin-bottom: 6px; }
        .logos-doc img { width: 2.4cm; height: 2.4cm; object-fit: contain; }
        .titre { text-align: center; font-weight: bold; font-size: 16pt; margin: 28px 0 6px;
                 text-transform: uppercase; }
        .numero { text-align: center; margin-bottom: 24px; }
        .art { margin: 12px 0; text-align: justify; }
        .signature { margin-top: 40px; text-align: right; }
        .signature .espace { height: 90px; }
        .copie { margin-top: 30px; font-size: 11pt; }
        @media print {
            body { background: #fff; }
            .outils { display: none; }
            .page { margin: 0; padding: 0; max-width: none; }
            @page { size: A4; margin: 2cm; }
        }
    </style>
</head>
<body>
<div class="outils">
    <button onclick="window.print()">Imprimer / Enregistrer en PDF</button>
    <a href="historique.php">← Retour à l'historique</a>
</div>

<div class="page">
    <div class="entete">
        <div>
            <div class="logos-doc">
                <img src="/dren/assets/img/logo-men.png" alt="Ministère de l'Éducation Nationale">
                <img src="/dren/assets/img/logo-dren.png" alt="DREN Atsimo Andrefana">
            </div>
            MINISTÈRE DE L'ÉDUCATION NATIONALE<br>
            ————<br>
            DIRECTION RÉGIONALE DE L'ÉDUCATION NATIONALE<br>
            ATSIMO ANDREFANA
        </div>
        <div>
            REPUBLIKAN'I MADAGASIKARA<br>
            Fitiavana – Tanindrazana – Fandrosoana
        </div>
    </div>

    <div class="titre"><?= htmlspecialchars($nature) ?> d'affectation</div>
    <div class="numero">N° <?= htmlspecialchars($numero) ?></div>

    <p class="art">
        <b>Le Directeur Régional de l'Éducation Nationale d'Atsimo Andrefana,</b>
    </p>
    <p class="art">
        Vu les textes en vigueur régissant la gestion des personnels de l'Éducation Nationale ;<br>
        Vu la nécessité d'assurer le bon fonctionnement des établissements et services de la région ;
    </p>

    <p class="art"><b>DÉCIDE :</b></p>

    <p class="art">
        <b>Article premier.</b> – <?= htmlspecialchars($civilite) ?>
        <b><?= htmlspecialchars(mb_strtoupper($d['nom']) . ' ' . $d['prenoms']) ?></b>,
        matricule <b><?= htmlspecialchars($d['matricule']) ?></b>,
        <?= htmlspecialchars($d['grade'] ?? 'grade non renseigné') ?>,
        <?= htmlspecialchars(mb_strtolower($d['fonction'] ?? 'fonction non renseignée')) ?>,
        <?php if ($ancien): ?>précédemment en service à <?= htmlspecialchars($ancien) ?>,<?php endif; ?>
        est <?= $accord ?> à <b><?= htmlspecialchars($d['etab']) ?></b>
        (<?= htmlspecialchars($d['cisco']) ?><?= $d['commune'] ? ', ' . htmlspecialchars($d['commune']) : '' ?>),
        au titre du motif suivant : <?= htmlspecialchars(mb_strtolower($d['motif'])) ?>.
    </p>

    <p class="art">
        <b>Article 2.</b> – La présente décision prend effet à compter du
        <b><?= htmlspecialchars(date_longue($d['date_debut'])) ?></b>.
    </p>

    <p class="art">
        <b>Article 3.</b> – Le Chef de la circonscription scolaire concernée, le Chef de service des
        ressources humaines et l'intéressé<?= $d['sexe'] === 'F' ? 'e' : '' ?> sont chargés, chacun en ce qui
        le concerne, de l'exécution de la présente décision.
    </p>

    <div class="signature">
        Fait à Toliara, le <?= htmlspecialchars(date_longue(date('Y-m-d'))) ?><br>
        <b>Le Directeur Régional</b>
        <div class="espace"></div>
        ........................................
    </div>

    <div class="copie">
        <b>Ampliations :</b> intéressé<?= $d['sexe'] === 'F' ? 'e' : '' ?> ; CISCO <?= htmlspecialchars($d['cisco']) ?> ;
        établissement d'accueil ; dossier individuel.
    </div>
</div>
</body>
</html>
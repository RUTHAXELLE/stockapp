<?php
// ============================================================
//  includes/pdf_achats.php — Génération des PDF du module Achats
//  (dompdf, même stack que pages/operations/point_pdf.php et la
//  fonction _bdc_pdf() de pages/commandes.php).
// ============================================================
require_once __DIR__ . '/achats.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/helpers.php';

// ── En-tête commun aux deux PDF — même style que le reste des exports
//    Achats (navy #06033A, bleu #1B75BC), table-based pour dompdf.
function ach_pdf_entete(array $feb, string $titre): string {
    $logo = function_exists('pdf_logo_img') ? pdf_logo_img('40px') : '';
    return "
    <table width='100%' style='border-collapse:collapse;background-color:#06033A'>
      <tr>
        <td style='padding:14px 18px;width:90px'>
          <div style='background:white;border-radius:6px;padding:4px 7px;display:inline-block'>$logo</div>
        </td>
        <td style='padding:14px 18px'>
          <div style='color:rgba(255,255,255,.6);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px'>$titre</div>
          <div style='color:#00AEEF;font-size:19px;font-weight:800'>" . h($feb['numero'] ?: '— (brouillon)') . "</div>
        </td>
      </tr>
    </table>";
}

// ── HTML de la fiche de validation archivée (Bloc 1) — générée une seule
//    fois à la confirmation, jamais recalculée. Contenu figé au moment de
//    l'appel : en-tête, toutes les lignes (achat et stock), lots et offre
//    retenue de chaque lot, montant total, circuit de validation (les
//    rôles du workflow_snapshot figé — pas un libellé de palier relu en
//    direct, qui pourrait avoir changé depuis), signatures.
function ach_html_fiche_validation(int $feb_id): string {
    $row = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$row) throw new AchValidationException('FEB introuvable.');
    $feb = ach_feb_decode($row);

    $demandeur  = db_fetch_one("SELECT CONCAT(prenom,' ',nom) AS nom FROM users WHERE id=?", [$feb['demandeur_id']]);
    $acheteur   = $feb['acheteur_id'] ? db_fetch_one("SELECT CONCAT(prenom,' ',nom) AS nom FROM users WHERE id=?", [$feb['acheteur_id']]) : null;
    $site_nom   = $feb['site_id'] ? db_fetch_value("SELECT nom FROM sites WHERE id=?", [$feb['site_id']]) : null;
    $dept_nom   = $feb['departement_id'] ? db_fetch_value("SELECT label FROM departements WHERE id=?", [$feb['departement_id']]) : null;

    $lignes = db_fetch_all("SELECT * FROM feb_lignes WHERE feb_id=? ORDER BY numero_ligne", [$feb_id]);
    $lots   = ach_lots_feb($feb_id);

    $lignes_html = '';
    foreach ($lignes as $l) {
        $badge = $l['arbitrage'] === 'stock' ? '#D1FAE5;color:#065F46' : '#FEF3C7;color:#92400E';
        $badge_txt = $l['arbitrage'] === 'stock' ? 'Stock' : 'Achat';
        $lignes_html .= "<tr>
            <td style='padding:6px 9px;border:1px solid #d1d5db'>" . h($l['designation']) . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db;text-align:center'>" . (int)$l['quantite'] . " " . h($l['unite'] ?: '') . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db;font-family:monospace;font-size:10px'>" . h($l['lot'] ?: '—') . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db;text-align:right'>" . number_format((float)$l['montant_ttc'], 0, ',', ' ') . " XOF</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db;text-align:center'><span style='background:$badge;padding:2px 8px;border-radius:10px;font-size:9px;font-weight:700'>$badge_txt</span></td>
        </tr>";
    }

    $lots_html = '';
    foreach ($lots as $lot) {
        $offre = db_fetch_one("SELECT o.*, f.raison_sociale FROM feb_offres o LEFT JOIN fournisseurs f ON f.id=o.fournisseur_id WHERE o.feb_id=? AND o.lot=? AND o.retenue=1", [$feb_id, $lot['lot']]);
        $lots_html .= "<div style='margin-bottom:10px;border:1px solid #d1d5db;border-radius:6px;overflow:hidden'>
            <div style='background:#fff7ed;padding:6px 10px;font-weight:700;color:#B45309;font-size:11px;font-family:monospace'>Lot " . h($lot['lot']) . "</div>
            <div style='padding:8px 10px;font-size:11px'>" .
                ($offre
                    ? "Offre retenue : <strong>" . h($offre['raison_sociale'] ?: '—') . "</strong> — " . number_format((float)$offre['montant_ttc'], 0, ',', ' ') . " XOF"
                    : "<span style='color:#888'>Aucune offre retenue enregistrée.</span>") .
            "</div>
        </div>";
    }

    // Circuit aval seul : le visa du supérieur hiérarchique (role 'n1',
    // désormais première étape du circuit — cf. ach_lancer_validation())
    // appartient à la fiche demandeur (bloc DEMANDEUR + SUPÉRIEUR
    // HIÉRARCHIQUE, ach_html_fiche_imprimable()), pas à cette pièce
    // archivée qui documente uniquement le circuit financier aval
    // (paliers RAF/DAF/PDG). Filtré par label, pas seulement exclu du
    // circuit : sa signature ne doit pas non plus apparaître ci-dessous.
    $n1_label_validation = null;
    foreach ($feb['workflow_snapshot'] as $e) {
        if (($e['role'] ?? '') === 'n1') { $n1_label_validation = $e['label'] ?? null; break; }
    }
    $etapes_aval = array_values(array_filter($feb['workflow_snapshot'], fn($e) => ($e['role'] ?? '') !== 'n1'));
    $circuit = implode(' → ', array_map(fn($e) => h($e['label'] ?? $e['role'] ?? ''), $etapes_aval));

    $signatures_html = '';
    foreach ($feb['signatures'] as $s) {
        if ($n1_label_validation !== null && ($s['etape_label'] ?? '') === $n1_label_validation) continue;
        $action_lbl = ($s['action'] ?? '') === 'approuve' ? 'Approuvé' : 'Rejeté';
        $signatures_html .= "<tr>
            <td style='padding:6px 9px;border:1px solid #d1d5db'>" . h($s['etape_label'] ?? '') . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db'>" . h($s['nom'] ?? '') . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db;text-align:center'>" . h($action_lbl) . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db'>" . h(!empty($s['date']) ? date('d/m/Y à H:i', strtotime($s['date'])) : '') . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db'>" . h($s['commentaire'] ?? '') . "</td>
        </tr>";
    }

    ob_start();
    ?>
    <!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
      @page { margin: 1.4cm; }
      * { box-sizing: border-box; margin:0; padding:0; }
      body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color:#1a1a2e; }
      h2 { font-size: 13px; color:#06033A; margin: 16px 0 8px; }
      table.data { width:100%; border-collapse:collapse; font-size:10.5px; }
      table.data th { background:#06033A; color:white; padding:6px 9px; text-align:left; font-size:9.5px; text-transform:uppercase; }
    </style>
    </head><body>
    <?= ach_pdf_entete($feb, 'Fiche de validation — pièce archivée') ?>

    <div style="padding:16px 4px">
      <table width="100%" style="margin-bottom:10px">
        <tr>
          <td style="width:50%;vertical-align:top">
            <div style="font-size:9px;color:#888;text-transform:uppercase">Demandeur</div>
            <div style="font-weight:700"><?= h($demandeur['nom'] ?? '—') ?></div>
          </td>
          <td style="width:50%;vertical-align:top">
            <div style="font-size:9px;color:#888;text-transform:uppercase">Acheteur</div>
            <div style="font-weight:700"><?= h($acheteur['nom'] ?? '—') ?></div>
          </td>
        </tr>
        <tr>
          <td style="vertical-align:top;padding-top:8px">
            <div style="font-size:9px;color:#888;text-transform:uppercase">Site / Service</div>
            <div style="font-weight:700"><?= h($site_nom ?: '—') ?> / <?= h($dept_nom ?: '—') ?></div>
          </td>
          <td style="vertical-align:top;padding-top:8px">
            <div style="font-size:9px;color:#888;text-transform:uppercase">Date de confirmation</div>
            <div style="font-weight:700"><?= h($feb['date_confirmation'] ? date('d/m/Y à H:i', strtotime($feb['date_confirmation'])) : '—') ?></div>
          </td>
        </tr>
      </table>
      <div style="background:#f0f7ff;border:1px solid #bfdbfe;padding:9px 13px;margin-bottom:10px">
        <div style="font-size:9px;text-transform:uppercase;color:#1D4ED8;font-weight:700;margin-bottom:3px">Objet</div>
        <div><?= h($feb['objet']) ?></div>
      </div>

      <h2>Circuit de validation appliqué</h2>
      <div style="font-size:11px;margin-bottom:6px"><?= $circuit ?: '—' ?></div>

      <h2>Lignes</h2>
      <table class="data">
        <thead><tr><th>Désignation</th><th>Qté</th><th>Lot</th><th>Montant</th><th>Arbitrage</th></tr></thead>
        <tbody><?= $lignes_html ?></tbody>
      </table>

      <h2>Lots et offres retenues</h2>
      <?= $lots_html ?: '<div style="font-size:11px;color:#888">Aucun lot (FEB entièrement servie sur stock).</div>' ?>

      <table width="100%" style="margin:14px 0">
        <tr><td style="background:#1B75BC;color:white;font-weight:700;text-align:right;padding:8px 12px;font-size:12px">
          MONTANT TOTAL : <?= number_format((float)$feb['montant_total'], 0, ',', ' ') ?> XOF
        </td></tr>
      </table>

      <h2>Signatures</h2>
      <table class="data">
        <thead><tr><th>Étape</th><th>Signataire</th><th>Décision</th><th>Date</th><th>Commentaire</th></tr></thead>
        <tbody><?= $signatures_html ?: '<tr><td colspan="5" style="padding:8px;text-align:center;color:#888">Aucune signature.</td></tr>' ?></tbody>
      </table>

      <div style="margin-top:20px;font-size:9px;color:#888">
        Document généré automatiquement le <?= date('d/m/Y à H:i') ?> — pièce archivée, non modifiable.
      </div>
    </div>
    </body></html>
    <?php
    return ob_get_clean();
}

// ── Génère et enregistre sur disque la fiche de validation d'une FEB
//    (Bloc 1, point 6) — appelée une seule fois par ach_viser() au moment
//    de la confirmation. Retourne le nom de fichier stocké.
function ach_generer_fiche_validation(int $feb_id): string {
    require_once __DIR__ . '/../vendor/autoload.php';
    upload_ensure_dirs();

    $html = ach_html_fiche_validation($feb_id);

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $numero = db_fetch_value("SELECT numero FROM feb WHERE id=?", [$feb_id]) ?: "feb$feb_id";
    $safe   = preg_replace('/[^A-Za-z0-9_-]/', '', $numero);
    $filename = "validation_{$safe}_" . date('Ymd_His') . '.pdf';
    file_put_contents(UPLOAD_VALIDATION_DIR . $filename, $dompdf->output());
    // Pièce opposable, générée une seule fois : copie durable en base (le
    // disque de Render est effacé à chaque déploiement).
    if (upload_archiver('validation', $filename)) upload_purger_disque('validation', $filename);

    db_query("UPDATE feb SET fiche_validation_path=? WHERE id=?", [$filename, $feb_id]);
    return $filename;
}

// ── HTML de la fiche FEB imprimable (Bloc 2) — modèle papier, générée à
//    la demande à tout moment du cycle de vie. Cases de signature vierges
//    tant que la FEB n'est pas confirmée, signatures réelles sinon.
function ach_html_fiche_imprimable(int $feb_id): string {
    $row = db_fetch_one("SELECT * FROM feb WHERE id=?", [$feb_id]);
    if (!$row) throw new AchValidationException('FEB introuvable.');
    $feb = ach_feb_decode($row);

    $demandeur = db_fetch_one("SELECT CONCAT(prenom,' ',nom) AS nom FROM users WHERE id=?", [$feb['demandeur_id']]);
    $site_nom  = $feb['site_id'] ? db_fetch_value("SELECT nom FROM sites WHERE id=?", [$feb['site_id']]) : null;
    $dept_nom  = $feb['departement_id'] ? db_fetch_value("SELECT label FROM departements WHERE id=?", [$feb['departement_id']]) : null;
    $lignes    = db_fetch_all("SELECT * FROM feb_lignes WHERE feb_id=? ORDER BY numero_ligne", [$feb_id]);

    $confirmee = $feb['statut'] === 'confirmee' || in_array($feb['statut'], ['rejetee'], true) && !empty($feb['signatures']);
    $signatures_par_etape = [];
    foreach ($feb['signatures'] as $s) { $signatures_par_etape[$s['etape_label'] ?? ''] = $s; }

    // ── Bloc DEMANDEUR + SUPÉRIEUR HIÉRARCHIQUE (modèle papier, feuille
    //    « FEB DEMANDEUR ») — propre à cette fiche, jamais sur la fiche de
    //    validation archivée qui ne documente que le circuit financier aval
    //    (cf. ach_html_fiche_validation()). Le demandeur n'a pas de visa à
    //    proprement parler : déposer/soumettre la FEB EST son engagement,
    //    daté à la soumission (ou à la création tant qu'elle est en
    //    brouillon). Le N+1 est résolu et figé sur feb.n1_user_id à la
    //    soumission (ach_endosser_n1(), avant même la prise en charge par
    //    les achats) — son endossement est tracé dans feb.historique
    //    ('endosse_n1'), plus dans feb.signatures : il ne fait plus partie
    //    du circuit RAF/DAF/PDG qui se joue plus tard, au lancement.
    $n1_nom = $feb['n1_user_id'] ? db_fetch_value("SELECT CONCAT(prenom,' ',nom) FROM users WHERE id=?", [(int)$feb['n1_user_id']]) : null;
    $n1_signature = null;
    foreach (($feb['historique'] ?: []) as $h) {
        if (($h['action'] ?? '') === 'endosse_n1') { $n1_signature = $h; break; }
    }

    // Case DEMANDEUR : toujours renseignée dès que la FEB existe — déposer/
    // soumettre est l'engagement du demandeur, il n'y a pas de visa distinct
    // à attendre. Date de soumission si elle a eu lieu, sinon de création
    // (brouillon imprimé avant soumission).
    $date_demandeur = $feb['date_soumission'] ?: $feb['date_creation'];
    $bloc_demandeur_n1 = "
        <td style='width:50%;vertical-align:top;padding:8px;border:1px solid #d1d5db'>
            <div style='font-size:9px;text-transform:uppercase;color:#888;margin-bottom:26px'>Demandeur</div>
            <div style='font-size:10px;font-weight:700'>" . h($demandeur['nom'] ?? '—') . "</div>
            <div style='font-size:9px;color:#666'>" . h($date_demandeur ? date('d/m/Y', strtotime($date_demandeur)) : '') . "</div>
        </td>
        <td style='width:50%;vertical-align:top;padding:8px;border:1px solid #d1d5db'>
            <div style='font-size:9px;text-transform:uppercase;color:#888;margin-bottom:26px'>Supérieur hiérarchique</div>"
            . (!$n1_nom
                ? "<div style='font-size:9px;color:#aaa'>Aucun N+1 désigné pour ce service.</div>"
                : ($n1_signature
                    ? "<div style='font-size:10px;font-weight:700'>" . h($n1_signature['nom'] ?? $n1_nom) . "</div><div style='font-size:9px;color:#666'>" . h(!empty($n1_signature['date']) ? date('d/m/Y', strtotime($n1_signature['date'])) : '') . "</div>"
                    : "<div style='font-size:10px;color:#374151'>" . h($n1_nom) . "</div><div style='border-bottom:1px solid #ccc;height:1px;margin-top:10px'></div><div style='font-size:9px;color:#aaa;margin-top:4px'>Signature en attente</div>")) .
        "</td>";

    // ── Blocs repris du modèle papier « FEB FINALE DES ACHATS » (classeur
    //    FEB EMUCI.xlsx). Chacun s'affiche en cases vides tant que la donnée
    //    n'existe pas : c'est un formulaire, pas un rapport — une FEB en
    //    brouillon doit pouvoir s'imprimer et se remplir à la main.
    $urgence_label = ach_urgences()[(int)$feb['urgence']] ?? 'Normale';

    // N° DA / N° BC (SAGE) — portés par les lignes de suivi, donc connus
    // seulement après confirmation (J7). Dédupliqués : une même référence
    // couvre souvent tout un lot.
    $refs = db_fetch_all(
        "SELECT DISTINCT numero_da, date_da, numero_bc, date_bc FROM feb_suivi
          WHERE feb_id=? AND (numero_da IS NOT NULL OR numero_bc IS NOT NULL)",
        [$feb_id]
    );
    $das = array_values(array_unique(array_filter(array_column($refs, 'numero_da'))));
    $bcs = array_values(array_unique(array_filter(array_column($refs, 'numero_bc'))));

    // Propositions fournisseurs — toutes les offres, la retenue marquée
    // d'une croix dans la colonne CHOIX comme sur le papier.
    $offres = db_fetch_all(
        "SELECT o.*, f.raison_sociale FROM feb_offres o
         LEFT JOIN fournisseurs f ON f.id = o.fournisseur_id
         WHERE o.feb_id=? ORDER BY o.lot, o.id",
        [$feb_id]
    );

    // Contrôle budgétaire — une ligne par famille achetée, sur le
    // département de la FEB (clé retenue en J6).
    $budget_rows = [];
    if ($feb['departement_id']) {
        $familles = db_fetch_all(
            "SELECT famille_id, SUM(montant_ttc) AS montant FROM feb_lignes
              WHERE feb_id=? AND arbitrage='achat' AND famille_id IS NOT NULL
              GROUP BY famille_id",
            [$feb_id]
        );
        foreach ($familles as $f) {
            $sit = ach_budget_situation((int)$feb['departement_id'], (int)$f['famille_id'], (int)$feb['exercice'], $feb_id);
            $budget_rows[] = [
                'libelle'    => db_fetch_value("SELECT libelle FROM familles_achat WHERE id=?", [$f['famille_id']]),
                'enveloppe'  => $sit['enveloppe'],
                'engage'     => $sit['total_engage'],
                'a_engager'  => (int)$f['montant'],
                'reste'      => $sit['enveloppe'] !== null ? $sit['enveloppe'] - $sit['total_engage'] - (int)$f['montant'] : null,
            ];
        }
    }
    $fmt = fn($v) => $v === null ? '—' : number_format((float)$v, 0, ',', ' ') . ' XOF';

    $lignes_html = '';
    foreach ($lignes as $l) {
        $lignes_html .= "<tr>
            <td style='padding:6px 9px;border:1px solid #d1d5db'>" . h($l['designation']) . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db;text-align:center'>" . (int)$l['quantite'] . " " . h($l['unite'] ?: '') . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db'>" . h($l['code_analytique'] ?: '—') . "</td>
            <td style='padding:6px 9px;border:1px solid #d1d5db'>" . h($l['type_achat'] ?: '—') . "</td>
        </tr>";
    }

    // Circuit aval seul ici aussi (cf. bloc DEMANDEUR + SUPÉRIEUR
    // HIÉRARCHIQUE ci-dessus, qui porte déjà la case du N+1) — sans quoi
    // il apparaîtrait deux fois sur la même fiche.
    $cases_signature = '';
    $etapes = array_values(array_filter($feb['workflow_snapshot'] ?: [], fn($e) => ($e['role'] ?? '') !== 'n1'));
    if ($etapes) {
        foreach ($etapes as $e) {
            $label = $e['label'] ?? $e['role'] ?? '';
            $s = $signatures_par_etape[$label] ?? null;
            $cases_signature .= "<td style='width:" . round(100 / max(1, count($etapes))) . "%;vertical-align:top;padding:8px;border:1px solid #d1d5db'>
                <div style='font-size:9px;text-transform:uppercase;color:#888;margin-bottom:26px'>" . h($label) . "</div>
                " . ($s
                    ? "<div style='font-size:10px;font-weight:700'>" . h($s['nom'] ?? '') . "</div><div style='font-size:9px;color:#666'>" . h(!empty($s['date']) ? date('d/m/Y', strtotime($s['date'])) : '') . "</div>"
                    : "<div style='border-bottom:1px solid #ccc;height:1px;margin-top:14px'></div><div style='font-size:9px;color:#aaa;margin-top:4px'>Signature en attente</div>") . "
            </td>";
        }
    } else {
        // FEB pas encore lancée en validation : le circuit n'est pas connu —
        // une case vierge générique, pas de fausse précision.
        $cases_signature = "<td style='padding:8px;border:1px solid #d1d5db'>
            <div style='font-size:9px;text-transform:uppercase;color:#888;margin-bottom:26px'>Visa</div>
            <div style='border-bottom:1px solid #ccc;height:1px;margin-top:14px'></div>
        </td>";
    }

    ob_start();
    ?>
    <!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
      @page { margin: 1.4cm; }
      * { box-sizing: border-box; margin:0; padding:0; }
      body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color:#1a1a2e; }
      table.data { width:100%; border-collapse:collapse; font-size:10.5px; margin-bottom:14px; }
      table.data th { background:#06033A; color:white; padding:6px 9px; text-align:left; font-size:9.5px; text-transform:uppercase; }
    </style>
    </head><body>
    <?= ach_pdf_entete($feb, 'Fiche d\'Expression de Besoin') ?>

    <div style="padding:16px 4px">
      <table width="100%" style="margin-bottom:12px">
        <tr>
          <td style="width:50%;vertical-align:top">
            <div style="font-size:9px;color:#888;text-transform:uppercase">Demandeur</div>
            <div style="font-weight:700"><?= h($demandeur['nom'] ?? '—') ?></div>
          </td>
          <td style="width:50%;vertical-align:top">
            <div style="font-size:9px;color:#888;text-transform:uppercase">Site / Service</div>
            <div style="font-weight:700"><?= h($site_nom ?: '—') ?> / <?= h($dept_nom ?: '—') ?></div>
          </td>
        </tr>
        <tr>
          <td style="width:50%;vertical-align:top;padding-top:8px">
            <div style="font-size:9px;color:#888;text-transform:uppercase">Fonction</div>
            <div style="font-weight:700"><?= h($feb['fonction'] ?: '—') ?></div>
          </td>
          <td style="width:50%;vertical-align:top;padding-top:8px">
            <div style="font-size:9px;color:#888;text-transform:uppercase">Urgence</div>
            <div style="font-weight:700"><?= h($urgence_label) ?></div>
          </td>
        </tr>
        <tr>
          <td style="width:50%;vertical-align:top;padding-top:8px">
            <div style="font-size:9px;color:#888;text-transform:uppercase">N° DA (Sage)</div>
            <div style="font-weight:700"><?= $das ? h(implode(', ', $das)) : '<span style="color:#bbb;font-weight:400">…………………………</span>' ?></div>
          </td>
          <td style="width:50%;vertical-align:top;padding-top:8px">
            <div style="font-size:9px;color:#888;text-transform:uppercase">N° BC (Sage)</div>
            <div style="font-weight:700"><?= $bcs ? h(implode(', ', $bcs)) : '<span style="color:#bbb;font-weight:400">…………………………</span>' ?></div>
          </td>
        </tr>
      </table>
      <div style="background:#f0f7ff;border:1px solid #bfdbfe;padding:9px 13px;margin-bottom:12px">
        <div style="font-size:9px;text-transform:uppercase;color:#1D4ED8;font-weight:700;margin-bottom:3px">Objet</div>
        <div><?= h($feb['objet']) ?></div>
      </div>

      <div style="font-size:10px;text-transform:uppercase;color:#888;margin-bottom:6px">Demandeur / Supérieur hiérarchique</div>
      <table width="100%" style="margin-bottom:12px"><tr><?= $bloc_demandeur_n1 ?></tr></table>

      <table class="data">
        <thead><tr><th>Désignation</th><th>Qté</th><th>Code analytique</th><th>Type d'achat</th></tr></thead>
        <tbody><?= $lignes_html ?: '<tr><td colspan="4" style="padding:8px;text-align:center;color:#888">Aucune ligne.</td></tr>' ?></tbody>
      </table>

      <div style="font-size:10px;text-transform:uppercase;color:#888;margin-bottom:6px">Propositions fournisseurs <span style="text-transform:none">(**)</span></div>
      <table class="data">
        <thead><tr>
          <th>Fournisseur</th><th>Délai liv.</th><th>Condition paie.</th>
          <th style="text-align:right">Montant TTC</th><th style="text-align:center">Choix</th><th>Observations</th>
        </tr></thead>
        <tbody>
        <?php if ($offres): foreach ($offres as $o): ?>
          <tr>
            <td style="padding:6px 9px;border:1px solid #d1d5db"><?= h($o['raison_sociale'] ?: '—') ?></td>
            <td style="padding:6px 9px;border:1px solid #d1d5db;text-align:center"><?= $o['delai_annonce'] !== null ? (int)$o['delai_annonce'] . ' j' : '—' ?></td>
            <td style="padding:6px 9px;border:1px solid #d1d5db"><?= h($o['conditions_paiement'] ?: '—') ?></td>
            <td style="padding:6px 9px;border:1px solid #d1d5db;text-align:right"><?= $fmt($o['montant_ttc']) ?></td>
            <td style="padding:6px 9px;border:1px solid #d1d5db;text-align:center;font-weight:700"><?= $o['retenue'] ? '✕' : '' ?></td>
            <td style="padding:6px 9px;border:1px solid #d1d5db"><?= h($o['observation'] ?: '') ?></td>
          </tr>
        <?php endforeach; else: ?>
          <?php for ($i = 0; $i < 3; $i++): ?>
          <tr><?php for ($j = 0; $j < 6; $j++): ?><td style="padding:11px 9px;border:1px solid #d1d5db"></td><?php endfor; ?></tr>
          <?php endfor; ?>
        <?php endif; ?>
        </tbody>
      </table>

      <div style="font-size:10px;text-transform:uppercase;color:#888;margin-bottom:6px">Contrôle budgétaire</div>
      <table class="data">
        <thead><tr>
          <th>Ligne budgétaire</th>
          <th style="text-align:right">Montant budget</th><th style="text-align:right">Montant engagé</th>
          <th style="text-align:right">Montant à engager</th><th style="text-align:right">Reste à engager</th>
        </tr></thead>
        <tbody>
        <?php if ($budget_rows): foreach ($budget_rows as $b): ?>
          <tr>
            <td style="padding:6px 9px;border:1px solid #d1d5db"><?= h($b['libelle'] ?: '—') ?></td>
            <td style="padding:6px 9px;border:1px solid #d1d5db;text-align:right"><?= $b['enveloppe'] === null ? 'Non plafonné' : $fmt($b['enveloppe']) ?></td>
            <td style="padding:6px 9px;border:1px solid #d1d5db;text-align:right"><?= $fmt($b['engage']) ?></td>
            <td style="padding:6px 9px;border:1px solid #d1d5db;text-align:right"><?= $fmt($b['a_engager']) ?></td>
            <td style="padding:6px 9px;border:1px solid #d1d5db;text-align:right;font-weight:700<?= ($b['reste'] !== null && $b['reste'] < 0) ? ';color:#991B1B' : '' ?>"><?= $b['reste'] === null ? '—' : $fmt($b['reste']) ?></td>
          </tr>
        <?php endforeach; else: ?>
          <?php for ($i = 0; $i < 2; $i++): ?>
          <tr><?php for ($j = 0; $j < 5; $j++): ?><td style="padding:11px 9px;border:1px solid #d1d5db"></td><?php endfor; ?></tr>
          <?php endfor; ?>
        <?php endif; ?>
        </tbody>
      </table>

      <div style="font-size:10px;text-transform:uppercase;color:#888;margin-bottom:6px">Circuit de validation (Achats)</div>
      <table width="100%"><tr><?= $cases_signature ?></tr></table>

      <div style="margin-top:20px;font-size:9px;color:#888">
        Document généré le <?= date('d/m/Y à H:i') ?> — modèle papier, régénérable à tout moment.
      </div>
    </div>
    </body></html>
    <?php
    return ob_get_clean();
}

<?php
// ============================================================
//  api/export.php  —  Export Excel via PhpSpreadsheet
//  composer require phpoffice/phpspreadsheet
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';

require_auth();
$type = trim($_GET['type'] ?? '');
$user = current_user();

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    die('PhpSpreadsheet non installé. Exécutez : composer require phpoffice/phpspreadsheet');
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

function apply_header(Spreadsheet $sp, string $range): void {
    $sp->getActiveSheet()->getStyle($range)->applyFromArray([
        'font'      => ['bold'=>true,'color'=>['argb'=>'FFFFFFFF'],'size'=>11],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF0D1F35']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFD5DDE8']]],
    ]);
}
function apply_data(Spreadsheet $sp, string $range): void {
    $sp->getActiveSheet()->getStyle($range)->applyFromArray([
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FFE2E8F0']]],
        'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
    ]);
}
function auto_size($sheet, int $n): void {
    for ($i=1; $i<=$n; $i++) {
        $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    }
}
function stripe($sheet, string $range): void {
    static $toggle=true;
    if ($toggle) $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
    $toggle=!$toggle;
}

$sp    = new Spreadsheet();
$sheet = $sp->getActiveSheet();
$sheet->getDefaultRowDimension()->setRowHeight(18);

// ── ÉQUIPEMENTS ──────────────────────────────────────────────
if ($type==='equipements') {
    require_permission('equipements','can_export');
    $w=[]; $p=[];
    if(!empty($_GET['q'])){$w[]='(e.numero_serie_interne LIKE ? OR e.numero_serie_origine LIKE ? OR e.marque LIKE ?)';$s='%'.$_GET['q'].'%';$p=array_merge($p,[$s,$s,$s]);}
    if(!empty($_GET['nom'])){$w[]='e.nomenclature_id=?';$p[]=(int)$_GET['nom'];}
    if(!empty($_GET['etat'])){$w[]='e.etat=?';$p[]=$_GET['etat'];}
    if(!empty($_GET['site'])){$w[]='e.site_id=?';$p[]=(int)$_GET['site'];}
    $w[]='e.actif=1';
    // Utilisateur = agent de l'Annuaire (equipements.agent_id), à défaut le
    // compte applicatif historique (utilisateur_id).
    $agent_ok = db_colonne_existe('equipements', 'agent_id');
    $col_user = $agent_ok
        ? "COALESCE(NULLIF(TRIM(CONCAT(ag.nom,' ',ag.prenom)),''), NULLIF(TRIM(CONCAT(u.prenom,' ',u.nom)),'')) AS utilisateur"
        : "CONCAT(u.prenom,' ',u.nom) AS utilisateur";
    $join_ag  = $agent_ok ? " LEFT JOIN agents ag ON ag.id=e.agent_id" : "";
    $rows=db_fetch_all("SELECT e.numero_chrono,e.numero_serie_interne,e.numero_serie_origine,COALESCE(n.libelle,'—') AS type,e.marque,e.modele,e.etat,s.nom AS site,$col_user,e.date_acquisition,e.date_mise_en_service,e.date_fin_cycle,e.observations FROM equipements e LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id LEFT JOIN sites s ON s.id=e.site_id LEFT JOIN users u ON u.id=e.utilisateur_id$join_ag WHERE ".implode(' AND ',$w)." ORDER BY e.numero_chrono DESC",$p);
    $sheet->setTitle('Équipements');
    $sheet->fromArray(['#','N° Interne','N° Série Fabricant','Type','Marque','Modèle','État','Site','Utilisateur','Date Acquisition','Mise en service','Fin de cycle','Observations'],null,'A1');
    apply_header($sp,'A1:M1'); $sheet->getRowDimension(1)->setRowHeight(22);
    $row=2; foreach($rows as $r){
        $sheet->fromArray([$r['numero_chrono'],$r['numero_serie_interne'],$r['numero_serie_origine'],$r['type'],$r['marque'],$r['modele'],$r['etat'],$r['site'],$r['utilisateur'],$r['date_acquisition'],$r['date_mise_en_service'],$r['date_fin_cycle'],$r['observations']],null,"A$row");
        if($r['date_fin_cycle']){$j=(new DateTime($r['date_fin_cycle']))->diff(new DateTime());$jn=$j->invert?-$j->days:$j->days;if($jn<0)$sheet->getStyle("L$row")->getFont()->getColor()->setARGB('FFE74C3C');elseif($jn<=30)$sheet->getStyle("L$row")->getFont()->getColor()->setARGB('FFF39C12');}
        stripe($sheet,"A$row:M$row"); $row++;
    }
    if($row>2) apply_data($sp,"A2:M".($row-1));
    auto_size($sheet,13);
    $filename='equipements_'.date('Ymd_His').'.xlsx';
    audit_log($user['id'],'EXPORT','equipements',null,"Export Excel équipements (".count($rows)." lignes)");
}

// ── CONSOMMABLES ─────────────────────────────────────────────
elseif ($type==='consommables') {
    require_permission('consommables','can_export');
    $rows=db_fetch_all("SELECT a.code,a.libelle,a.unite,a.stock_global,a.seuil_alerte,GROUP_CONCAT(DISTINCT s.nom ORDER BY s.nom SEPARATOR ', ') AS sites FROM articles a LEFT JOIN stock_site ss ON ss.article_id=a.id LEFT JOIN sites s ON s.id=ss.site_id GROUP BY a.id ORDER BY a.libelle");
    $sheet->setTitle('Consommables');
    $sheet->fromArray(['Code','Libellé','Unité','Stock Global','Seuil Alerte','Sites'],null,'A1');
    apply_header($sp,'A1:F1');
    $row=2; foreach($rows as $r){
        $sheet->fromArray([$r['code'],$r['libelle'],$r['unite'],$r['stock_global'],$r['seuil_alerte'],$r['sites']],null,"A$row");
        if((float)$r['stock_global']<=(float)$r['seuil_alerte']) $sheet->getStyle("D$row")->getFont()->getColor()->setARGB('FFE74C3C');
        stripe($sheet,"A$row:F$row"); $row++;
    }
    if($row>2) apply_data($sp,"A2:F".($row-1));
    auto_size($sheet,6);
    $filename='consommables_'.date('Ymd_His').'.xlsx';
    audit_log($user['id'],'EXPORT','consommables',null,"Export Excel consommables");
}

// ── LIVRAISONS ───────────────────────────────────────────────
elseif ($type==='livraisons') {
    require_permission('consommables','can_export');
    $rows=db_fetch_all("SELECT lc.date_livraison,a.libelle AS consommable,a.unite,lc.quantite,s.nom AS site,lc.bon_livraison,lc.notes,CONCAT(u.prenom,' ',u.nom) AS agent FROM livraisons_consommables lc JOIN articles a ON a.id=lc.consommable_id JOIN sites s ON s.id=lc.site_id LEFT JOIN users u ON u.id=lc.created_by ORDER BY lc.date_livraison DESC");
    $sheet->setTitle('Livraisons');
    $sheet->fromArray(['Date','Consommable','Unité','Quantité','Site','Bon livraison','Notes','Agent'],null,'A1');
    apply_header($sp,'A1:H1');
    $row=2; foreach($rows as $r){ $sheet->fromArray([$r['date_livraison'],$r['consommable'],$r['unite'],$r['quantite'],$r['site'],$r['bon_livraison'],$r['notes'],$r['agent']],null,"A$row"); stripe($sheet,"A$row:H$row"); $row++; }
    if($row>2) apply_data($sp,"A2:H".($row-1));
    auto_size($sheet,8);
    $filename='livraisons_'.date('Ymd_His').'.xlsx';
    audit_log($user['id'],'EXPORT','consommables',null,"Export Excel livraisons");
}

// ── AUDIT ────────────────────────────────────────────────────
elseif ($type==='audit') {
    require_permission('audit','can_export');
    $rows=db_fetch_all("SELECT al.created_at,CONCAT(u.prenom,' ',u.nom) AS utilisateur,u.email,al.action,al.module,al.description,al.ip_address FROM audit_log al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.created_at DESC LIMIT 5000");
    $sheet->setTitle('Audit');
    $sheet->fromArray(['Date/Heure','Utilisateur','Email','Action','Module','Description','IP'],null,'A1');
    apply_header($sp,'A1:G1');
    $row=2; foreach($rows as $r){ $sheet->fromArray([$r['created_at'],$r['utilisateur'],$r['email'],$r['action'],$r['module'],$r['description'],$r['ip_address']],null,"A$row"); stripe($sheet,"A$row:G$row"); $row++; }
    if($row>2) apply_data($sp,"A2:G".($row-1));
    auto_size($sheet,7);
    $filename='audit_'.date('Ymd_His').'.xlsx';
    audit_log($user['id'],'EXPORT','audit',null,"Export Excel audit log");
}

// ── COÛTS SITES ──────────────────────────────────────────────
elseif ($type==='couts_sites') {
    require_permission('rapports','can_export');
    $annee  = (int)($_GET['annee'] ?? date('Y'));
    $mois   = (int)($_GET['mois']  ?? 0);
    $f_site = (int)($_GET['site']  ?? 0);
    $detail = !empty($_GET['detail']);

    $date_debut = $mois ? sprintf('%04d-%02d-01', $annee, $mois) : "$annee-01-01";
    $date_fin   = $mois ? date('Y-m-t', strtotime($date_debut))  : "$annee-12-31";
    $where_site = $f_site ? "AND lc.site_id=$f_site" : '';

    if (!$detail) {
        // Résumé : coût total par site
        $rows = db_fetch_all(
            "SELECT s.nom AS site, s.type AS type_site,
                    COALESCE(SUM(lc.prix_total),0)    AS cout_total_fcfa,
                    COALESCE(SUM(lc.quantite),0)      AS qte_totale,
                    COUNT(DISTINCT lc.consommable_id) AS nb_articles,
                    MIN(lc.date_livraison)             AS premiere_livraison,
                    MAX(lc.date_livraison)             AS derniere_livraison
             FROM sites s
             LEFT JOIN livraisons_consommables lc ON lc.site_id=s.id
                 AND lc.date_livraison BETWEEN ? AND ? $where_site
                 AND lc.prix_total > 0
             WHERE s.actif=1
             GROUP BY s.id ORDER BY cout_total_fcfa DESC",
            [$date_debut, $date_fin]
        );
        $sheet->setTitle('Coûts par site');
        $sheet->fromArray(['Site','Type','Coût Total (FCFA)','Qté totale','Nb articles','1ère livraison','Dernière livraison'], null, 'A1');
        apply_header($sp,'A1:G1'); $sheet->getRowDimension(1)->setRowHeight(22);
        $row=2; $grand_total=0;
        foreach($rows as $r){
            $grand_total += (float)$r['cout_total_fcfa'];
            $sheet->fromArray([$r['site'],$r['type_site'],
                (float)$r['cout_total_fcfa'],(float)$r['qte_totale'],(int)$r['nb_articles'],
                $r['premiere_livraison'],$r['derniere_livraison']], null, "A$row");
            $sheet->getStyle("C$row")->getNumberFormat()->setFormatCode('#,##0" FCFA"');
            stripe($sheet,"A$row:G$row"); $row++;
        }
        // Ligne totale
        $sheet->fromArray(['TOTAL GÉNÉRAL','',number_format($grand_total,0,',',' ').' FCFA','','','',''],null,"A$row");
        $sheet->getStyle("A$row:G$row")->applyFromArray(['font'=>['bold'=>true],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFE8F0FE']]]);
        if($row>2) apply_data($sp,"A2:G".($row-1));
        auto_size($sheet,7);
        $filename = 'couts_sites_'.$date_debut.'_'.$date_fin.'.xlsx';
    } else {
        // Détail : ligne par article × site
        $rows = db_fetch_all(
            "SELECT s.nom AS site, a.code, a.libelle AS article, a.unite,
                    COALESCE(SUM(lc.quantite),0)         AS qte,
                    COALESCE(AVG(lc.prix_unitaire),0)    AS prix_unit_moy,
                    COALESCE(SUM(lc.prix_total),0)       AS cout_total
             FROM livraisons_consommables lc
             JOIN sites s ON s.id=lc.site_id
             JOIN articles a ON a.id=lc.consommable_id
             WHERE lc.date_livraison BETWEEN ? AND ? $where_site
               AND lc.prix_total > 0
             GROUP BY s.id, a.id ORDER BY s.nom, cout_total DESC",
            [$date_debut, $date_fin]
        );
        $sheet->setTitle('Détail coûts');
        $sheet->fromArray(['Site','Code','Article','Unité','Qté','Prix unit. moy (FCFA)','Coût total (FCFA)'],null,'A1');
        apply_header($sp,'A1:G1'); $sheet->getRowDimension(1)->setRowHeight(22);
        $row=2; $current_site=''; $site_total=0;
        foreach($rows as $r){
            if($current_site && $current_site!==$r['site']){
                $sheet->fromArray(['  → Sous-total '.$current_site,'','','','','',number_format($site_total,0,',',' ').' FCFA'],null,"A$row");
                $sheet->getStyle("A$row:G$row")->getFont()->setBold(true);
                $sheet->getStyle("A$row:G$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8F0FE');
                $row++; $site_total=0;
            }
            $current_site=$r['site'];
            $site_total+=(float)$r['cout_total'];
            $sheet->fromArray([$r['site'],$r['code'],$r['article'],$r['unite'],(float)$r['qte'],(float)$r['prix_unit_moy'],(float)$r['cout_total']],null,"A$row");
            $sheet->getStyle("F$row")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("G$row")->getNumberFormat()->setFormatCode('#,##0');
            stripe($sheet,"A$row:G$row"); $row++;
        }
        if($current_site){
            $sheet->fromArray(['  → Sous-total '.$current_site,'','','','','',number_format($site_total,0,',',' ').' FCFA'],null,"A$row");
            $sheet->getStyle("A$row:G$row")->getFont()->setBold(true);
            $sheet->getStyle("A$row:G$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8F0FE');
        }
        if($row>2) apply_data($sp,"A2:G".($row-1));
        auto_size($sheet,7);
        $filename='detail_couts_sites_'.$date_debut.'_'.$date_fin.'.xlsx';
    }
    audit_log($user['id'],'EXPORT','rapports',null,"Export Excel coûts sites ($date_debut → $date_fin)");
}

// ── BILAN MENSUEL ────────────────────────────────────────────
elseif ($type==='bilan_mensuel') {
    require_permission('rapports','can_export');
    $annee = (int)($_GET['annee'] ?? date('Y'));
    $mois  = (int)($_GET['mois']  ?? date('n'));
    $date_debut = sprintf('%04d-%02d-01', $annee, $mois);
    $date_fin   = date('Y-m-t', strtotime($date_debut));
    $mois_fr = [1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',
                7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre'];
    $mois_label = ($mois_fr[$mois] ?? $mois) . ' ' . $annee;

    $rows = db_fetch_all(
        "SELECT s.nom AS site, a.code, a.libelle AS article, a.unite,
                COALESCE(SUM(lc.quantite),0)         AS qte,
                COALESCE(AVG(lc.prix_unitaire),0)    AS prix_unit_moy,
                COALESCE(SUM(lc.prix_total),0)       AS cout_total
         FROM livraisons_consommables lc
         JOIN sites s ON s.id=lc.site_id
         JOIN articles a ON a.id=lc.consommable_id
         WHERE YEAR(lc.date_livraison)=? AND MONTH(lc.date_livraison)=?
           AND lc.prix_total > 0
         GROUP BY s.id, a.id ORDER BY s.nom, cout_total DESC",
        [$annee, $mois]
    );

    $sp2     = new Spreadsheet();
    $sheet   = $sp2->getActiveSheet();
    $sheet->setTitle('Bilan '.$annee.'-'.str_pad($mois,2,'0',STR_PAD_LEFT));
    $sheet->getDefaultRowDimension()->setRowHeight(18);

    // Titre
    $sheet->mergeCells('A1:G1');
    $sheet->setCellValue('A1', "BILAN MENSUEL CONSOMMABLES — ".strtoupper($mois_label));
    $sheet->getStyle('A1')->applyFromArray(['font'=>['bold'=>true,'size'=>13,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF0D1F35']],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    $sheet->fromArray(['Site','Code','Article','Unité','Qté consommée','Prix unit. moy (FCFA)','Coût total (FCFA)'],null,'A2');
    apply_header($sp2,'A2:G2'); $sheet->getRowDimension(2)->setRowHeight(22);

    $row=3; $current_site=''; $site_total=0; $grand_total=0;
    foreach($rows as $r){
        if($current_site && $current_site!==$r['site']){
            $sheet->fromArray(['  SOUS-TOTAL — '.$current_site,'','','','','',round($site_total,2)],null,"A$row");
            $sheet->getStyle("A$row:G$row")->applyFromArray(['font'=>['bold'=>true],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFD6E4F7']]]);
            $sheet->getStyle("G$row")->getNumberFormat()->setFormatCode('#,##0" FCFA"');
            $row++; $site_total=0;
        }
        $current_site=$r['site']; $site_total+=(float)$r['cout_total']; $grand_total+=(float)$r['cout_total'];
        $sheet->fromArray([$r['site'],$r['code'],$r['article'],$r['unite'],(float)$r['qte'],(float)$r['prix_unit_moy'],(float)$r['cout_total']],null,"A$row");
        $sheet->getStyle("F$row")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("G$row")->getNumberFormat()->setFormatCode('#,##0');
        stripe($sheet,"A$row:G$row"); $row++;
    }
    if($current_site){
        $sheet->fromArray(['  SOUS-TOTAL — '.$current_site,'','','','','',round($site_total,2)],null,"A$row");
        $sheet->getStyle("A$row:G$row")->applyFromArray(['font'=>['bold'=>true],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFD6E4F7']]]);
        $sheet->getStyle("G$row")->getNumberFormat()->setFormatCode('#,##0" FCFA"');
        $row++;
    }
    // Grand total
    $sheet->fromArray(['TOTAL GÉNÉRAL','','','','','',round($grand_total,2)],null,"A$row");
    $sheet->getStyle("A$row:G$row")->applyFromArray(['font'=>['bold'=>true,'size'=>11,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF0D1F35']]]);
    $sheet->getStyle("G$row")->getNumberFormat()->setFormatCode('#,##0" FCFA"');

    if($row>3) apply_data($sp2,"A3:G".($row-1));
    auto_size($sheet,7);

    $sp = $sp2; // swap pour le téléchargement
    $filename='bilan_mensuel_'.sprintf('%04d-%02d',$annee,$mois).'.xlsx';
    audit_log($user['id'],'EXPORT','rapports',null,"Export bilan mensuel $annee-$mois");
}

// ── INTERVENTIONS MAINTENANCE ─────────────────────────────────
elseif ($type==='interventions') {
    require_permission('interventions','can_export');
    $f_site = (int)($_GET['site'] ?? 0);
    $f_mois = trim($_GET['mois'] ?? '');
    $where  = ['1=1']; $params = [];
    // Maintenance_info ne voit que ses propres interventions
    if ($user['role_slug']==='maintenance_info') { $where[]='im.technicien_id=?'; $params[]=$user['id']; }
    if ($f_site) { $where[]='im.site_id=?'; $params[]=$f_site; }
    if ($f_mois) { $where[]="DATE_FORMAT(im.date_intervention,'%Y-%m')=?"; $params[]=$f_mois; }
    $rows = db_fetch_all(
        "SELECT im.date_intervention, s.nom AS site, im.type_action, im.description,
                e.numero_serie_interne AS equipement, im.probleme_signale, im.solution_apportee,
                im.statut_apres, im.duree_minutes, im.pieces_changees,
                CONCAT(u.prenom,' ',u.nom) AS technicien
         FROM interventions_maintenance im
         JOIN sites s ON s.id=im.site_id
         LEFT JOIN equipements e ON e.id=im.equipement_id
         JOIN users u ON u.id=im.technicien_id
         WHERE ".implode(' AND ',$where)." ORDER BY im.date_intervention DESC",
        $params
    );
    $sheet->setTitle('Interventions');
    $sheet->fromArray(['Date','Site','Type d\'action','Description','Équipement','Problème','Solution','Statut','Durée (min)','Pièces changées','Technicien'],null,'A1');
    apply_header($sp,'A1:K1'); $sheet->getRowDimension(1)->setRowHeight(22);
    $row=2; foreach($rows as $r){
        $sheet->fromArray([$r['date_intervention'],$r['site'],$r['type_action'],$r['description'],
            $r['equipement']??'—',$r['probleme_signale']??'',$r['solution_apportee']??'',
            $r['statut_apres'],$r['duree_minutes']??'',$r['pieces_changees']??'',$r['technicien']],null,"A$row");
        stripe($sheet,"A$row:K$row"); $row++;
    }
    if($row>2) apply_data($sp,"A2:K".($row-1));
    auto_size($sheet,11);
    $filename='interventions_'.date('Ymd_His').'.xlsx';
    audit_log($user['id'],'EXPORT','interventions',null,"Export Excel interventions");
}

// ── AFFECTATIONS / MOUVEMENTS ÉQUIPEMENTS ─────────────────────
elseif ($type==='mouvements') {
    require_permission('affectations','can_export');
    $f_site = (int)($_GET['site'] ?? 0);
    $f_type = trim($_GET['type_mouv'] ?? '');
    $where  = ['1=1']; $params = [];
    if ($f_site) { $where[] = '(me.site_source_id=? OR me.site_dest_id=?)'; $params[] = $f_site; $params[] = $f_site; }
    if ($f_type) { $where[] = 'me.type=?'; $params[] = $f_type; }
    $rows = db_fetch_all(
        "SELECT me.created_at, me.type, e.numero_serie_interne, COALESCE(n.libelle,'—') AS type_equip,
                s1.nom AS site_source, s2.nom AS site_dest,
                CONCAT(ud.prenom,' ',ud.nom) AS user_dest,
                CONCAT(u.prenom,' ',u.nom) AS agent,
                me.fichier_bl, me.notes
         FROM mouvements_equipements me
         JOIN equipements e ON e.id=me.equipement_id
         LEFT JOIN nomenclatures n ON n.id=e.nomenclature_id
         LEFT JOIN sites s1 ON s1.id=me.site_source_id
         LEFT JOIN sites s2 ON s2.id=me.site_dest_id
         LEFT JOIN users u  ON u.id=me.created_by
         LEFT JOIN users ud ON ud.id=me.user_dest_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY me.created_at DESC",
        $params
    );
    $type_labels = ['entree'=>'Entrée','sortie'=>'Sortie','transfert'=>'Transfert','reforme'=>'Réforme','maintenance'=>'Maintenance'];
    $sheet->setTitle('Mouvements');
    $sheet->fromArray(['Date','Type','Équipement','Type équipement','Site source','Site destination','Utilisateur assigné','Agent','Bon de livraison','Notes'],null,'A1');
    apply_header($sp,'A1:J1'); $sheet->getRowDimension(1)->setRowHeight(22);
    $row=2; foreach($rows as $r){
        $sheet->fromArray([
            $r['created_at'], $type_labels[$r['type']] ?? $r['type'], $r['numero_serie_interne'], $r['type_equip'],
            $r['site_source'] ?? '—', $r['site_dest'] ?? '—', trim($r['user_dest'] ?? '') ?: '—',
            $r['agent'] ?? '—', $r['fichier_bl'] ? 'Oui' : 'Non', $r['notes'] ?? '',
        ],null,"A$row");
        stripe($sheet,"A$row:J$row"); $row++;
    }
    if($row>2) apply_data($sp,"A2:J".($row-1));
    auto_size($sheet,10);
    $filename='mouvements_'.date('Ymd_His').'.xlsx';
    audit_log($user['id'],'EXPORT','affectations',null,"Export Excel mouvements équipements (".count($rows)." lignes)");
}

else { http_response_code(400); die('Type d\'export non reconnu.'); }

// ── TÉLÉCHARGEMENT ───────────────────────────────────────────
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');
(new Xlsx($sp))->save('php://output');
exit;

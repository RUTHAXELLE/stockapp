<?php
// ============================================================
//  includes/periode.php
//  Granularite temporelle partagee : journalier, hebdomadaire,
//  mensuel, annuel.
//
//  Phase 3, tache 2.2. Le mecanisme vient de pages/pdg_overview.php,
//  ou il pilote les 16 TO_CHAR(...) de la page et les comparaisons a
//  la periode precedente. Le dashboard KPI a besoin exactement des
//  memes quatre granularites : plutot que d'en ecrire une seconde
//  version — c'est ainsi que la formule de consommation avait fini
//  recopiee trois fois — la logique est extraite ici et les deux
//  ecrans s'y branchent.
//
//  Retourne un tableau utilisable tel quel :
//    periode      cle courte (journalier | hebdomadaire | mensuel | annuel)
//    date_fmt     format DATE_FORMAT correspondant (variante MySQL de cette
//                 branche — main utilise les tokens TO_CHAR de PostgreSQL,
//                 incompatibles ; cf. DEPLOY-VPS-MYSQL.md)
//    val          valeur de la periode courante, a comparer au DATE_FORMAT
//    val_prec     idem pour la periode precedente
//    du / au      bornes de dates, pour les requetes par intervalle
//    libelle      libelle lisible de la periode courante
//    libelle_prec libelle de la periode precedente
//    mot          tournure courte ("ce mois", "cette semaine"...)
//
//  %x-%v et non %Y-%u pour la semaine : %x/%v donnent l'annee et la semaine
//  ISO-8601 (lundi premier jour, semaine 1 = premiere semaine a majorite de
//  jours dans la nouvelle annee) — sur une semaine a cheval sur deux annees
//  les variantes non-ISO (%Y-%u) divergent, et la comparaison a la semaine
//  precedente tomberait sur la mauvaise semaine. Meme semantique que
//  IYYY-IW cote PostgreSQL (main) ; PHP date('o-W') cote calcul de $val
//  produit deja l'annee/semaine ISO, donc aucun changement necessaire la.
// ============================================================

function periode_contexte(): array {
    $mc = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin',
           '07'=>'Juil','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
    $ml = ['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin',
           '07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre'];

    $periode = in_array($_GET['periode'] ?? '', ['journalier','hebdomadaire','annuel'], true)
             ? $_GET['periode'] : 'mensuel';

    $mois = trim($_GET['mois'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $mois)) $mois = date('Y-m');
    $jour = trim($_GET['jour'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) || !strtotime($jour)) $jour = date('Y-m-d');

    $annee_max = (int)date('Y');
    $annee_min = (int)(db_fetch_value(
        "SELECT MIN(YEAR(date_point)) FROM op_points_journaliers") ?? $annee_max);
    if ($annee_min > $annee_max) $annee_min = $annee_max;

    if ($periode === 'journalier') {
        $prec = date('Y-m-d', strtotime($jour.' -1 day'));
        $c = ['date_fmt'=>'%Y-%m-%d', 'val'=>$jour, 'val_prec'=>$prec,
              'du'=>$jour, 'au'=>$jour,
              'libelle'=>fmt_date($jour,'d/m/Y'), 'libelle_prec'=>fmt_date($prec,'d/m/Y'),
              'mot'=>"aujourd'hui", 'annee'=>(int)substr($jour,0,4)];
    } elseif ($periode === 'hebdomadaire') {
        $prec     = date('o-W', strtotime($jour.' -7 days'));
        $lundi    = date('Y-m-d', strtotime($jour.' monday this week'));
        $dimanche = date('Y-m-d', strtotime($lundi.' +6 days'));
        $c = ['date_fmt'=>'%x-%v', 'val'=>date('o-W', strtotime($jour)), 'val_prec'=>$prec,
              'du'=>$lundi, 'au'=>$dimanche,
              'libelle'=>'Semaine '.date('W', strtotime($jour)).' — du '
                         .fmt_date($lundi,'d/m').' au '.fmt_date($dimanche,'d/m/Y'),
              'libelle_prec'=>'Semaine '.substr($prec,-2),
              'mot'=>'cette semaine', 'annee'=>(int)date('o', strtotime($jour))];
    } elseif ($periode === 'annuel') {
        $an = (int)($_GET['annee'] ?? $annee_max);
        if ($an < 2000 || $an > 2100) $an = $annee_max;
        $c = ['date_fmt'=>'%Y', 'val'=>(string)$an, 'val_prec'=>(string)($an-1),
              'du'=>$an.'-01-01', 'au'=>$an.'-12-31',
              'libelle'=>'Année '.$an, 'libelle_prec'=>'Année '.($an-1),
              'mot'=>'cette année', 'annee'=>$an];
    } else {
        $prec = date('Y-m', strtotime($mois.'-01 -1 month'));
        $du   = $mois.'-01';
        $c = ['date_fmt'=>'%Y-%m', 'val'=>$mois, 'val_prec'=>$prec,
              'du'=>$du, 'au'=>date('Y-m-t', strtotime($du)),
              'libelle'=>($ml[substr($mois,5,2)] ?? '').' '.substr($mois,0,4),
              'libelle_prec'=>($mc[substr($prec,5,2)] ?? '').' '.substr($prec,0,4),
              'mot'=>'ce mois', 'annee'=>(int)substr($mois,0,4)];
    }

    return $c + ['periode'=>$periode, 'mois'=>$mois, 'jour'=>$jour,
                 'annee_min'=>$annee_min, 'annee_max'=>$annee_max];
}

/**
 * Selecteur de periode — le meme balisage pour tous les ecrans qui
 * utilisent periode_contexte(), afin que l'utilisateur retrouve le
 * meme controle d'une page a l'autre.
 * $extra : champs caches a reporter (site, filtres propres a la page).
 */
function periode_selecteur(array $p, array $extra = [], string $classe = 'month-inp'): string {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $out = '';
    foreach ($extra as $k => $v) {
        $out .= '<input type="hidden" name="'.$h($k).'" value="'.$h($v).'">';
    }
    $opts = ['journalier'=>'Journalier','hebdomadaire'=>'Hebdomadaire',
             'mensuel'=>'Mensuel','annuel'=>'Annuel'];
    $out .= '<select name="periode" class="'.$h($classe).'" onchange="this.form.submit()"'
          . ' title="Type de période" style="min-width:118px">';
    foreach ($opts as $k => $lbl) {
        $out .= '<option value="'.$k.'"'.($p['periode']===$k?' selected':'').'>'.$lbl.'</option>';
    }
    $out .= '</select>';

    if ($p['periode'] === 'annuel') {
        $out .= '<select name="annee" class="'.$h($classe).'" onchange="this.form.submit()" title="Choisir l\'année">';
        for ($y = $p['annee_max']; $y >= $p['annee_min']; $y--) {
            $out .= '<option value="'.$y.'"'.($p['annee']===$y?' selected':'').'>'.$y.'</option>';
        }
        $out .= '</select>';
    } elseif ($p['periode'] === 'mensuel') {
        $out .= '<input type="month" name="mois" value="'.$h($p['mois']).'" class="'.$h($classe)
              . '" onchange="this.form.submit()" aria-label="Choisir le mois">';
    } else {
        $lbl = $p['periode']==='hebdomadaire' ? 'Choisir une date dans la semaine' : 'Choisir le jour';
        $out .= '<input type="date" name="jour" value="'.$h($p['jour']).'" class="'.$h($classe)
              . '" onchange="this.form.submit()" aria-label="'.$h($lbl).'">';
    }
    return $out;
}

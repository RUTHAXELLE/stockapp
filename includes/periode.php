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

/** Date sûre : un quantieme absent du mois vise est ramene a son dernier
 *  jour. Sans ce garde-fou, le 31 mars compare au « 31 fevrier » que
 *  strtotime deplace au 2 ou 3 mars, et le 29 fevrier bissextile glisse
 *  au 1er mars de l'annee precedente. */
function periode_date_rang(int $an, int $mois, int $jour): string {
    $fin = (int) date('t', mktime(0, 0, 0, $mois, 1, $an));
    return sprintf('%04d-%02d-%02d', $an, $mois, min($jour, $fin));
}

/** Nombre de jours calendaires de $du a $au inclus, 0 si l'intervalle est vide. */
function periode_nb_jours(string $du, string $au): int {
    if ($au < $du) return 0;
    return (int) round((strtotime($au) - strtotime($du)) / 86400) + 1;
}

/**
 * Periode de comparaison (B), a cote de la periode analysee (A) que porte
 * periode_contexte(). Trois modes, parametre `cmp` :
 *
 *   precedente  la periode juste avant A — le defaut, sans aucun clic ;
 *   an_prec     la meme periode un an plus tot (sans objet en annuel,
 *               ou elle se confond avec la precedente) ;
 *   choisie     une periode libre de meme granularite (jour_b, mois_b,
 *               annee_b ; en hebdomadaire jour_b porte une date de la
 *               semaine, comme `jour` pour A).
 *
 * Regle de duree, arbitree avec le metier :
 *   - en mode `precedente`, si A est en cours, B est arretee au meme rang
 *     (1er → 24 aout contre 1er → 24 septembre) : c'est la comparaison
 *     automatique, elle ne doit pas annoncer une baisse qui ne tient
 *     qu'au temps ecoule ;
 *   - des que l'utilisateur choisit lui-meme B (`an_prec`, `choisie`),
 *     les deux periodes sont comparees entieres. L'ecran le signale
 *     quand A est en cours, et la moyenne par jour reste comparable.
 *
 * Distinct de periode_contexte() pour ne rien changer a
 * pages/pdg_overview.php, qui compare toujours a la periode precedente.
 */
function periode_comparaison(array $P): array {
    $mc = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Juin',
           '07'=>'Juil','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
    $ml = ['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin',
           '07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre'];

    $per  = $P['periode'];
    $mode = in_array($_GET['cmp'] ?? '', ['precedente','an_prec','choisie'], true)
          ? $_GET['cmp'] : 'precedente';
    if ($mode === 'an_prec' && $per === 'annuel') $mode = 'precedente';

    $du_a = $P['du'];
    $au_a = $P['au'];
    $auj  = date('Y-m-d');

    $jour_b = trim($_GET['jour_b'] ?? '');
    $jour_b_ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour_b) && strtotime($jour_b);

    if ($per === 'journalier') {
        if ($mode === 'an_prec') {
            $b = periode_date_rang((int)substr($du_a,0,4) - 1, (int)substr($du_a,5,2), (int)substr($du_a,8,2));
        } elseif ($mode === 'choisie' && $jour_b_ok) {
            $b = $jour_b;
        } else {
            $b = date('Y-m-d', strtotime($du_a . ' -1 day'));
        }
        $du_b = $au_b = $b;
        $lib = $lib_long = fmt_date($b, 'd/m/Y');
    } elseif ($per === 'hebdomadaire') {
        if ($mode === 'an_prec') {
            $sem = (int) date('W', strtotime($du_a));
            $d = new DateTime();
            $d->setISODate((int) date('o', strtotime($du_a)) - 1, $sem);
            // Une annee ISO sans semaine 53 : setISODate deborde sur la
            // semaine 1 suivante. On retombe alors sur la semaine 52.
            if ((int) $d->format('W') !== $sem) $d->setISODate((int) date('o', strtotime($du_a)) - 1, 52);
            $du_b = $d->format('Y-m-d');
        } elseif ($mode === 'choisie' && $jour_b_ok) {
            $du_b = date('Y-m-d', strtotime($jour_b . ' monday this week'));
        } else {
            $du_b = date('Y-m-d', strtotime($du_a . ' -7 days'));
        }
        $au_b = date('Y-m-d', strtotime($du_b . ' +6 days'));
        $lib      = 'S' . date('W', strtotime($du_b)) . ' ' . date('o', strtotime($du_b));
        $lib_long = 'Semaine ' . date('W', strtotime($du_b)) . ' — du '
                  . fmt_date($du_b, 'd/m') . ' au ' . fmt_date($au_b, 'd/m/Y');
    } elseif ($per === 'annuel') {
        $an_a = (int) $P['annee'];
        $an_b = (int) ($_GET['annee_b'] ?? 0);
        if ($mode !== 'choisie' || $an_b < 2000 || $an_b > 2100) $an_b = $an_a - 1;
        $du_b = $an_b . '-01-01';
        $au_b = $an_b . '-12-31';
        $lib = $lib_long = 'Année ' . $an_b;
    } else {
        $mois_b = trim($_GET['mois_b'] ?? '');
        if ($mode === 'an_prec') {
            $mois_b = ((int)substr($P['mois'],0,4) - 1) . substr($P['mois'],4);
        } elseif ($mode !== 'choisie' || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois_b)) {
            $mois_b = date('Y-m', strtotime($P['mois'] . '-01 -1 month'));
        }
        $du_b = $mois_b . '-01';
        $au_b = date('Y-m-t', strtotime($du_b));
        $lib      = ($mc[substr($mois_b,5,2)] ?? '') . ' ' . substr($mois_b,0,4);
        $lib_long = ($ml[substr($mois_b,5,2)] ?? '') . ' ' . substr($mois_b,0,4);
    }

    $au_b_complet = $au_b;
    $a_en_cours   = $per !== 'journalier' && $auj >= $du_a && $auj <= $au_a;
    $a_date       = $mode === 'precedente' && $a_en_cours;

    if ($a_date) {
        if ($per === 'hebdomadaire') {
            $au_b = date('Y-m-d', strtotime($du_b . ' +' . (periode_nb_jours($du_a, $auj) - 1) . ' days'));
        } elseif ($per === 'mensuel') {
            $au_b = periode_date_rang((int)substr($du_b,0,4), (int)substr($du_b,5,2), (int)date('j'));
        } else {
            $au_b = periode_date_rang((int)substr($du_b,0,4), (int)date('n'), (int)date('j'));
        }
    }

    $intervalle_b = $du_b === $au_b ? fmt_date($du_b, 'd/m')
                  : fmt_date($du_b, 'd/m') . ' – ' . fmt_date($au_b, 'd/m');

    return [
        'mode'         => $mode,
        'du_a'         => $du_a,
        'au_a'         => $au_a,
        'du_b'         => $du_b,
        'au_b'         => $au_b,            // borne effective, a date si a_date
        'au_b_complet' => $au_b_complet,
        'libelle_b'    => $lib,
        'libelle_b_long' => $lib_long,
        'a_date'       => $a_date,
        'a_en_cours'   => $a_en_cours,
        'intervalle_b' => $intervalle_b,
        // Jours reellement ecoules de chaque periode : une periode future
        // ou en cours ne compte que ce qui est passe.
        'jours_a'      => periode_nb_jours($du_a, min($au_a, $auj)),
        'jours_b'      => periode_nb_jours($du_b, min($au_b, $auj)),
    ];
}

/**
 * Selecteur de periode avec comparaison — type, periode analysee (A),
 * mode de comparaison, et periode B quand elle est choisie librement.
 *
 * En hebdomadaire, une liste de semaines (« S38 · 14/09 → 20/09 ») plutot
 * qu'un champ date : le choix porte sur une semaine, pas sur un jour, et
 * le champ natif `type=week` n'existe ni sous Firefox ni sous Safari.
 * La valeur transmise reste le lundi de la semaine, dans `jour` (resp.
 * `jour_b`), ce que periode_contexte() sait deja lire.
 */
function periode_selecteur_comparaison(array $P, array $C, string $classe = 'month-inp'): string {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $sub = ' onchange="this.form.submit()"';

    $semaines = function (string $nom, string $choisi) use ($P, $h, $classe, $sub): string {
        $fin = max(date('Y-m-d', strtotime('monday this week')), $choisi);
        $deb = date('Y-m-d', strtotime($P['annee_min'] . '-01-01 monday this week'));
        $o = '<select name="' . $h($nom) . '" class="' . $h($classe) . '"' . $sub
           . ' aria-label="Choisir la semaine">';
        $groupe = null;
        for ($l = $fin; $l >= $deb; $l = date('Y-m-d', strtotime($l . ' -7 days'))) {
            $an = date('o', strtotime($l));
            if ($an !== $groupe) {
                if ($groupe !== null) $o .= '</optgroup>';
                $o .= '<optgroup label="' . $h($an) . '">';
                $groupe = $an;
            }
            $o .= '<option value="' . $l . '"' . ($l === $choisi ? ' selected' : '') . '>S'
                . date('W', strtotime($l)) . ' · ' . date('d/m', strtotime($l)) . ' → '
                . date('d/m', strtotime($l . ' +6 days')) . '</option>';
        }
        return $o . ($groupe !== null ? '</optgroup>' : '') . '</select>';
    };
    $annees = function (string $nom, int $choisi, string $aria) use ($P, $h, $classe, $sub): string {
        $o = '<select name="' . $h($nom) . '" class="' . $h($classe) . '"' . $sub
           . ' aria-label="' . $h($aria) . '">';
        for ($y = $P['annee_max']; $y >= min($P['annee_min'], $choisi); $y--) {
            $o .= '<option value="' . $y . '"' . ($y === $choisi ? ' selected' : '') . '>' . $y . '</option>';
        }
        return $o . '</select>';
    };

    $out = '<select name="periode" class="' . $h($classe) . '"' . $sub
         . ' title="Type de période" style="min-width:118px">';
    foreach (['journalier'=>'Journalier','hebdomadaire'=>'Hebdomadaire',
              'mensuel'=>'Mensuel','annuel'=>'Annuel'] as $k => $lbl) {
        $out .= '<option value="' . $k . '"' . ($P['periode'] === $k ? ' selected' : '') . '>' . $lbl . '</option>';
    }
    $out .= '</select>';

    // Periode analysee (A)
    switch ($P['periode']) {
        case 'annuel':
            $out .= $annees('annee', (int)$P['annee'], "Choisir l'année analysée");
            break;
        case 'mensuel':
            $out .= '<input type="month" name="mois" value="' . $h($P['mois']) . '" class="' . $h($classe)
                  . '"' . $sub . ' aria-label="Choisir le mois analysé">';
            break;
        case 'hebdomadaire':
            $out .= $semaines('jour', $P['du']);
            break;
        default:
            $out .= '<input type="date" name="jour" value="' . $h($P['jour']) . '" class="' . $h($classe)
                  . '"' . $sub . ' aria-label="Choisir le jour analysé">';
    }

    // Mode de comparaison
    $modes = ['precedente' => 'vs période précédente'];
    if ($P['periode'] !== 'annuel') $modes['an_prec'] = "vs même période l'an dernier";
    $modes['choisie'] = 'vs période choisie…';
    // Mode et periode B dans un meme groupe : sur un ecran etroit, la barre
    // passe a la ligne sans separer « vs periode choisie » de sa periode.
    $out .= '<span style="display:inline-flex;gap:8px;flex-wrap:wrap;align-items:center">';
    $out .= '<select name="cmp" class="' . $h($classe) . '"' . $sub . ' title="Comparer à">';
    foreach ($modes as $k => $lbl) {
        $out .= '<option value="' . $k . '"' . ($C['mode'] === $k ? ' selected' : '') . '>' . $h($lbl) . '</option>';
    }
    $out .= '</select>';

    // Periode B, seulement quand elle est libre
    if ($C['mode'] === 'choisie') {
        switch ($P['periode']) {
            case 'annuel':
                $out .= $annees('annee_b', (int)substr($C['du_b'], 0, 4), "Choisir l'année de comparaison");
                break;
            case 'mensuel':
                $out .= '<input type="month" name="mois_b" value="' . $h(substr($C['du_b'], 0, 7))
                      . '" class="' . $h($classe) . '"' . $sub . ' aria-label="Choisir le mois de comparaison">';
                break;
            case 'hebdomadaire':
                $out .= $semaines('jour_b', $C['du_b']);
                break;
            default:
                $out .= '<input type="date" name="jour_b" value="' . $h($C['du_b']) . '" class="'
                      . $h($classe) . '"' . $sub . ' aria-label="Choisir le jour de comparaison">';
        }
    }
    return $out . '</span>';
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

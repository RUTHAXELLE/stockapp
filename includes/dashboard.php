<?php
// ============================================================
//  includes/dashboard.php  —  Registre des blocs de tableau de bord
// ============================================================
//
//  pages/dashboard.php contenait 72 requêtes réparties en quatre branches
//  par nom de rôle, qui recalculaient les mêmes indicateurs avec des WHERE
//  différents. Aucune des huit conditions d'affichage ne regardait les
//  permissions ; la plupart étaient négatives (« sauf coordinateur »), donc
//  un rôle nouvellement créé voyait tout.
//
//  Trois notions séparées remplacent ça :
//
//   1. LA PORTÉE — un seul objet, déduit du rôle, appliqué à toutes les
//      requêtes. Le coordinateur ne voit que son site ; la maintenance
//      informatique ne voit que sa catégorie d'équipements.
//
//   2. LE REGISTRE — chaque bloc se déclare : son titre, son module de
//      permission, s'il respecte la portée, comment il lit ses données et
//      comment il s'affiche. Ajouter un bloc, c'est ajouter une entrée.
//
//   3. LES PROFILS — un profil métier est une liste ordonnée d'identifiants
//      de blocs. C'est le seul endroit où des noms de rôle subsistent, et
//      c'est normal : « ce que ce métier regarde en premier » n'est pas
//      déductible d'une permission.
//
//  Le rendu réutilise le vocabulaire de templates/dash_style.php et se fait
//  animer par templates/dash_anim.php sans rien déclarer : le moteur
//  découvre les valeurs en parcourant le DOM.
// ------------------------------------------------------------

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helpers.php';

// ============================================================
//  MISE EN FORME
// ============================================================

// Nombre entier, avec une garde pour ne pas afficher « 0 » là où il y a
// quelque chose de non nul mais inférieur à l'unité.
if (!function_exists('ent')) {
    function ent(?float $v, bool $signe = false): string {
        if ($v === null) return '—';
        $r = (int)round($v);
        if ($r === 0 && $v >  0.0001) return '&lt; 1';
        if ($r === 0 && $v < -0.0001) return '&gt; -1';
        return ($signe && $r > 0 ? '+' : '') . number_format($r, 0, ',', ' ');
    }
}

// Répartition en entiers dont la somme fait exactement 100 (plus grand
// reste). Arrondir chaque part séparément donnait des totaux à 101 %.
if (!function_exists('pct_entiers')) {
    function pct_entiers(array $valeurs, float $total): array {
        $n = count($valeurs);
        if ($n === 0) return [];
        if ($total <= 0) return array_fill(0, $n, 0);
        $bruts = array_map(fn($v) => $v / $total * 100, $valeurs);
        $bas   = array_map(fn($p) => (int)floor($p), $bruts);
        $reste = 100 - array_sum($bas);
        $ordre = range(0, $n - 1);
        usort($ordre, fn($a, $b) => ($bruts[$b] - $bas[$b]) <=> ($bruts[$a] - $bas[$a]));
        for ($i = 0; $i < $reste && $i < $n; $i++) $bas[$ordre[$i]]++;
        return $bas;
    }
}

// ============================================================
//  PORTÉE
// ============================================================

/**
 * Périmètre de données de l'utilisateur courant.
 *
 * Un seul endroit décide « de quoi cette personne parle », et toutes les
 * requêtes du registre s'y conforment. C'est ce qui évite de réécrire
 * quatre variantes de la même requête.
 *
 * @return array{site_id:int, categorie:string, libelle:string, site_nom:string}
 */
function dash_portee(?array $user = null): array {
    $user = $user ?? current_user();
    $role = $user['role_slug'] ?? '';

    $site_id   = 0;
    $categorie = '';
    $verrouille = false;

    if ($role === 'coordinateur_site' && !empty($user['site_id'])) {
        // Site imposé par le rôle : le paramètre d'URL est ignoré, sinon un
        // coordinateur consulterait les chiffres d'un autre site en
        // modifiant l'adresse à la main.
        $site_id    = (int)$user['site_id'];
        $verrouille = true;
    } elseif (isset($_GET['site_id']) && (int)$_GET['site_id'] > 0) {
        // Site choisi dans la barre de filtres. Vérifié en base : un
        // identifiant inventé ou désactivé ramène à « tous les sites »
        // plutôt que de produire des blocs vides sans explication.
        $demande = (int)$_GET['site_id'];
        if (db_fetch_value("SELECT id FROM sites WHERE id=? AND actif=1", [$demande])) {
            $site_id = $demande;
        }
    }

    if ($role === 'maintenance_info') {
        $categorie = 'informatique';
    }

    $site_nom = '';
    if ($site_id) {
        $site_nom = (string)db_fetch_value("SELECT nom FROM sites WHERE id=?", [$site_id]);
    }

    $libelle = $site_nom !== '' ? $site_nom
             : ($categorie !== '' ? 'Parc informatique' : 'Tous les sites');

    return [
        'site_id'    => $site_id,
        'categorie'  => $categorie,
        'libelle'    => $libelle,
        'site_nom'   => $site_nom,
        'verrouille' => $verrouille,
    ];
}

/** Sites proposés au filtre. Vide si la portée est imposée par le rôle. */
function dash_sites_filtrables(array $portee): array {
    if ($portee['verrouille']) return [];
    return db_fetch_all("SELECT id, nom FROM sites WHERE actif=1 ORDER BY nom");
}

/**
 * Fragment SQL et paramètres pour restreindre une requête à la portée.
 *
 * La colonne est donnée en entier, pas sous forme de préfixe. Une première
 * version prenait un alias et ajoutait « site_id » derrière : elle produisait
 * « s.site_id » pour les requêtes qui partent de la table sites, dont la
 * colonne s'appelle « id ». Les blocs concernés échouaient dès qu'un site
 * était choisi, et seulement dans ce cas — sans filtre, la clause est vide.
 *
 * @param string $colonne  Colonne portant le site (« pj.site_id », « s.id »).
 * @return array{0:string, 1:array}  Clause à concaténer, paramètres.
 */
function dash_filtre_site(array $portee, string $colonne = 'site_id'): array {
    if (!$portee['site_id']) return ['', []];
    return [" AND $colonne = ?", [$portee['site_id']]];
}

function dash_filtre_categorie(array $portee, string $colonne = 'categorie'): array {
    if ($portee['categorie'] === '') return ['', []];
    return [" AND $colonne = ?", [$portee['categorie']]];
}

// ============================================================
//  PROFILS
// ============================================================

/**
 * Profil métier de l'utilisateur : détermine QUELS blocs et DANS QUEL ORDRE.
 *
 * Les rôles absents de cette table reçoivent le profil « general », qui
 * porte la vue d'ensemble. Aucun rôle ne peut donc se retrouver devant une
 * page vide, y compris un rôle créé après ce code.
 */
function dash_profil(?array $user = null): string {
    $user = $user ?? current_user();
    return match ($user['role_slug'] ?? '') {
        'coordinateur_site'          => 'coordinateur',
        'gestionnaire_stock_bobines' => 'gsb',
        'superviseur_operation'      => 'superviseur_op',
        'gestionnaire_operation'     => 'gestionnaire_op',
        'maintenance_info',
        'superviseur_it',
        'support_it'                 => 'informatique',
        default                      => 'general',
    };
}

/**
 * Blocs de chaque profil, dans l'ordre d'affichage.
 *
 * L'ordre porte du sens : ce qui demande une action vient avant ce qui
 * informe. Le coordinateur voit d'abord ses réceptions à traiter, le GSB
 * d'abord ses commandes à servir.
 */
function dash_profils(): array {
    return [
        // Terrain : actions en premier, lecture ensuite.
        'coordinateur' => [
            'synthese_kpi',
            'points_recents', 'corrections_attente',
            'evolution_engins', 'stock_conso_site',
            'receptions_site',
            'bobines_sites', 'equipements',
            'rivets',
        ],

        // Stock bobines : actions, suivi, alertes.
        'gsb' => [
            'synthese_kpi',
            'commandes_bobines', 'corrections_attente',
            'validations_matin', 'bobines_sites',
            'rivets', 'stock_bas',
        ],

        // Supervision : actions → graphes → suivi mensuel.
        'superviseur_op' => [
            'synthese_kpi',
            'points_attente', 'corrections_attente',
            'evolution_engins', 'repartition_parc',
            'perf_sites',
            'validations_matin', 'points_rejetes',
            'bobines_sites', 'performance_mois',
            'receptions_site', 'activites',
        ],

        // Gestion opérationnelle : peu de droits.
        'gestionnaire_op' => [
            'synthese_kpi',
            'raccourcis', 'points_recents',
            'evolution_engins', 'commandes_bobines',
        ],

        // Informatique : parc, consommation, fin de cycle.
        'informatique' => [
            'synthese_kpi',
            'repartition_parc', 'equipements',
            'conso_sites', 'fin_cycle',
            'activites',
        ],

        // Vue d'ensemble.
        'general' => [
            'synthese_kpi',
            'evolution_engins', 'repartition_parc',
            'equipements', 'conso_sites',
            'perf_sites',
            'bobines_sites', 'sites',
            'rivets', 'stock_bas',
            'fin_cycle', 'activites',
        ],
    ];
}

// ============================================================
//  VISIBILITÉ
// ============================================================

/**
 * Un bloc s'affiche-t-il pour cet utilisateur ?
 *
 * La liste du profil dit ce que le métier regarde ; les permissions
 * restreignent.
 *
 * Le repli ci-dessous n'est PAS du code mort, contrairement à ce qui était
 * écrit ici. Vérifié en base le 2026-07-30, après application des deux
 * migrations : cinq rôles n'ont toujours aucune ligne dans permissions —
 * controleur_production, gestionnaire, gestionnaire_stock_bobines, lecteur
 * et maintenance_info. La migration « 27 modules » ajoute les neuf nouveaux
 * modules aux rôles qui avaient déjà des lignes ; elle n'en crée pas pour
 * ceux qui partaient de zéro.
 *
 * Or trois de ces cinq rôles sont routés par dash_profils() : gsb,
 * informatique et le profil général. Retirer ce repli leur afficherait une
 * page vide. Il tombe de lui-même dès que leurs permissions sont
 * renseignées, sans qu'il y ait à toucher à ce code.
 */
function dash_bloc_visible(array $bloc, ?array $user = null): bool {
    $user = $user ?? current_user();
    if (!$user) return false;

    $module = $bloc['module'] ?? null;
    if ($module === null) return true;              // bloc sans module dédié

    if (!dash_role_a_des_permissions($user)) return true;

    return can($module, $bloc['droit'] ?? 'can_read');
}

/** Le rôle a-t-il au moins une permission déclarée ? Résultat mémorisé. */
function dash_role_a_des_permissions(?array $user = null): bool {
    static $cache = [];
    $user = $user ?? current_user();
    $rid  = (int)($user['role_id'] ?? 0);
    if ($rid === 0) return false;
    if (!array_key_exists($rid, $cache)) {
        // admin et superadmin passent par can() sans consulter la table.
        if (in_array($user['role_slug'] ?? '', ['admin', 'superadmin'], true)) {
            $cache[$rid] = true;
        } else {
            $cache[$rid] = (int)db_fetch_value(
                "SELECT COUNT(*) FROM permissions WHERE role_id=? AND can_read=1", [$rid]
            ) > 0;
        }
    }
    return $cache[$rid];
}

/**
 * Blocs à afficher pour l'utilisateur courant, dans l'ordre du profil.
 *
 * Un identifiant de profil qui ne correspond à aucune entrée du registre
 * est ignoré silencieusement côté page mais signalé dans les journaux :
 * une faute de frappe dans une liste de profil ne doit pas casser la page,
 * ni disparaître sans laisser de trace.
 */
function dash_blocs_visibles(?array $user = null): array {
    $user     = $user ?? current_user();
    $registre = dash_registre();
    $profils  = dash_profils();
    $profil   = dash_profil($user);
    $ids      = $profils[$profil] ?? $profils['general'];

    $sortie = [];
    foreach ($ids as $id) {
        if (!isset($registre[$id])) {
            error_log("dashboard : bloc « $id » déclaré dans le profil « $profil » mais absent du registre");
            continue;
        }
        $bloc = $registre[$id] + ['id' => $id];
        if (dash_bloc_visible($bloc, $user)) $sortie[] = $bloc;
    }
    return $sortie;
}

// ============================================================
//  AIDES AU RENDU
// ============================================================

/** Ouvre une carte : titre, sous-titre, lien d'action optionnel. */
function dash_carte_debut(array $bloc): void {
    $lien = $bloc['lien'] ?? null;
    // Un bloc peut porter un second lien (« lien2 »), soumis au même contrôle
    // de droit que le premier : deux vues d'une même donnée, par exemple le
    // rapport mensuel et le rapport annuel.
    $lien2 = $bloc['lien2'] ?? null;

    if (dash_v2()) {
        echo '<div class="dv2-c">';
        echo   '<div class="dv2-c-hd"><div style="min-width:0">';
        echo     '<div class="dv2-c-t">' . h($bloc['titre']) . '</div>';
        if (!empty($bloc['soustitre'])) {
            echo '<div class="dv2-c-s">' . h($bloc['soustitre']) . '</div>';
        }
        echo   '</div>';
        // Le « ••• » de la maquette mène à la page du bloc. Il n'apparaît que
        // si l'utilisateur a le droit d'y aller : un raccourci vers un 403
        // vaut moins que pas de raccourci.
        if ($lien) {
            $lm = $bloc['lien_module'] ?? ($bloc['module'] ?? null);
            $ld = $bloc['lien_droit']  ?? 'can_read';
            if ($lm === null || can($lm, $ld)) {
                if ($lien2) {
                    echo '<a class="dv2-more" href="' . APP_URL . h($lien2[0]) . '"'
                       . ' title="' . h($lien2[1]) . '" aria-label="' . h($lien2[1]) . '"'
                       . ' style="width:auto;padding:0 10px;font-size:12px;font-weight:700">'
                       . h($lien2[1]) . '</a>';
                }
                echo '<a class="dv2-more" href="' . APP_URL . h($lien[0]) . '"'
                   . ' title="' . h($lien[1]) . '" aria-label="' . h($lien[1]) . '">•••</a>';
            }
        }
        echo   '</div><div class="dv2-c-body">';
        return;
    }

    echo '<div class="card">';
    echo '<div class="dash-tete">';
    echo '<div><div class="card-ttl">' . h($bloc['titre']) . '</div>';
    if (!empty($bloc['soustitre'])) {
        echo '<div class="card-sub" style="margin-bottom:0">' . h($bloc['soustitre']) . '</div>';
    }
    echo '</div>';
    if ($lien) {
        // Le lien n'est affiché que si l'utilisateur a le droit requis sur
        // le module cible. lien_module et lien_droit permettent de cibler
        // un module ou un niveau différent de celui qui conditionne le bloc.
        $lm = $bloc['lien_module'] ?? ($bloc['module'] ?? null);
        $ld = $bloc['lien_droit']  ?? 'can_read';
        if ($lm === null || can($lm, $ld)) {
            echo '<div style="display:flex;gap:12px;align-items:center">';
            if ($lien2) {
                echo '<a class="dash-lien" href="' . APP_URL . h($lien2[0]) . '">' . h($lien2[1]) . ' →</a>';
            }
            echo '<a class="dash-lien" href="' . APP_URL . h($lien[0]) . '">' . h($lien[1]) . ' →</a>';
            echo '</div>';
        }
    }
    // dash-corps porte le défilement horizontal. La carte est en
    // overflow:hidden (feuille globale du site) : un tableau plus large
    // qu'elle était donc coupé sans recours, et sur téléphone deux colonnes
    // sur quatre disparaissaient purement et simplement.
    echo '</div><div class="dash-corps">';
}

function dash_carte_fin(): void {
    echo '</div></div>';
}

/**
 * État vide. Il porte toujours une phrase qui dit ce que l'absence
 * signifie : « rien à valider » et « pas de données » ne veulent pas dire
 * la même chose pour qui consulte.
 */
function dash_vide(string $message, bool $bonne_nouvelle = false): void {
    // En v2 l'état vide porte une icône et deux niveaux de texte ; ailleurs
    // il garde sa forme d'origine, pour ne rien changer à la vue PDG.
    if (dash_v2()) {
        echo '<div class="dv2-empty' . ($bonne_nouvelle ? ' ok' : '') . '">'
           . '<i class="ph-duotone ' . ($bonne_nouvelle ? 'ph-check-circle' : 'ph-tray') . '"></i>'
           . '<div class="dv2-empty-t">' . h($message) . '</div>'
           . '</div>';
        return;
    }
    $couleur = $bonne_nouvelle ? '#166534' : '#5a6678';
    echo '<div style="text-align:center;padding:26px 16px;font-size:13px;color:' . $couleur . '">'
       . h($message) . '</div>';
}

// ============================================================
//  RENDU v2 — maquette de référence
//
//  Le tableau de bord par profil passe à un nouveau vocabulaire visuel.
//  Deux surfaces ne suivent pas :
//
//   - pages/pdg_overview.php, qui garde templates/dash_style.php ;
//   - le rôle « lecteur », qui reste sur l'ancien rendu.
//
//  D'où l'interrupteur ci-dessous plutôt qu'un remplacement pur et simple :
//  les deux coquilles doivent coexister tant que ces deux surfaces vivent.
// ============================================================

/** Le rendu v2 est-il actif pour cette requête ? Posé par pages/dashboard.php. */
function dash_v2(?bool $activer = null): bool {
    static $actif = false;
    if ($activer !== null) $actif = $activer;
    return $actif;
}

/** Rôles qui restent sur l'ancien tableau de bord. */
function dash_role_v1(?array $user = null): bool {
    $user = $user ?? current_user();
    return ($user['role_slug'] ?? '') === 'lecteur';
}

/**
 * Variation entre deux valeurs, prête à afficher.
 *
 * Renvoie null quand il n'y a rien d'honnête à dire : sans valeur de
 * référence, un « +100 % » calculé depuis zéro est un artefact, pas une
 * information. Le bloc affiche alors sa ligne de contexte à la place.
 */
function dash_delta(?float $actuel, ?float $precedent): ?array {
    if ($precedent === null || $actuel === null) return null;
    if ($precedent == 0.0) return null;
    $pct = (($actuel - $precedent) / abs($precedent)) * 100;
    $sens = $pct > 0.05 ? 'up' : ($pct < -0.05 ? 'dn' : 'flat');
    return [
        'classe' => 'dv2-d-' . $sens,
        // La flèche double la couleur : l'information ne repose jamais sur
        // la teinte seule.
        'fleche' => $sens === 'up' ? '↑' : ($sens === 'dn' ? '↓' : '='),
        'texte'  => ($pct > 0 ? '+' : '') . number_format($pct, 1, ',', ' ') . ' %',
    ];
}

/** Une carte d'indicateur de la rangée de tête. */
function dash_v2_kpi(array $k): void {
    $ic = $k['icone'] ?? 'ph-chart-line';
    $tn = $k['ton']   ?? 'b';
    $tons = [
        'b' => ['var(--d-blue-l)',  'var(--d-blue)'],
        'g' => ['var(--d-green-l)', 'var(--d-green)'],
        'o' => ['var(--d-amber-l)', 'var(--d-amber)'],
        'r' => ['var(--d-red-l)',   'var(--d-red)'],
    ];
    [$bg, $fg] = $tons[$tn] ?? $tons['b'];

    echo '<div class="dv2-k">';
    echo   '<div class="dv2-k-hd">';
    echo     '<span class="dv2-k-l">' . h($k['libelle']) . '</span>';
    echo     '<span class="dv2-k-i" style="background:' . $bg . ';color:' . $fg . '">'
           .   '<i class="ph-duotone ' . h($ic) . '"></i></span>';
    echo   '</div>';
    echo   '<div class="dv2-k-v">' . h($k['valeur']) . '</div>';
    echo   '<div class="dv2-k-ft">';
    if (!empty($k['delta'])) {
        $d = $k['delta'];
        echo '<span class="dv2-d ' . $d['classe'] . '">' . $d['fleche'] . ' ' . h($d['texte']) . '</span>';
    }
    if (!empty($k['note'])) {
        echo '<span class="dv2-k-vs">' . h($k['note']) . '</span>';
    }
    echo   '</div>';
    echo '</div>';
}

/**
 * Les quatre indicateurs de tête, choisis selon le profil.
 *
 * La maquette met quatre cartes en haut de page ; encore faut-il qu'elles
 * portent ce qui compte pour qui regarde. Le coordinateur ouvre sur sa
 * production du jour, le gestionnaire de bobines sur sa file d'attente,
 * l'informatique sur l'état du parc.
 *
 * La comparaison est celle de la veille pour tout ce qui se compte par jour.
 * Les stocks n'ont pas d'historique quotidien en base : ils portent une ligne
 * de contexte au lieu d'une variation inventée.
 */
function dash_v2_kpis(array $portee): array {
    $today = date('Y-m-d');
    $hier  = date('Y-m-d', strtotime('-1 day'));
    [$ws, $as] = dash_filtre_site($portee, 'site_id');

    // Un seul aller-retour par métrique journalière, les deux jours à la fois.
    $jour = function (string $colonne) use ($ws, $as, $today, $hier): array {
        $sql = "SELECT date_point, COALESCE(SUM($colonne),0) AS v
                FROM op_points_journaliers
                WHERE date_point IN (?, ?) AND statut = 'valide' AND type_point IN ('point_18h','historique') $ws
                GROUP BY date_point";
        $rows = db_fetch_all($sql, array_merge([$today, $hier], $as));
        $m = [];
        foreach ($rows as $r) $m[(string)$r['date_point']] = (float)$r['v'];
        return [$m[$today] ?? 0.0, $m[$hier] ?? null];
    };

    $profil = dash_profil();
    $k = [];

    if (can('operations', 'can_read')
        && in_array($profil, ['coordinateur','superviseur_op','gestionnaire_op','general'], true)) {
        [$eng, $engP] = $jour('total_engins');
        [$pla, $plaP] = $jour('total_plaques');
        $k[] = ['libelle'=>'Engins traités','valeur'=>fmt_number($eng),'icone'=>'ph-truck','ton'=>'b',
                'delta'=>dash_delta($eng,$engP),'note'=>'vs. '.fmt_number($engP ?? 0).' hier'];
        $k[] = ['libelle'=>'Plaques posées','valeur'=>fmt_number($pla),'icone'=>'ph-rectangle','ton'=>'g',
                'delta'=>dash_delta($pla,$plaP),'note'=>'vs. '.fmt_number($plaP ?? 0).' hier'];
    }

    if (can('bobines', 'can_read')) {
        [$wb, $ab] = dash_filtre_site($portee, 'site_id');
        $bob  = (int)db_fetch_value("SELECT COUNT(*) FROM op_bobines WHERE statut IN ('en_cours','en_stock') $wb", $ab);
        $crit = (int)db_fetch_value("SELECT COUNT(*) FROM op_bobines WHERE statut='en_cours' AND films_restants < 50 $wb", $ab);
        $k[] = ['libelle'=>'Bobines actives','valeur'=>fmt_number($bob),'icone'=>'ph-disc',
                'ton'=>$crit > 0 ? 'o' : 'b',
                'note'=>$crit > 0 ? $crit.' sous le seuil critique' : 'aucune sous le seuil'];
    }

    if ($profil === 'gsb' && can('commandes_bobines', 'can_read')) {
        $cmd = (int)db_fetch_value("SELECT COUNT(*) FROM commandes_bobines WHERE statut IN ('en_attente','valide') $ws", $as);
        array_unshift($k, ['libelle'=>'Commandes à servir','valeur'=>fmt_number($cmd),
            'icone'=>'ph-clipboard-text','ton'=>$cmd > 0 ? 'o' : 'g',
            'note'=>$cmd > 0 ? 'en file d\'attente' : 'file vide']);
    }

    if ($profil === 'informatique' && can('equipements', 'can_read')) {
        [$we, $ae] = dash_filtre_site($portee, 'site_id');
        [$wc, $ac] = dash_filtre_categorie($portee, 'categorie');
        $arg = array_merge($ae, $ac);
        $tot = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 $we $wc", $arg);
        $hs  = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 AND etat='hs' $we $wc", $arg);
        $mt  = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 AND etat='maintenance' $we $wc", $arg);
        $fc  = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 AND date_fin_cycle IS NOT NULL AND date_fin_cycle <= (CURRENT_DATE + INTERVAL 30 DAY) $we $wc", $arg);
        $k = [
            ['libelle'=>'Parc actif','valeur'=>fmt_number($tot),'icone'=>'ph-desktop','ton'=>'b',
             'note'=>'équipements en service'],
            ['libelle'=>'Hors service','valeur'=>fmt_number($hs),'icone'=>'ph-warning-octagon',
             'ton'=>$hs > 0 ? 'r' : 'g','note'=>$tot > 0 ? number_format($hs / $tot * 100, 1, ',', ' ').' % du parc' : '—'],
            ['libelle'=>'En maintenance','valeur'=>fmt_number($mt),'icone'=>'ph-wrench',
             'ton'=>$mt > 0 ? 'o' : 'g','note'=>$mt > 0 ? 'immobilisés' : 'aucun immobilisé'],
            ['libelle'=>'Fin de cycle ≤ 30 j','valeur'=>fmt_number($fc),'icone'=>'ph-hourglass',
             'ton'=>$fc > 0 ? 'o' : 'g','note'=>$fc > 0 ? 'à renouveler' : 'rien à renouveler'],
        ];
    }

    if (can('validation_stock', 'can_read') || can('inventaire_bobines', 'can_read')) {
        $corr = (int)db_fetch_value("SELECT COUNT(*) FROM corrections_bobines WHERE statut='en_attente' $ws", $as);
        $k[] = ['libelle'=>'Corrections en attente','valeur'=>fmt_number($corr),
            'icone'=>'ph-arrows-clockwise','ton'=>$corr > 0 ? 'o' : 'g',
            'note'=>$corr > 0 ? 'à traiter' : 'rien à traiter'];
    }

    if (can('rivets', 'can_read') && count($k) < 4) {
        [$wr, $ar] = dash_filtre_site($portee, 'sr.site_id');
        $riv = (int)db_fetch_value("SELECT COALESCE(SUM(sr.quantite),0) FROM op_stock_rivets sr
                                    JOIN sites s ON s.id=sr.site_id WHERE s.actif=1 $wr", $ar);
        $k[] = ['libelle'=>'Rivets en stock','valeur'=>fmt_number($riv),'icone'=>'ph-nut',
            'ton'=>$riv < 100 ? 'r' : ($riv < 500 ? 'o' : 'g'),
            'note'=>$riv < 500 ? 'sous le seuil de confort' : 'au-dessus du seuil'];
    }

    // La rangée en compte quatre : au-delà elle déborde, en deçà elle sonne
    // creux. On tronque, et la grille en dessous porte le reste.
    return array_slice($k, 0, 4);
}


/**
 * Le mois que la synthèse doit montrer.
 *
 * Le mois civil est l'unité dans laquelle l'activité se raconte, donc c'est
 * lui qu'on affiche — tant qu'il porte quelque chose. Le premier du mois, et
 * pendant toute une période creuse, il ne porte rien : le chiffre de tête
 * serait alors un grand zéro exact et inutile, qui est le pire accueil
 * possible pour une page dont le rôle est de situer.
 *
 * On retombe donc sur le dernier mois ayant des relevés validés, et le
 * libellé le nomme. Mieux vaut un chiffre daté qu'un zéro ambigu.
 *
 * @return array{mois:string, libelle:string, courant:bool}
 */
function dash_periode(array $portee): array {
    [$w, $args] = dash_filtre_site($portee, 'pj.site_id');
    $courant = date('Y-m');

    $dernier = (string)db_fetch_value(
        "SELECT DATE_FORMAT(MAX(pj.date_point),'%Y-%m') FROM op_points_journaliers pj
         WHERE pj.statut = 'valide' AND pj.type_point IN ('point_18h','historique') $w", $args);

    $mois = ($dernier !== '' && $dernier < $courant) ? $dernier : $courant;

    $noms = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
             'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($mois . '-01');

    return [
        'mois'    => $mois,
        'libelle' => $noms[(int)date('n', $t)] . ' ' . date('Y', $t),
        'courant' => $mois === $courant,
    ];
}

/**
 * Canvas de graphe. Les séries voyagent en attributs plutôt qu'en variables
 * JavaScript : le contenu échangé par templates/dash_anim.php arrive alors
 * avec ses propres chiffres, sans script à réexécuter.
 */
function dash_graphe(string $type, array $valeurs, array $options = []): void {
    // Un canvas est un rectangle vide pour un lecteur d'écran : sans
    // équivalent textuel, l'information n'existe tout simplement pas. Le
    // résumé est construit à partir des mêmes chiffres que le tracé, donc il
    // ne peut pas diverger de ce qui est dessiné.
    $resume = $options['alt'] ?? dash_resume_graphe($type, $valeurs, $options['libelles'] ?? []);

    $attrs = 'data-graphe="' . h($type) . '"'
           . ' role="img" aria-label="' . h($resume) . '"'
           . " data-valeurs='" . h(json_encode(array_values($valeurs))) . "'";
    if (!empty($options['libelles'])) {
        $attrs .= " data-libelles='" . h(json_encode(array_values($options['libelles']))) . "'";
    }
    if (!empty($options['couleurs'])) {
        $attrs .= " data-couleurs='" . h(json_encode(array_values($options['couleurs']))) . "'";
    }
    if (!empty($options['couleur'])) $attrs .= ' data-couleur="' . h($options['couleur']) . '"';
    // Repère d'objectif de la jauge, en pourcentage.
    if (isset($options['cible']))    $attrs .= ' data-cible="' . (float)$options['cible'] . '"';
    if (!empty($options['taille']))  $attrs .= ' width="' . (int)$options['taille'] . '"';
    if (!empty($options['hauteur'])) $attrs .= ' height="' . (int)$options['hauteur'] . '"';
    echo '<canvas ' . $attrs . '></canvas>';
}

/**
 * Canvas multi-séries (courbe_multi). $series = [['label'=>…,'couleur'=>…,'valeurs'=>[…]],…]
 */
function dash_graphe_multi(array $series, array $libelles, int $hauteur = 160): void {
    $resume = implode(', ', array_map(fn($s) => $s['label'] . ' : ' . array_sum($s['valeurs']), $series));
    $attrs  = 'data-graphe="courbe_multi"'
            . ' role="img" aria-label="' . h($resume) . '"'
            . " data-series='" . h(json_encode($series)) . "'"
            . " data-libelles='" . h(json_encode(array_values($libelles))) . "'"
            . ' height="' . $hauteur . '"';
    echo '<canvas ' . $attrs . '></canvas>';
}

/**
 * Résumé parlé d'un graphe. Il ne récite pas toute la série — une courbe de
 * douze mois lue point par point est inexploitable à l'oreille — mais donne
 * ce qu'on retient en regardant : le total, les extrêmes, la tendance.
 */
function dash_resume_graphe(string $type, array $valeurs, array $libelles): string {
    if (!$valeurs) return 'Graphique sans donnée.';

    $total = array_sum($valeurs);
    $iMax  = array_search(max($valeurs), $valeurs, true);
    $nomMax = $libelles[$iMax] ?? null;

    if ($type === 'courbe') {
        // La tendance se lit sur les périodes qui portent quelque chose. La
        // calculer sur le premier et le dernier point annonçait « stable »
        // dès que la série commençait et finissait à zéro, ce qui est exact
        // et ne dit rien.
        $portantes = array_values(array_filter($valeurs, fn($v) => $v > 0));
        $sens = '';
        if (count($portantes) >= 2) {
            $a = (float)$portantes[count($portantes) - 2];
            $b = (float)$portantes[count($portantes) - 1];
            $sens = $b > $a ? ', en hausse sur la fin' : ($b < $a ? ', en baisse sur la fin' : '');
        }
        return sprintf(
            'Courbe sur %d périodes, total %s%s. Maximum de %s%s.',
            count($valeurs), ent((float)$total), $sens, ent((float)max($valeurs)),
            $nomMax ? ' en ' . $nomMax : '');
    }

    if ($type === 'anneau') {
        return sprintf('Répartition de %s au total, en %d parts. Part la plus grande : %s.',
            ent((float)$total), count($valeurs), ent((float)max($valeurs)));
    }

    // Barres : le classement est ce qui compte, donc on nomme la tête.
    $tete = [];
    $ordre = $valeurs;
    arsort($ordre);
    foreach (array_slice($ordre, 0, 3, true) as $i => $v) {
        $tete[] = ($libelles[$i] ?? 'sans nom') . ' ' . ent((float)$v);
    }
    return sprintf('Classement de %d entrées, total %s. En tête : %s.',
        count($valeurs), ent((float)$total), implode(', ', $tete));
}

/** Légende d'un anneau : pastille, libellé, valeur. */
function dash_legende(array $entrees): void {
    echo '<div class="leg-list">';
    foreach ($entrees as $e) {
        echo '<div class="leg-item"><span class="leg-dot" style="background:' . h($e['couleur']) . '"></span>'
           . h($e['lbl']) . '<span class="leg-val">' . ent((float)$e['val']) . '</span></div>';
    }
    echo '</div>';
}

/** Libellé lisible d'un type de point journalier. */
function dash_type_point(string $t): string {
    return [
        'point_9h'      => '9 h',
        'point_13h'     => '13 h',
        'point_18h'     => '18 h',
        'final'         => 'Final',
        'intermediaire' => 'Intermédiaire',
    ][$t] ?? $t;
}

// ============================================================
//  LE REGISTRE
// ============================================================

/**
 * Tous les blocs disponibles, indexés par identifiant.
 *
 * Chaque entrée :
 *   titre      — en-tête de la carte
 *   soustitre  — précision de périmètre ou de période
 *   module     — module de permission ; null = toujours visible
 *   droit      — droit requis sur ce module (can_read par défaut)
 *   largeur    — 'demi' (défaut) ou 'plein'
 *   lien       — [chemin, libellé] vers la page complète
 *   donnees    — fn(array $portee): mixed
 *   rendu      — fn(mixed $donnees, array $portee): void
 *
 * Les requêtes reçoivent la portée et l'appliquent : c'est ce qui fait
 * qu'un bloc écrit une fois sert plusieurs profils.
 */
function dash_registre(): array {
    return [


    // ══════════════════════════════════════════════════════════════════
    //  GRAPHES
    //
    //  Le registre ne portait que des tableaux et des listes : huit cartes
    //  de même forme, où rien ne disait où regarder en premier. Ces trois
    //  blocs apportent la lecture visuelle qu'avait déjà la vue PDG, avec
    //  le même moteur de tracé (templates/dash_charts.php).
    // ══════════════════════════════════════════════════════════════════
    // ── Évolution des engins posés ────────────────────────────────────
    'evolution_engins' => [
        'titre'     => 'Évolution des engins posés',
        'soustitre' => 'Douze derniers mois',
        'module'    => 'point_emuci',
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'pj.site_id');
            $lignes = db_fetch_all(
                "SELECT DATE_FORMAT(pj.date_point,'%Y-%m') AS m,
                        COALESCE(SUM(pj.total_engins),0) AS v
                 FROM op_points_journaliers pj
                 WHERE pj.statut = 'valide' AND pj.type_point IN ('point_18h','historique')
                   AND pj.date_point >= DATE_SUB(DATE_FORMAT(CURRENT_DATE,'%Y-%m-01'), INTERVAL 11 MONTH) $w
                 GROUP BY 1 ORDER BY 1", $args);

            // Les mois sans relevé doivent apparaître à zéro : une série
            // trouée décale les points et fait mentir la pente.
            $par_mois = [];
            foreach ($lignes as $l) $par_mois[$l['m']] = (float)$l['v'];

            $noms = ['', 'janv', 'févr', 'mars', 'avr', 'mai', 'juin',
                     'juil', 'août', 'sept', 'oct', 'nov', 'déc'];
            $valeurs = $libelles = [];
            for ($i = 11; $i >= 0; $i--) {
                $t = strtotime("-$i months", strtotime(date('Y-m-01')));
                $cle = date('Y-m', $t);
                $valeurs[]  = $par_mois[$cle] ?? 0;
                $libelles[] = $noms[(int)date('n', $t)];
            }
            return ['valeurs' => $valeurs, 'libelles' => $libelles];
        },
        'rendu' => function (array $d) {
            if (array_sum($d['valeurs']) <= 0) {
                dash_vide('Aucun point validé sur les douze derniers mois.');
                return;
            }
            dash_graphe('courbe', $d['valeurs'], ['libelles' => $d['libelles'], 'hauteur' => 180]);
        },
    ],

    // ── Répartition du parc ───────────────────────────────────────────
    'repartition_parc' => [
        'titre'     => 'État du parc',
        'soustitre' => 'Équipements actifs par état',
        'module'    => 'equipements',
        'lien'      => ['/pages/equipements.php', 'Le parc'],
        'donnees'   => function (array $p) {
            [$ws, $as] = dash_filtre_site($p, 'e.site_id');
            [$wc, $ac] = dash_filtre_categorie($p, 'e.categorie');
            return db_fetch_all(
                "SELECT e.etat, COUNT(*) AS n FROM equipements e
                 WHERE e.actif = 1 $ws $wc GROUP BY e.etat ORDER BY n DESC",
                array_merge($as, $ac));
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucun équipement actif sur ce périmètre.'); return; }
            $teintes = ['neuf' => '#1B75BC', 'bon' => '#16a34a',
                        'usage' => '#d97706', 'reforme' => '#dc2626'];
            $noms    = ['neuf' => 'Neuf', 'bon' => 'Bon',
                        'usage' => 'Usagé', 'reforme' => 'Réformé'];
            $val = $coul = []; $leg = [];
            foreach ($d as $r) {
                $c = $teintes[$r['etat']] ?? '#6d28d9';
                $val[]  = (int)$r['n'];
                $coul[] = $c;
                $leg[]  = ['lbl' => $noms[$r['etat']] ?? ucfirst($r['etat']),
                           'val' => (int)$r['n'], 'couleur' => $c];
            }
            $parle = [];
            foreach ($leg as $e) $parle[] = $e['lbl'] . ' ' . (int)$e['val'];

            echo '<div class="donut-wrap">';
            dash_graphe('anneau', $val, [
                'couleurs' => $coul,
                'taille'   => 120,
                'alt'      => 'État du parc, ' . array_sum($val) . ' équipements actifs : '
                              . implode(', ', $parle) . '.',
            ]);
            dash_legende($leg);
            echo '</div>';
        },
    ],

    // ── Engins posés par site ─────────────────────────────────────────
    'perf_sites' => [
        'titre'     => 'Engins posés par site',
        'soustitre' => 'Points validés',
        'module'    => 'point_emuci',
        'largeur'   => 'plein',
        'donnees'   => function (array $p) {
            // Même période que le bandeau, pour que les deux racontent le
            // même mois : un classement par site sur un mois vide, juste
            // sous une synthèse qui en montre un autre, se contredirait.
            $per = dash_periode($p);
            [$w, $args] = dash_filtre_site($p, 'pj.site_id');
            return [
                'periode' => $per,
                'lignes'  => db_fetch_all(
                    "SELECT s.nom, COALESCE(SUM(pj.total_engins),0) AS v
                     FROM op_points_journaliers pj
                     JOIN sites s ON s.id = pj.site_id
                     WHERE pj.statut = 'valide' AND pj.type_point IN ('point_18h','historique')
                       AND DATE_FORMAT(pj.date_point,'%Y-%m') = ? $w
                     GROUP BY s.nom HAVING SUM(pj.total_engins) > 0
                     ORDER BY 2 DESC LIMIT 12",
                    array_merge([$per['mois']], $args)),
            ];
        },
        'rendu' => function (array $d) {
            if (!$d['lignes']) { dash_vide('Aucun engin posé sur ce périmètre.'); return; }
            echo '<div class="card-sub" style="margin:-8px 0 14px">'
               . h(ucfirst($d['periode']['libelle'])) . '</div>';
            dash_graphe('barres',
                array_map(fn($r) => (float)$r['v'], $d['lignes']),
                ['libelles' => array_map(fn($r) => $r['nom'], $d['lignes'])]);
        },
    ],

    // ── Points journaliers récents ────────────────────────────────────
    'points_recents' => [
        'titre'     => 'Points journaliers récents',
        'soustitre' => 'Cinq derniers relevés',
        'module'    => 'operations',
        'lien'      => ['/pages/operations/point_journalier.php', 'Tous les points'],
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'pj.site_id');
            return db_fetch_all(
                "SELECT pj.date_point, pj.type_point, pj.statut, pj.total_engins,
                        pj.total_plaques, pj.rivets_utilises, s.nom AS site_nom
                 FROM op_points_journaliers pj
                 JOIN sites s ON s.id = pj.site_id
                 WHERE 1=1 $w
                 ORDER BY pj.date_point DESC, pj.id DESC
                 LIMIT 5", $args);
        },
        'rendu' => function (array $d, array $p) {
            if (!$d) { dash_vide('Aucun point journalier sur ce périmètre.'); return; }
            echo '<table class="ptbl"><thead><tr><th>Date</th>';
            if (!$p['site_id']) echo '<th style="text-align:left">Site</th>';
            echo '<th>Engins</th><th>Plaques</th><th>Rivets</th><th style="text-align:right">État</th>'
               . '</tr></thead><tbody>';
            foreach ($d as $r) {
                $valide = ($r['statut'] === 'valide');
                echo '<tr><td>' . h(fmt_date($r['date_point'], 'd/m'))
                   . ' <span class="card-sub" style="margin:0">' . h(dash_type_point($r['type_point'])) . '</span></td>';
                if (!$p['site_id']) echo '<td>' . h($r['site_nom']) . '</td>';
                echo '<td>' . ent((float)$r['total_engins']) . '</td>'
                   . '<td>' . ent((float)$r['total_plaques']) . '</td>'
                   . '<td>' . ent((float)$r['rivets_utilises']) . '</td>'
                   . '<td style="text-align:right"><span class="mvh ' . ($valide ? 'mvh-g' : 'mvh-o') . '">'
                   . ($valide ? 'Validé' : 'Brouillon') . '</span></td></tr>';
            }
            echo '</tbody></table>';
        },
    ],

    // ── Points en attente de validation ───────────────────────────────
    'points_attente' => [
        'titre'      => 'Points en attente de validation',
        'soustitre'  => 'Relevés encore en brouillon',
        'module'     => 'operations',
        'lien'       => ['/pages/operations/point_journalier.php', 'Valider'],
        'lien_droit' => 'can_update',
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'pj.site_id');
            return db_fetch_all(
                "SELECT pj.id, pj.date_point, pj.type_point, pj.total_engins,
                        s.nom AS site_nom,
                        CONCAT(u.prenom, ' ', u.nom) AS auteur,
                        (CURRENT_DATE - pj.date_point) AS anciennete
                 FROM op_points_journaliers pj
                 JOIN sites s ON s.id = pj.site_id
                 LEFT JOIN users u ON u.id = pj.created_by
                 WHERE pj.statut = 'en_attente_validation' $w
                 ORDER BY pj.date_point ASC
                 LIMIT 8", $args);
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucun point en attente de validation.', true); return; }
            $sc = count($d) > 5;
            if ($sc) echo '<div style="max-height:256px;overflow-y:auto">';
            echo '<table class="dtbl"><thead><tr><th>Date</th><th>Site</th><th>Auteur</th>'
               . '<th style="text-align:right">Soumis</th></tr></thead><tbody>';
            foreach ($d as $r) {
                $j   = (int)$r['anciennete'];
                $cls = $j >= 2 ? 'mvh-r' : ($j >= 1 ? 'mvh-o' : 'mvh-g');
                echo '<tr><td>' . h(fmt_date($r['date_point'], 'd/m'))
                   . ' <span style="color:#5a6678">' . h(dash_type_point($r['type_point'])) . '</span></td>'
                   . '<td style="font-weight:600">' . h($r['site_nom']) . '</td>'
                   . '<td style="color:#5a6678">' . h($r['auteur'] ?? '—') . '</td>'
                   . '<td style="text-align:right"><span class="mvh ' . $cls . '">'
                   . ($j <= 0 ? "Aujourd'hui" : ent((float)$j) . ' j') . '</span></td></tr>';
            }
            echo '</tbody></table>';
            if ($sc) echo '</div>';
        },
    ],

    // ── Corrections de saisie en attente ──────────────────────────────
    'corrections_attente' => [
        'titre'      => 'Corrections en attente',
        'soustitre'  => 'Demandes de correction de saisie',
        'module'     => 'validation_stock',
        'lien'       => ['/pages/validation_stock_matin.php', 'Traiter'],
        'lien_droit' => 'can_create',
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'pj.site_id');
            return db_fetch_all(
                "SELECT dc.id, dc.motif, dc.created_at, pj.date_point, pj.type_point,
                        s.nom AS site_nom, CONCAT(u.prenom, ' ', u.nom) AS demandeur
                 FROM demandes_correction_saisie dc
                 JOIN op_points_journaliers pj ON pj.id = dc.point_id
                 JOIN sites s ON s.id = pj.site_id
                 LEFT JOIN users u ON u.id = dc.demande_par
                 WHERE dc.statut = 'en_attente' $w
                 ORDER BY dc.created_at ASC
                 LIMIT 6", $args);
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucune correction demandée.', true); return; }
            $sc = count($d) > 3;
            if ($sc) echo '<div style="max-height:220px;overflow-y:auto;padding-right:2px">';
            foreach ($d as $r) {
                echo '<div class="biz-risk-r" style="margin-bottom:9px">'
                   . '<div class="biz-risk-i"><i class="ph-duotone ph-pencil-simple"></i></div>'
                   . '<div class="biz-risk-b">'
                   . '<div class="biz-risk-t">' . h($r['site_nom']) . ' · '
                   . h(fmt_date($r['date_point'], 'd/m')) . ' ' . h(dash_type_point($r['type_point'])) . '</div>'
                   . '<div class="biz-risk-s">' . h($r['motif']) . '</div>'
                   . '<div class="biz-risk-s" style="margin-top:3px">Demandé par '
                   . h($r['demandeur'] ?? '—') . ' le ' . h(fmt_date($r['created_at'])) . '</div>'
                   . '</div></div>';
            }
            if ($sc) echo '</div>';
        },
    ],

    // ── Validations du stock du matin ─────────────────────────────────
    'validations_matin' => [
        'titre'       => 'Validations du stock matin',
        'soustitre'   => 'Sept derniers jours',
        'module'      => 'inventaire_bobines',
        'lien'        => ['/pages/validation_stock_matin.php', 'Voir le détail'],
        'lien_module' => 'validation_stock',
        'lien_droit'  => 'can_create',
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'v.site_id');
            return db_fetch_all(
                "SELECT v.date_validation, v.statut, v.nb_ecarts, s.nom AS site_nom
                 FROM validations_stock_matin v
                 JOIN sites s ON s.id = v.site_id
                 WHERE v.date_validation >= (CURRENT_DATE - INTERVAL 7 DAY) $w
                 ORDER BY v.date_validation DESC, s.nom
                 LIMIT 10", $args);
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucune validation enregistrée cette semaine.'); return; }
            $sc = count($d) > 5;
            if ($sc) echo '<div style="max-height:260px;overflow-y:auto;padding-right:2px">';
            foreach ($d as $r) {
                $ec  = (int)$r['nb_ecarts'];
                $cls = $ec === 0 ? 'hc-green' : ($ec <= 2 ? 'hc-orange' : 'hc-red');
                echo '<div class="stock-stat-row">'
                   . '<div><div style="font-weight:700;color:#06033A">' . h($r['site_nom']) . '</div>'
                   . '<div class="card-sub" style="margin:2px 0 0">' . h(fmt_date($r['date_validation']))
                   . ' · ' . h(str_replace('_', ' ', $r['statut'])) . '</div></div>'
                   . '<span class="hc-badge ' . $cls . '" style="margin:0">'
                   . ($ec === 0 ? 'Aucun écart' : ent((float)$ec) . ' écart' . ($ec > 1 ? 's' : ''))
                   . '</span></div>';
            }
            if ($sc) echo '</div>';
        },
    ],

    // ── Commandes de bobines ──────────────────────────────────────────
    'commandes_bobines' => [
        'titre'     => 'Commandes de bobines',
        'soustitre' => 'À servir ou en cours',
        'module'    => 'commandes_bobines',
        'lien'      => ['/pages/commandes_bobines.php', 'Toutes les commandes'],
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'c.site_id');
            return db_fetch_all(
                "SELECT c.numero, c.libelle_type, c.statut, c.created_at,
                        s.nom AS site_nom, CONCAT(u.prenom, ' ', u.nom) AS demandeur
                 FROM commandes_bobines c
                 JOIN sites s ON s.id = c.site_id
                 LEFT JOIN users u ON u.id = c.demande_par
                 WHERE c.statut IN ('en_attente', 'valide') $w
                 ORDER BY c.created_at ASC
                 LIMIT 8", $args);
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucune commande en cours.', true); return; }
            $sc = count($d) > 5;
            if ($sc) echo '<div style="max-height:256px;overflow-y:auto">';
            echo '<table class="dtbl"><thead><tr><th>N°</th><th>Site</th><th>Type</th>'
               . '<th style="text-align:right">État</th></tr></thead><tbody>';
            foreach ($d as $r) {
                $att = ($r['statut'] === 'en_attente');
                echo '<tr><td style="font-family:monospace">' . h($r['numero']) . '</td>'
                   . '<td style="font-weight:600">' . h($r['site_nom']) . '</td>'
                   . '<td>' . h($r['libelle_type']) . '</td>'
                   . '<td style="text-align:right"><span class="d-statut ' . ($att ? 'ds-att' : 'ds-enc') . '">'
                   . ($att ? 'À valider' : 'À servir') . '</span></td></tr>';
            }
            echo '</tbody></table>';
            if ($sc) echo '</div>';
        },
    ],

    // ── Stock de consommables bas ─────────────────────────────────────
    'stock_bas' => [
        'titre'     => 'Stock consommables bas',
        'soustitre' => 'Sous le seuil d\'alerte',
        'module'    => 'consommables',
        'largeur'   => 'plein',
        'lien'      => ['/pages/articles.php', 'Gérer'],
        'donnees'   => function (array $p) {
            // Le coordinateur raisonne sur le stock de son site, les autres
            // sur le stock global : ce ne sont pas les mêmes tables.
            if ($p['site_id']) {
                return db_fetch_all(
                    "SELECT a.libelle, a.unite, ss.quantite AS stock, a.seuil_alerte
                     FROM stock_site ss
                     JOIN articles a ON a.id = ss.article_id
                     WHERE ss.site_id = ? AND ss.quantite <= a.seuil_alerte
                     ORDER BY (ss.quantite / NULLIF(a.seuil_alerte,0)) ASC
                     LIMIT 8", [$p['site_id']]);
            }
            return db_fetch_all(
                "SELECT libelle, unite, stock_global AS stock, seuil_alerte
                 FROM articles
                 WHERE stock_global <= seuil_alerte
                 ORDER BY (stock_global / NULLIF(seuil_alerte,0)) ASC
                 LIMIT 8");
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Tous les stocks sont au-dessus du seuil.', true); return; }
            foreach ($d as $r) {
                $seuil = (float)$r['seuil_alerte'];
                $pct   = $seuil > 0 ? min(100, (float)$r['stock'] / $seuil * 100) : 0;
                $bas   = $pct <= 25;
                echo '<div class="biz-cov-r">'
                   . '<div><div class="biz-cov-n">' . h($r['libelle']) . '</div>'
                   . '<div class="biz-cov-s">seuil ' . ent($seuil) . ' ' . h($r['unite']) . '</div></div>'
                   . '<div class="biz-cov-bar"><div class="biz-cov-f' . ($bas ? ' low' : '')
                   . '" style="width:' . round($pct) . '%"></div></div>'
                   . '<div class="biz-cov-d"><span class="biz-cov-num">' . ent((float)$r['stock'])
                   . '</span><span class="biz-cov-u">' . h($r['unite']) . '</span></div>'
                   . '</div>';
            }
        },
    ],

    // ── Raccourcis ────────────────────────────────────────────────────
    'raccourcis' => [
        'titre'     => 'Raccourcis',
        'soustitre' => 'Ce que votre profil peut faire',
        'module'    => null,
        'largeur'   => 'plein',
        // Chaque raccourci est conditionné par sa propre permission : la
        // liste ne propose jamais une page qui renverrait un refus.
        'donnees'   => function () {
            $tout = [
                ['commandes',          'can_read',   '/pages/commandes_bobines.php',              'Commandes de bobines',    'ph-clipboard-text'],
                ['point_emuci',        'can_create', '/pages/operations/point_journalier.php',    'Saisir un point',         'ph-note-pencil'],
                ['inventaire_bobines', 'can_read',   '/pages/validation_stock_matin.php',         'Stock du matin',          'ph-check-square-offset'],
                ['bobines',            'can_read',   '/pages/operations/bobines.php',             'Bobines',                 'ph-film-strip'],
                ['rivets',             'can_read',   '/pages/operations/rivets.php',              'Rivets',                  'ph-nut'],
                ['receptions',         'can_read',   '/pages/reception_site.php',                 'Réceptions',              'ph-package'],
                ['equipements',        'can_read',   '/pages/equipements.php',                    'Équipements',             'ph-desktop'],
                ['rapports',           'can_read',   '/pages/rapports.php',                       'Rapports',                'ph-chart-bar'],
            ];
            return array_values(array_filter($tout, fn($r) => can($r[0], $r[1])));
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucun module accessible avec ce profil.'); return; }
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:11px">';
            foreach ($d as [, , $url, $libelle, $icone]) {
                echo '<a href="' . APP_URL . h($url) . '" class="biz-risk-r" '
                   . 'style="text-decoration:none;background:#f8fafc">'
                   . '<div class="biz-risk-i" style="background:#eef2ff;color:#1e40af">'
                   . '<i class="ph-duotone ' . h($icone) . '"></i></div>'
                   . '<div class="biz-risk-t">' . h($libelle) . '</div></a>';
            }
            echo '</div>';
        },
    ],

    // ── Réceptions de site ────────────────────────────────────────────
    'receptions_site' => [
        'titre'     => 'Réceptions récentes',
        'soustitre' => 'Consommables et équipements reçus',
        'module'    => 'receptions',
        'largeur'   => 'plein',
        'lien'      => ['/pages/reception_site.php', 'Toutes les réceptions'],
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'rs.site_id');
            return db_fetch_all(
                "SELECT rs.date_reception, rs.type_reception, rs.quantite, rs.statut,
                        s.nom AS site_nom, c.libelle AS conso_lib, c.unite,
                        n.libelle AS equip_type
                 FROM receptions_site rs
                 JOIN sites s ON s.id = rs.site_id
                 LEFT JOIN articles c ON c.id = rs.consommable_id
                 LEFT JOIN equipements e ON e.id = rs.equipement_id
                 LEFT JOIN nomenclatures n ON n.id = e.nomenclature_id
                 WHERE 1=1 $w
                 ORDER BY rs.created_at DESC
                 LIMIT 10", $args);
        },
        'rendu' => function (array $d, array $p) {
            if (!$d) { dash_vide('Aucune réception enregistrée.'); return; }
            echo '<div class="table-wrap"><table class="dtbl"><thead><tr><th>Date</th>';
            if (!$p['site_id']) echo '<th>Site</th>';
            echo '<th>Article</th><th style="text-align:right">Qté</th>'
               . '<th style="text-align:right">Statut</th></tr></thead><tbody>';
            foreach ($d as $r) {
                $conso = ($r['type_reception'] === 'consommable');
                $cls   = match ($r['statut']) {
                    'receptionnee' => 'mvh-g',
                    'litige'       => 'mvh-r',
                    default        => 'mvh-o',
                };
                $lbl = match ($r['statut']) {
                    'receptionnee' => 'Reçu',
                    'litige'       => 'Litige',
                    'en_attente'   => 'En attente',
                    default        => $r['statut'],
                };
                echo '<tr><td style="color:#5a6678">' . h(fmt_date($r['date_reception'])) . '</td>';
                if (!$p['site_id']) echo '<td style="font-weight:600">' . h($r['site_nom']) . '</td>';
                echo '<td>' . h($conso ? ($r['conso_lib'] ?? '—') : ($r['equip_type'] ?? '—')) . '</td>'
                   . '<td style="text-align:right;font-weight:700">'
                   . ($r['quantite'] !== null ? ent((float)$r['quantite']) . ' ' . h($r['unite'] ?? '') : '—') . '</td>'
                   . '<td style="text-align:right"><span class="mvh ' . $cls . '">' . h($lbl) . '</span></td></tr>';
            }
            echo '</tbody></table></div>';
        },
    ],

    // ── Stock de consommables du site ─────────────────────────────────
    'stock_conso_site' => [
        'titre'     => 'Stock consommables du site',
        'soustitre' => 'Quantités et seuils',
        'module'    => 'consommables',
        'donnees'   => function (array $p) {
            if (!$p['site_id']) return [];
            return db_fetch_all(
                "SELECT a.libelle, a.unite, ss.quantite, a.seuil_alerte
                 FROM stock_site ss
                 JOIN articles a ON a.id = ss.article_id
                 WHERE ss.site_id = ?
                 ORDER BY (ss.quantite / NULLIF(a.seuil_alerte,0)) ASC
                 LIMIT 10", [$p['site_id']]);
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucun consommable suivi sur ce site.'); return; }
            $sc = count($d) > 4;
            if ($sc) echo '<div style="max-height:224px;overflow-y:auto;padding-right:2px">';
            foreach ($d as $r) {
                $seuil = (float)$r['seuil_alerte'];
                $bas   = $seuil > 0 && (float)$r['quantite'] <= $seuil;
                $pct   = $seuil > 0 ? min(100, (float)$r['quantite'] / $seuil * 100) : 100;
                echo '<div class="biz-cov-r">'
                   . '<div><div class="biz-cov-n">' . h($r['libelle']) . '</div>'
                   . '<div class="biz-cov-s">seuil ' . ent($seuil) . '</div></div>'
                   . '<div class="biz-cov-bar"><div class="biz-cov-f' . ($bas ? ' low' : '')
                   . '" style="width:' . round($pct) . '%"></div></div>'
                   . '<div class="biz-cov-d"><span class="biz-cov-num">' . ent((float)$r['quantite'])
                   . '</span><span class="biz-cov-u">' . h($r['unite']) . '</span></div></div>';
            }
            if ($sc) echo '</div>';
        },
    ],

    // ── Parc d'équipements ────────────────────────────────────────────
    'equipements' => [
        'titre'     => 'Parc d\'équipements',
        'soustitre' => 'Répartition par état',
        'module'    => 'equipements',
        'lien'      => ['/pages/equipements.php', 'Le parc'],
        'donnees'   => function (array $p) {
            [$ws, $as] = dash_filtre_site($p, 'site_id');
            [$wc, $ac] = dash_filtre_categorie($p, 'categorie');
            $args  = array_merge($as, $ac);
            $total = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 $ws $wc", $args);
            $etats = db_fetch_all(
                "SELECT etat, COUNT(*) AS total FROM equipements
                 WHERE actif=1 $ws $wc GROUP BY etat ORDER BY total DESC", $args);
            return ['total' => $total, 'etats' => $etats];
        },
        'rendu' => function (array $d) {
            $total = $d['total'];
            if ($total === 0) { dash_vide('Aucun équipement sur ce périmètre.'); return; }
            $libelles = ['neuf'=>'Neuf','bon'=>'Bon état','usage'=>'Usagé',
                         'mauvais'=>'Mauvais état','hs'=>'Hors service','maintenance'=>'En maintenance'];
            $teintes  = ['neuf'=>'#06033A','bon'=>'#1e40af','usage'=>'#f59e0b',
                         'mauvais'=>'#d97706','hs'=>'#dc2626','maintenance'=>'#6d28d9'];
            $parts = pct_entiers(array_map(fn($e) => (float)$e['total'], $d['etats']), (float)$total);

            echo '<div class="eq-big-l">Total actif</div>';
            echo '<div class="eq-big">' . ent((float)$total) . '</div>';
            echo '<div class="dist-bar" style="margin-top:16px">';
            foreach ($d['etats'] as $i => $e) {
                echo '<div class="dist-seg" style="width:' . $parts[$i] . '%;background:'
                   . ($teintes[$e['etat']] ?? '#94a3b8') . '"></div>';
            }
            echo '</div>';
            foreach ($d['etats'] as $i => $e) {
                echo '<div class="eq-r"><span class="eq-sq" style="background:'
                   . ($teintes[$e['etat']] ?? '#94a3b8') . '"></span>'
                   . '<span class="eq-r-l">' . h($libelles[$e['etat']] ?? $e['etat']) . '</span>'
                   . '<span class="eq-r-n">' . ent((float)$e['total']) . '</span>'
                   . '<span class="biz-cov-u" style="min-width:34px;text-align:right">'
                   . $parts[$i] . ' %</span></div>';
            }
        },
    ],

    // ── Équipements en fin de cycle ───────────────────────────────────
    'fin_cycle' => [
        'titre'     => 'Fin de cycle',
        'soustitre' => 'Soixante prochains jours',
        'module'    => 'equipements',
        'largeur'   => 'plein',
        'lien'      => ['/pages/equipements.php', 'Le parc'],
        'donnees'   => function (array $p) {
            [$ws, $as] = dash_filtre_site($p, 'e.site_id');
            [$wc, $ac] = dash_filtre_categorie($p, 'e.categorie');
            return db_fetch_all(
                "SELECT e.numero_serie_interne, e.date_fin_cycle, n.libelle AS type_equip,
                        s.nom AS site_nom,
                        DATEDIFF(e.date_fin_cycle, CURRENT_DATE) AS jours
                 FROM equipements e
                 JOIN nomenclatures n ON n.id = e.nomenclature_id
                 LEFT JOIN sites s ON s.id = e.site_id
                 WHERE e.actif=1 AND e.date_fin_cycle IS NOT NULL
                   AND e.date_fin_cycle <= (CURRENT_DATE + INTERVAL 60 DAY) $ws $wc
                 ORDER BY e.date_fin_cycle ASC
                 LIMIT 8", array_merge($as, $ac));
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucun équipement en fin de cycle.', true); return; }
            foreach ($d as $r) {
                $j   = (int)$r['jours'];
                $cls = $j < 0 ? 'hc-red' : ($j <= 30 ? 'hc-orange' : 'hc-green');
                echo '<div class="stock-stat-row">'
                   . '<div><div style="font-weight:700;color:#06033A">' . h($r['type_equip']) . '</div>'
                   . '<div class="card-sub" style="margin:2px 0 0;font-family:monospace">'
                   . h($r['numero_serie_interne']) . ' · ' . h($r['site_nom'] ?? 'Non affecté') . '</div></div>'
                   . '<span class="hc-badge ' . $cls . '" style="margin:0">'
                   . ($j < 0 ? 'Dépassé' : ($j === 0 ? "Aujourd'hui" : 'J-' . ent((float)$j)))
                   . '</span></div>';
            }
        },
    ],

    // ── Sites par type ────────────────────────────────────────────────
    'sites' => [
        'titre'     => 'Sites actifs',
        'soustitre' => 'Répartition par type',
        'module'    => 'sites',
        'lien'      => ['/pages/admin/sites.php', 'Les sites'],
        'donnees'   => fn() => db_fetch_all(
            "SELECT type, COUNT(*) AS nb FROM sites WHERE actif=1 GROUP BY type ORDER BY nb DESC"),
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucun site configuré.'); return; }
            $libelles = ['pose'=>'Pose','siege'=>'Siège','mixte'=>'Mixte',
                         'entrepot'=>'Entrepôt','autre'=>'Autre'];
            $total = array_sum(array_column($d, 'nb'));
            $parts = pct_entiers(array_map(fn($r) => (float)$r['nb'], $d), (float)$total);
            echo '<div class="eq-big-l">Total</div><div class="eq-big">' . ent((float)$total) . '</div>';
            echo '<div style="margin-top:14px">';
            foreach ($d as $i => $r) {
                echo '<div class="biz-cov-r">'
                   . '<div class="biz-cov-n">' . h($libelles[$r['type']] ?? ucfirst($r['type'])) . '</div>'
                   . '<div class="biz-cov-bar"><div class="biz-cov-f" style="width:' . $parts[$i] . '%"></div></div>'
                   . '<div class="biz-cov-d"><span class="biz-cov-num">' . ent((float)$r['nb']) . '</span></div>'
                   . '</div>';
            }
            echo '</div>';
        },
    ],

    // ── Bobines actives par site ──────────────────────────────────────
    'bobines_sites' => [
        'titre'     => 'Bobines actives',
        'soustitre' => 'En stock ou en cours d\'usage',
        'module'    => 'bobines',
        'lien'      => ['/pages/operations/bobines.php', 'Les bobines'],
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 's.id');
            return db_fetch_all(
                "SELECT s.nom AS site_nom, COUNT(b.id) AS nb,
                        COALESCE(SUM(b.stock_systeme),0) AS films
                 FROM sites s
                 LEFT JOIN op_bobines b ON b.site_id = s.id
                      AND b.statut IN ('en_cours','en_stock')
                 WHERE s.actif=1 $w
                 GROUP BY s.id, s.nom
                 HAVING COUNT(b.id) > 0
                 ORDER BY nb DESC
                 LIMIT 10", $args);
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucune bobine active sur ce périmètre.'); return; }
            $max   = max(array_map(fn($r) => (float)$r['nb'], $d)) ?: 1;
            $scroll = count($d) > 4;
            if ($scroll) echo '<div style="max-height:224px;overflow-y:auto;padding-right:2px">';
            foreach ($d as $r) {
                echo '<div class="biz-cov-r">'
                   . '<div><div class="biz-cov-n">' . h($r['site_nom']) . '</div>'
                   . '<div class="biz-cov-s">' . ent((float)$r['films']) . ' films restants</div></div>'
                   . '<div class="biz-cov-bar"><div class="biz-cov-f" style="width:'
                   . round((float)$r['nb'] / $max * 100) . '%"></div></div>'
                   . '<div class="biz-cov-d"><span class="biz-cov-num">' . ent((float)$r['nb'])
                   . '</span><span class="biz-cov-u">bobines</span></div></div>';
            }
            if ($scroll) echo '</div>';
        },
    ],

    // ── Rivets en stock ───────────────────────────────────────────────
    'rivets' => [
        'titre'     => 'Rivets en stock',
        'soustitre' => 'Par site et par type',
        'module'    => 'rivets',
        'lien'      => ['/pages/operations/rivets.php', 'Les rivets'],
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'sr.site_id');
            return db_fetch_all(
                "SELECT s.nom AS site_nom, COALESCE(SUM(sr.quantite),0) AS stock
                 FROM op_stock_rivets sr
                 JOIN sites s ON s.id = sr.site_id
                 WHERE s.actif=1 $w
                 GROUP BY s.id, s.nom
                 ORDER BY stock ASC
                 LIMIT 10", $args);
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucun stock de rivets enregistré.'); return; }
            $sc = count($d) > 5;
            if ($sc) echo '<div style="max-height:260px;overflow-y:auto;padding-right:2px">';
            foreach ($d as $r) {
                $q   = (float)$r['stock'];
                $cls = $q < 100 ? 'hc-red' : ($q < 500 ? 'hc-orange' : 'hc-green');
                echo '<div class="stock-stat-row">'
                   . '<div style="font-weight:700;color:#06033A">' . h($r['site_nom']) . '</div>'
                   . '<div style="display:flex;align-items:center;gap:10px">'
                   . '<span class="stock-val">' . ent($q) . '</span>'
                   . '<span class="hc-badge ' . $cls . '" style="margin:0">'
                   . ($q < 100 ? 'À commander' : ($q < 500 ? 'À surveiller' : 'Suffisant'))
                   . '</span></div></div>';
            }
            if ($sc) echo '</div>';
        },
    ],

    // ── Interventions du mois ─────────────────────────────────────────
    'interventions' => [
        'titre'     => 'Interventions du mois',
        'soustitre' => 'Maintenance par site',
        'module'    => 'interventions',
        'lien'      => ['/pages/interventions.php', 'Les interventions'],
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 's.id');
            return db_fetch_all(
                "SELECT s.nom AS site_nom, COUNT(im.id) AS nb
                 FROM sites s
                 LEFT JOIN interventions_maintenance im ON im.site_id = s.id
                      AND YEAR(im.date_intervention)  = YEAR(CURRENT_DATE)
                      AND MONTH(im.date_intervention) = MONTH(CURRENT_DATE)
                 WHERE s.actif=1 $w
                 GROUP BY s.id, s.nom
                 HAVING COUNT(im.id) > 0
                 ORDER BY nb DESC
                 LIMIT 10", $args);
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucune intervention ce mois-ci.', true); return; }
            $max = max(array_map(fn($r) => (float)$r['nb'], $d)) ?: 1;
            foreach ($d as $r) {
                $n = (float)$r['nb'];
                echo '<div class="biz-cov-r">'
                   . '<div class="biz-cov-n">' . h($r['site_nom']) . '</div>'
                   . '<div class="biz-cov-bar"><div class="biz-cov-f' . ($n >= 5 ? ' low' : '')
                   . '" style="width:' . round($n / $max * 100) . '%"></div></div>'
                   . '<div class="biz-cov-d"><span class="biz-cov-num">' . ent($n) . '</span></div></div>';
            }
        },
    ],

    // ── Consommation par site ─────────────────────────────────────────
    'conso_sites' => [
        'titre'     => 'Consommation par site',
        'soustitre' => 'Douze derniers mois, en FCFA',
        'module'    => 'consommables',
        'lien'      => ['/pages/articles.php', 'Les consommables'],
        'donnees'   => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'lc.site_id');
            return db_fetch_all(
                "SELECT s.nom AS site_nom, COALESCE(SUM(lc.prix_total),0) AS montant
                 FROM livraisons_consommables lc
                 JOIN sites s ON s.id = lc.site_id
                 WHERE lc.date_livraison >= (CURRENT_DATE - INTERVAL 12 MONTH) $w
                 GROUP BY s.id, s.nom
                 HAVING COALESCE(SUM(lc.prix_total),0) > 0
                 ORDER BY montant DESC
                 LIMIT 10", $args);
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucune livraison valorisée sur la période.'); return; }
            $max   = max(array_map(fn($r) => (float)$r['montant'], $d)) ?: 1;
            $total = array_sum(array_map(fn($r) => (float)$r['montant'], $d));
            echo '<div class="eq-big-l">Total sur douze mois</div>';
            echo '<div class="biz-m-val" style="font-size:30px">' . ent($total)
               . '<span class="biz-m-u">FCFA</span></div>';
            $sc = count($d) > 4;
            echo '<div style="margin-top:14px' . ($sc ? ';max-height:224px;overflow-y:auto;padding-right:2px' : '') . '">';
            foreach ($d as $r) {
                echo '<div class="biz-cov-r">'
                   . '<div class="biz-cov-n">' . h($r['site_nom']) . '</div>'
                   . '<div class="biz-cov-bar"><div class="biz-cov-f" style="width:'
                   . round((float)$r['montant'] / $max * 100) . '%"></div></div>'
                   . '<div class="biz-cov-d"><span class="biz-cov-num">' . ent((float)$r['montant'])
                   . '</span></div></div>';
            }
            echo '</div>';
        },
    ],

    // ── Synthèse KPI — bloc adaptatif selon les permissions ─────────
    'synthese_kpi' => [
        'titre'    => 'Synthèse',
        'soustitre'=> 'Indicateurs clés — aujourd\'hui',
        'module'   => null,
        'largeur'  => 'plein',
        'lien'     => ['/pages/resume_superviseur.php', 'Vue complète'],
        'lien_module' => 'import_emuci',
        'lien_droit'  => 'can_read',
        'donnees'  => function (array $p) {
            $today = date('Y-m-d');
            [$ws, $as] = dash_filtre_site($p, 'site_id');
            $d = [];
            if (can('operations', 'can_read')) {
                $d['plaques']     = (int)db_fetch_value("SELECT COALESCE(SUM(total_plaques),0)   FROM op_points_journaliers WHERE date_point=? AND statut='valide' AND type_point IN ('point_18h','historique') $ws", array_merge([$today], $as));
                $d['engins']      = (int)db_fetch_value("SELECT COALESCE(SUM(total_engins),0)    FROM op_points_journaliers WHERE date_point=? AND statut='valide' AND type_point IN ('point_18h','historique') $ws", array_merge([$today], $as));
                $d['rivets_j']    = (int)db_fetch_value("SELECT COALESCE(SUM(rivets_utilises),0) FROM op_points_journaliers WHERE date_point=? AND statut='valide' AND type_point IN ('point_18h','historique') $ws", array_merge([$today], $as));
                $d['pts_valides'] = (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND statut='valide' $ws",                   array_merge([$today], $as));
                $d['pts_total']   = (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND statut IN ('valide','en_attente_validation') $ws", array_merge([$today], $as));
                $d['pts_attente'] = (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND statut='en_attente_validation' $ws",    array_merge([$today], $as));
            }
            if (can('import_emuci', 'can_read')) {
                $in_use = (int)db_fetch_value("SELECT COUNT(*) FROM import_optoplate WHERE date_import=? AND statut_plaque='in_use' $ws", array_merge([$today], $as));
                $d['in_use'] = $in_use;
                $d['ecart']  = $in_use - ($d['plaques'] ?? 0);
            }
            if (can('bobines', 'can_read')) {
                [$wb, $ab] = dash_filtre_site($p, 'site_id');
                $d['bobines']       = (int)db_fetch_value("SELECT COUNT(*) FROM op_bobines WHERE statut IN ('en_cours','en_stock') $wb", $ab);
                $d['films']         = (int)db_fetch_value("SELECT COALESCE(SUM(films_restants),0) FROM op_bobines WHERE statut IN ('en_cours','en_stock') $wb", $ab);
                $d['bob_critiques'] = (int)db_fetch_value("SELECT COUNT(*) FROM op_bobines WHERE statut='en_cours' AND films_restants < 50 $wb", $ab);
            }
            if (can('validation_stock', 'can_read') || can('inventaire_bobines', 'can_read')) {
                $d['corrections'] = (int)db_fetch_value("SELECT COUNT(*) FROM corrections_bobines WHERE statut='en_attente' $ws", $as);
            }
            if (can('commandes_bobines', 'can_read')) {
                $d['commandes'] = (int)db_fetch_value("SELECT COUNT(*) FROM commandes_bobines WHERE statut IN ('en_attente','valide') $ws", $as);
            }
            if (can('rivets', 'can_read')) {
                [$wr, $ar] = dash_filtre_site($p, 'sr.site_id');
                $d['rivets_stock'] = (int)db_fetch_value("SELECT COALESCE(SUM(sr.quantite),0) FROM op_stock_rivets sr JOIN sites s ON s.id=sr.site_id WHERE s.actif=1 $wr", $ar);
            }
            if (can('equipements', 'can_read')) {
                [$we, $ae] = dash_filtre_site($p, 'site_id');
                [$wc, $ac] = dash_filtre_categorie($p, 'categorie');
                $d['equip']     = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 $we $wc", array_merge($ae, $ac));
                $d['equip_fin'] = (int)db_fetch_value("SELECT COUNT(*) FROM equipements WHERE actif=1 AND date_fin_cycle IS NOT NULL AND date_fin_cycle <= (CURRENT_DATE + INTERVAL 30 DAY) $we $wc", array_merge($ae, $ac));
            }
            return $d;
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucun indicateur disponible.'); return; }

            // Deux familles plutôt qu'une suite. À gauche ce qui a été produit
            // aujourd'hui et ce qu'il reste à valider dessus ; à droite ce avec
            // quoi on produit. Les corrections en attente vont à la production :
            // c'est une demande portant sur un point du jour, au même titre
            // qu'une validation en attente, pas un état du stock.
            //
            // Chaque indicateur déclare sa famille au lieu d'être posé dans une
            // liste unique. Le bloc suit donc les permissions du profil sans
            // avoir à les connaître : une famille que personne n'a le droit de
            // voir se vide, et disparaît d'elle-même.
            $prod = [];
            $stock = [];
            // #1B75BC sur #dbeafe ne donne que 3,99:1 : sous le seuil AA de 4,5
            // qui s'applique au libellé de 11 px. #15568B, le bleu de lien déjà
            // employé par la feuille (.dash-lien), monte à 6,29:1 sans ajouter
            // une teinte de plus à la palette.
            if (isset($d['plaques']))
                $prod[] = ['val' => fmt_number($d['plaques'], 0), 'lbl' => 'Plaques posées',    'col' => '#15568B', 'bg' => '#dbeafe'];
            if (isset($d['engins']))
                $prod[] = ['val' => fmt_number($d['engins'], 0),  'lbl' => 'Engins traités',    'col' => '#06033A', 'bg' => '#f0f4ff'];
            if (isset($d['rivets_j']))
                $prod[] = ['val' => fmt_number($d['rivets_j'], 0),'lbl' => 'Rivets utilisés',   'col' => '#6d28d9', 'bg' => '#f3e8ff'];
            if (isset($d['in_use']))
                $prod[] = ['val' => fmt_number($d['in_use'], 0),  'lbl' => 'In Use EMUCI',      'col' => '#92400e', 'bg' => '#fef3c7'];
            if (isset($d['ecart'])) {
                $e = $d['ecart'];
                $prod[] = ['val' => ($e > 0 ? '+' : '') . $e,    'lbl' => 'Écart EMUCI / PJ',  'col' => ($e === 0 ? '#166534' : '#991b1b'), 'bg' => ($e === 0 ? '#dcfce7' : '#fee2e2')];
            }
            if (isset($d['pts_valides'])) {
                $tot = $d['pts_total'] ?? $d['pts_valides'];
                $ok  = $tot > 0 && $d['pts_valides'] >= $tot;
                $prod[] = ['val' => $d['pts_valides'] . ' / ' . $tot, 'lbl' => 'Points validés', 'col' => ($ok ? '#166534' : '#92400e'), 'bg' => ($ok ? '#dcfce7' : '#fef3c7')];
            }
            if (isset($d['pts_attente'])) {
                $pa = $d['pts_attente'];
                $prod[] = ['val' => fmt_number($pa, 0), 'lbl' => 'En attente valid.', 'col' => ($pa > 0 ? '#92400e' : '#166534'), 'bg' => ($pa > 0 ? '#fef3c7' : '#dcfce7')];
            }
            if (isset($d['corrections'])) {
                $c = $d['corrections'];
                $prod[] = ['val' => fmt_number($c, 0), 'lbl' => 'Corrections att.', 'col' => ($c > 0 ? '#92400e' : '#166534'), 'bg' => ($c > 0 ? '#fef3c7' : '#dcfce7')];
            }
            if (isset($d['bobines']))
                $stock[] = ['val' => fmt_number($d['bobines'], 0),      'lbl' => 'Bobines actives',  'col' => '#0369a1', 'bg' => '#e0f2fe'];
            if (isset($d['films']))
                $stock[] = ['val' => fmt_number($d['films'], 0),        'lbl' => 'Films restants',   'col' => '#0369a1', 'bg' => '#f0f9ff'];
            if (isset($d['bob_critiques']) && $d['bob_critiques'] > 0)
                $stock[] = ['val' => fmt_number($d['bob_critiques'], 0),'lbl' => 'Bobines critiques','col' => '#991b1b', 'bg' => '#fee2e2'];
            if (isset($d['rivets_stock'])) {
                $sr = $d['rivets_stock'];
                $stock[] = ['val' => fmt_number($sr, 0), 'lbl' => 'Stock rivets', 'col' => ($sr < 100 ? '#991b1b' : ($sr < 500 ? '#92400e' : '#166534')), 'bg' => ($sr < 100 ? '#fee2e2' : ($sr < 500 ? '#fef3c7' : '#dcfce7'))];
            }
            if (isset($d['commandes'])) {
                $ca = $d['commandes'];
                $stock[] = ['val' => fmt_number($ca, 0), 'lbl' => 'Commandes à servir', 'col' => ($ca > 0 ? '#15568B' : '#166534'), 'bg' => ($ca > 0 ? '#dbeafe' : '#dcfce7')];
            }
            if (isset($d['equip']))
                $stock[] = ['val' => fmt_number($d['equip'], 0),     'lbl' => 'Équipements actifs', 'col' => '#374151', 'bg' => '#f3f4f6'];
            if (isset($d['equip_fin']) && $d['equip_fin'] > 0)
                $stock[] = ['val' => fmt_number($d['equip_fin'], 0), 'lbl' => 'Fin cycle ≤ 30j',    'col' => '#991b1b', 'bg' => '#fee2e2'];

            $familles = [];
            if ($prod)  $familles[] = ['Production du jour', $prod];
            if ($stock) $familles[] = ['Stock & moyens',     $stock];
            if (!$familles) { dash_vide('Aucun indicateur disponible pour ce profil.'); return; }

            echo '<div class="syn">';
            foreach ($familles as [$titre, $liste]) {
                echo '<div>';
                echo '<div class="syn-hd"><span class="syn-t">' . h($titre) . '</span></div>';
                echo '<div class="syn-grid">';
                foreach ($liste as $k) {
                    echo '<div class="syn-k" style="background:' . $k['bg'] . '">'
                       . '<div class="syn-v" style="color:' . $k['col'] . '">' . h($k['val']) . '</div>'
                       . '<div class="syn-l" style="color:' . $k['col'] . '">' . h($k['lbl']) . '</div>'
                       . '</div>';
                }
                echo '</div></div>';
            }
            echo '</div>';
        },
    ],

    // ── Synthèse du jour — coordinateur de site ──────────────────────
    'synthese_coord' => [
        'titre'    => 'Synthèse du jour',
        'soustitre'=> 'Mon site — aujourd\'hui',
        'module'   => 'operations',
        'largeur'  => 'plein',
        'donnees'  => function (array $p) {
            $today = date('Y-m-d');
            $sid   = $p['site_id'];
            if (!$sid) return null;
            $plaques  = (int)db_fetch_value("SELECT COALESCE(SUM(total_plaques),0)   FROM op_points_journaliers WHERE date_point=? AND site_id=?", [$today, $sid]);
            $engins   = (int)db_fetch_value("SELECT COALESCE(SUM(total_engins),0)    FROM op_points_journaliers WHERE date_point=? AND site_id=?", [$today, $sid]);
            $rivets_j = (int)db_fetch_value("SELECT COALESCE(SUM(rivets_utilises),0) FROM op_points_journaliers WHERE date_point=? AND site_id=?", [$today, $sid]);
            $brouillon= (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND site_id=? AND statut='brouillon'",             [$today, $sid]);
            $soumis   = (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND site_id=? AND statut='en_attente_validation'", [$today, $sid]);
            $valide   = (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND site_id=? AND statut='valide'",                [$today, $sid]);
            $bobines  = (int)db_fetch_value("SELECT COUNT(*) FROM op_bobines WHERE site_id=? AND statut IN ('en_cours','en_stock')", [$sid]);
            $stock_rivets = (int)db_fetch_value("SELECT COALESCE(SUM(quantite),0) FROM op_stock_rivets WHERE site_id=?", [$sid]);
            return compact('plaques','engins','rivets_j','brouillon','soumis','valide','bobines','stock_rivets');
        },
        'rendu' => function ($d) {
            if (!$d) { dash_vide('Site non configuré pour ce compte.'); return; }
            $pt_col = $d['valide'] > 0 ? '#166534' : ($d['soumis'] > 0 ? '#92400e' : '#5a6678');
            $pt_bg  = $d['valide'] > 0 ? '#dcfce7' : ($d['soumis'] > 0 ? '#fef3c7' : '#f1f5f9');
            $rv_col = $d['stock_rivets'] < 100 ? '#dc2626' : ($d['stock_rivets'] < 500 ? '#92400e' : '#166534');
            $rv_bg  = $d['stock_rivets'] < 100 ? '#fee2e2' : ($d['stock_rivets'] < 500 ? '#fef3c7' : '#dcfce7');
            $kpis = [
                ['val' => ent((float)$d['plaques']),      'lbl' => 'Plaques posées',    'col' => '#1B75BC', 'bg' => '#dbeafe'],
                ['val' => ent((float)$d['engins']),       'lbl' => 'Engins traités',    'col' => '#06033A', 'bg' => '#f0f4ff'],
                ['val' => ent((float)$d['rivets_j']),     'lbl' => 'Rivets utilisés',   'col' => '#6d28d9', 'bg' => '#f3e8ff'],
                ['val' => ent((float)$d['bobines']),      'lbl' => 'Bobines actives',   'col' => '#0369a1', 'bg' => '#e0f2fe'],
                ['val' => ent((float)$d['stock_rivets']), 'lbl' => 'Stock rivets',      'col' => $rv_col,   'bg' => $rv_bg],
                ['val' => $d['valide'] . ' / ' . ($d['brouillon'] + $d['soumis'] + $d['valide']),
                          'lbl' => 'Points validés',      'col' => $pt_col,             'bg' => $pt_bg],
            ];
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px">';
            foreach ($kpis as $k) {
                echo '<div style="background:' . $k['bg'] . ';border-radius:12px;padding:14px 16px;min-width:0">'
                   . '<div style="font-size:26px;font-weight:900;color:' . $k['col'] . ';line-height:1;font-variant-numeric:tabular-nums">' . $k['val'] . '</div>'
                   . '<div style="font-size:12px;font-weight:700;color:' . $k['col'] . ';opacity:.75;text-transform:uppercase;letter-spacing:.4px;margin-top:6px;line-height:1.3">' . h($k['lbl']) . '</div>'
                   . '</div>';
            }
            echo '</div>';
        },
    ],

    // ── Synthèse opérationnelle du jour ──────────────────────────────
    'synthese_jour' => [
        'titre'      => 'Synthèse du jour',
        'soustitre'  => 'Indicateurs clés — aujourd\'hui',
        'module'     => 'operations',
        'largeur'    => 'plein',
        'lien'       => ['/pages/resume_superviseur.php', 'Rapport détaillé'],
        'lien_module'=> 'rapports',
        'donnees'    => function (array $p) {
            $today = date('Y-m-d');
            [$w, $args] = dash_filtre_site($p, 'site_id');
            $plaques    = (int)db_fetch_value("SELECT COALESCE(SUM(total_plaques),0)    FROM op_points_journaliers WHERE date_point=? AND statut='valide' AND type_point IN ('point_18h','historique') $w", array_merge([$today], $args));
            $engins     = (int)db_fetch_value("SELECT COALESCE(SUM(total_engins),0)     FROM op_points_journaliers WHERE date_point=? AND statut='valide' AND type_point IN ('point_18h','historique') $w", array_merge([$today], $args));
            $rivets_j   = (int)db_fetch_value("SELECT COALESCE(SUM(rivets_utilises),0)  FROM op_points_journaliers WHERE date_point=? AND statut='valide' AND type_point IN ('point_18h','historique') $w", array_merge([$today], $args));
            $en_attente = (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND statut='en_attente_validation' $w", array_merge([$today], $args));
            $valides    = (int)db_fetch_value("SELECT COUNT(*) FROM op_points_journaliers WHERE date_point=? AND statut='valide' $w", array_merge([$today], $args));
            $in_use     = (int)db_fetch_value("SELECT COUNT(*) FROM import_optoplate WHERE date_import=? AND statut_plaque='in_use' $w", array_merge([$today], $args));
            return compact('plaques', 'engins', 'rivets_j', 'en_attente', 'valides', 'in_use');
        },
        'rendu' => function (array $d) {
            $ecart   = $d['in_use'] - $d['plaques'];
            $e_str   = ($ecart > 0 ? '+' : '') . $ecart;
            $e_col   = $ecart === 0 ? '#166534' : '#991b1b';
            $e_bg    = $ecart === 0 ? '#dcfce7' : '#fee2e2';
            $att_col = $d['en_attente'] > 0 ? '#92400e' : '#166534';
            $att_bg  = $d['en_attente'] > 0 ? '#fef3c7' : '#dcfce7';
            $kpis = [
                ['val' => ent((float)$d['plaques']),    'lbl' => 'Plaques posées',        'col' => '#1B75BC', 'bg' => '#dbeafe'],
                ['val' => ent((float)$d['engins']),     'lbl' => 'Engins traités',        'col' => '#06033A', 'bg' => '#f0f4ff'],
                ['val' => ent((float)$d['rivets_j']),   'lbl' => 'Rivets utilisés',       'col' => '#6d28d9', 'bg' => '#f3e8ff'],
                ['val' => ent((float)$d['in_use']),     'lbl' => 'In Use EMUCI',          'col' => '#92400e', 'bg' => '#fef3c7'],
                ['val' => $e_str,                        'lbl' => 'Écart EMUCI / PJ',      'col' => $e_col,    'bg' => $e_bg],
                ['val' => ent((float)$d['valides']),    'lbl' => 'Points validés',        'col' => '#166534', 'bg' => '#dcfce7'],
                ['val' => ent((float)$d['en_attente']), 'lbl' => 'En attente validation', 'col' => $att_col,  'bg' => $att_bg],
            ];
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px">';
            foreach ($kpis as $k) {
                echo '<div style="background:' . $k['bg'] . ';border-radius:12px;padding:14px 16px;min-width:0">'
                   . '<div style="font-size:26px;font-weight:900;color:' . $k['col'] . ';line-height:1;font-variant-numeric:tabular-nums">' . $k['val'] . '</div>'
                   . '<div style="font-size:12px;font-weight:700;color:' . $k['col'] . ';opacity:.75;text-transform:uppercase;letter-spacing:.4px;margin-top:6px;line-height:1.3">' . h($k['lbl']) . '</div>'
                   . '</div>';
            }
            echo '</div>';
        },
    ],

    // ── Performance mensuelle par site ────────────────────────────────
    'performance_mois' => [
        'titre'      => 'Performance du mois',
        'soustitre'  => 'Mois en cours — taux de validation par site',
        'module'     => 'operations',
        'largeur'    => 'plein',
        'lien'       => ['/pages/resume_superviseur.php?vue=mensuel', 'Rapport mensuel'],
        'lien2'      => ['/pages/resume_superviseur.php?vue=annuel',  'Annuel'],
        'lien_module'=> 'rapports',
        'donnees'    => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'p.site_id');
            return db_fetch_all(
                "SELECT s.nom AS site_nom,
                        COUNT(*) AS nb_points,
                        SUM(CASE WHEN p.statut='valide' THEN 1 ELSE 0 END) AS points_valides,
                        SUM(p.total_plaques)   AS total_plaques,
                        SUM(p.total_engins)    AS total_engins,
                        SUM(p.rivets_utilises) AS rivets_utilises,
                        AVG(NULLIF(p.moyenne_prod,0)) AS moy_vh
                 FROM op_points_journaliers p
                 JOIN sites s ON s.id = p.site_id
                 WHERE DATE_FORMAT(p.date_point,'%Y-%m') = ? AND p.statut = 'valide' AND p.type_point IN ('point_18h','historique') $w
                 GROUP BY p.site_id, s.nom
                 ORDER BY total_plaques DESC
                 LIMIT 12",
                array_merge([date('Y-m')], $args)
            );
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucune donnée ce mois-ci.'); return; }
            echo '<div class="table-wrap"><table class="ptbl"><thead><tr>'
               . '<th style="text-align:left">Site</th>'
               . '<th style="text-align:center">Validés / Total</th>'
               . '<th style="text-align:center">Taux</th>'
               . '<th style="text-align:right">Plaques</th>'
               . '<th style="text-align:right">Engins</th>'
               . '<th style="text-align:right">Rivets</th>'
               . '<th style="text-align:right">Moy. V/H</th>'
               . '</tr></thead><tbody>';
            $tp = 0; $tv = 0; $tpl = 0; $te = 0; $tr = 0;
            foreach ($d as $r) {
                $nb   = (int)$r['nb_points'];
                $val  = (int)$r['points_valides'];
                $taux = $nb > 0 ? round($val / $nb * 100) : 0;
                $col  = $taux >= 90 ? '#166534' : ($taux >= 60 ? '#92400e' : '#dc2626');
                $bg   = $taux >= 90 ? '#dcfce7' : ($taux >= 60 ? '#fef3c7' : '#fee2e2');
                $tp  += $nb;   $tv  += $val;
                $tpl += (int)$r['total_plaques'];
                $te  += (int)$r['total_engins'];
                $tr  += (int)$r['rivets_utilises'];
                echo '<tr>'
                   . '<td style="font-weight:600;color:#06033A">' . h($r['site_nom']) . '</td>'
                   . '<td style="text-align:center">' . $val . ' / ' . $nb . '</td>'
                   . '<td style="text-align:center"><span style="background:' . $bg . ';color:' . $col . ';border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700">' . $taux . ' %</span></td>'
                   . '<td style="text-align:right;font-weight:700;color:#1B75BC">' . ent((float)$r['total_plaques']) . '</td>'
                   . '<td style="text-align:right">' . ent((float)$r['total_engins']) . '</td>'
                   . '<td style="text-align:right">' . ent((float)$r['rivets_utilises']) . '</td>'
                   . '<td style="text-align:right;color:#5a6678">' . number_format((float)($r['moy_vh'] ?? 0), 1) . ' V/H</td>'
                   . '</tr>';
            }
            $tt    = $tp > 0 ? round($tv / $tp * 100) : 0;
            $col_t = $tt >= 90 ? '#166534' : ($tt >= 60 ? '#92400e' : '#dc2626');
            $bg_t  = $tt >= 90 ? '#dcfce7' : ($tt >= 60 ? '#fef3c7' : '#fee2e2');
            echo '<tr style="border-top:2px solid var(--border);background:var(--bg,#f8fafc)">'
               . '<td style="font-weight:800;color:#06033A">TOTAL</td>'
               . '<td style="text-align:center;font-weight:700">' . $tv . ' / ' . $tp . '</td>'
               . '<td style="text-align:center"><span style="background:' . $bg_t . ';color:' . $col_t . ';border-radius:20px;padding:2px 10px;font-size:12px;font-weight:800">' . $tt . ' %</span></td>'
               . '<td style="text-align:right;font-weight:800;color:#1B75BC">' . ent((float)$tpl) . '</td>'
               . '<td style="text-align:right;font-weight:700">' . ent((float)$te) . '</td>'
               . '<td style="text-align:right;font-weight:700">' . ent((float)$tr) . '</td>'
               . '<td style="text-align:right">—</td>'
               . '</tr></tbody></table></div>';
        },
    ],

    // ── Points rejetés récemment ──────────────────────────────────────
    'points_rejetes' => [
        'titre'    => 'Points rejetés',
        'soustitre'=> 'Sept derniers jours',
        'module'   => 'operations',
        'donnees'  => function (array $p) {
            [$w, $args] = dash_filtre_site($p, 'pj.site_id');
            return db_fetch_all(
                "SELECT pj.date_point, pj.type_point, pj.motif_rejet,
                        s.nom AS site_nom,
                        CONCAT(u.prenom, ' ', u.nom) AS auteur
                 FROM op_points_journaliers pj
                 JOIN sites s ON s.id = pj.site_id
                 LEFT JOIN users u ON u.id = pj.created_by
                 WHERE pj.statut = 'rejete'
                   AND pj.date_point >= (CURRENT_DATE - INTERVAL 7 DAY) $w
                 ORDER BY pj.date_point DESC
                 LIMIT 6",
                $args
            );
        },
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucun point rejeté cette semaine.', true); return; }
            $sc = count($d) > 3;
            if ($sc) echo '<div style="max-height:220px;overflow-y:auto;padding-right:2px">';
            foreach ($d as $r) {
                echo '<div class="biz-risk-r" style="margin-bottom:9px">'
                   . '<div class="biz-risk-i" style="background:#fee2e2;color:#991b1b"><i class="ph-duotone ph-x-circle"></i></div>'
                   . '<div class="biz-risk-b">'
                   . '<div class="biz-risk-t">' . h($r['site_nom']) . ' · '
                   . h(fmt_date($r['date_point'], 'd/m')) . ' ' . h(dash_type_point($r['type_point'])) . '</div>'
                   . '<div class="biz-risk-s">' . h($r['motif_rejet'] ?? 'Aucun motif indiqué') . '</div>'
                   . '<div class="biz-risk-s" style="margin-top:2px">Par ' . h($r['auteur'] ?? '—') . '</div>'
                   . '</div></div>';
            }
            if ($sc) echo '</div>';
        },
    ],

    // ── Dernières activités ───────────────────────────────────────────
    // ── Blocs de la maquette v2 ────────────────────────────────────
    //
    //  Trois formes reprises de la maquette : la carte de tête (métrique
    //  large + courbe + bande de répartition), la jauge à objectif, et le
    //  tableau à pastilles d'icône. Ils ne sont routés que vers les profils
    //  v2 ; le rôle « lecteur » ne les voit jamais.

    'v2_hero' => [
        'titre'     => 'Production du mois',
        'soustitre' => 'Engins posés — mois en cours',
        'module'    => 'operations',
        'largeur'   => 'plein',
        'lien'      => ['/pages/operations/point_journalier.php', 'Points journaliers'],
        'donnees'   => function (array $p) {
            [$ws, $as] = dash_filtre_site($p, 'site_id');
            $debut = date('Y-m-01');
            $prev  = date('Y-m-01', strtotime('-1 month'));

            $mois = (float)db_fetch_value(
                "SELECT COALESCE(SUM(total_engins),0) FROM op_points_journaliers
                 WHERE date_point >= ? AND statut = 'valide' AND type_point IN ('point_18h','historique') $ws", array_merge([$debut], $as));
            $moisPrec = (float)db_fetch_value(
                "SELECT COALESCE(SUM(total_engins),0) FROM op_points_journaliers
                 WHERE date_point >= ? AND date_point < ? AND statut = 'valide' AND type_point IN ('point_18h','historique') $ws", array_merge([$prev, $debut], $as));

            // 4 courbes par type de véhicule — trente derniers jours
            $rows = db_fetch_all(
                "SELECT date_point,
                        COALESCE(SUM(nb_vp),0)      AS vp,
                        COALESCE(SUM(nb_camion),0)  AS cam,
                        COALESCE(SUM(nb_semi),0)    AS semi,
                        COALESCE(SUM(nb_moto),0)    AS mo
                 FROM op_points_journaliers
                 WHERE date_point > CURRENT_DATE - INTERVAL 30 DAY AND statut = 'valide' AND type_point IN ('point_18h','historique') $ws
                 GROUP BY date_point ORDER BY date_point", $as);
            $par = [];
            foreach ($rows as $r) $par[(string)$r['date_point']] = $r;
            $s_vp = $s_cam = $s_semi = $s_mo = $lbl = [];
            for ($i = 29; $i >= 0; $i--) {
                $j = date('Y-m-d', strtotime("-$i day"));
                $r = $par[$j] ?? [];
                $s_vp[]   = (float)($r['vp']   ?? 0);
                $s_cam[]  = (float)($r['cam']   ?? 0);
                $s_semi[] = (float)($r['semi']  ?? 0);
                $s_mo[]   = (float)($r['mo']    ?? 0);
                $lbl[]    = date('j', strtotime($j));
            }
            $series = [
                ['label'=>'VP',             'couleur'=>'#2563EB', 'valeurs'=>$s_vp],
                ['label'=>'Camions',        'couleur'=>'#0F8A47', 'valeurs'=>$s_cam],
                ['label'=>'Semi-remorques', 'couleur'=>'#7C3AED', 'valeurs'=>$s_semi],
                ['label'=>'Motos',          'couleur'=>'#B45309', 'valeurs'=>$s_mo],
            ];

            $mix = db_fetch_one(
                "SELECT COALESCE(SUM(nb_vp),0) AS vp, COALESCE(SUM(nb_camion),0) AS cam,
                        COALESCE(SUM(nb_semi),0) AS semi, COALESCE(SUM(nb_moto),0) AS mo
                 FROM op_points_journaliers WHERE date_point >= ? AND statut = 'valide' AND type_point IN ('point_18h','historique') $ws", array_merge([$debut], $as));

            return ['mois'=>$mois,'prec'=>$moisPrec,'series'=>$series,'lbl'=>$lbl,'mix'=>$mix ?: []];
        },
        'rendu' => function (array $d) {
            $tot_mois = array_sum(array_column($d['series'], 'valeurs') ? array_merge(...array_column($d['series'], 'valeurs')) : []);
            if (!$tot_mois && !$d['mois']) {
                dash_vide('Aucun engin posé ce mois-ci.'); return;
            }
            $delta = dash_delta($d['mois'], $d['prec'] ?: null);
            echo '<div class="dv2-hero-row">';
            echo   '<div class="dv2-hero-l">';
            echo     '<div class="dv2-hero-v">' . h(fmt_number($d['mois'])) . '</div>';
            echo     '<div class="dv2-k-ft">';
            if ($delta) echo '<span class="dv2-d ' . $delta['classe'] . '">' . $delta['fleche'] . ' ' . h($delta['texte']) . '</span>';
            echo       '<span class="dv2-k-vs">vs. ' . h(fmt_number($d['prec'])) . ' le mois dernier</span>';
            echo     '</div>';
            echo   '</div>';
            echo   '<div class="dv2-hero-ch">';
            dash_graphe_multi($d['series'], $d['lbl'], 150);
            echo   '</div>';
            echo '</div>';

            $m = $d['mix']; $tot = (float)(($m['vp'] ?? 0) + ($m['cam'] ?? 0) + ($m['semi'] ?? 0) + ($m['mo'] ?? 0));
            if ($tot > 0) {
                $parts = [
                    ['Véhicules particuliers', (float)$m['vp'],   '#2563EB'],
                    ['Camions',                (float)$m['cam'],  '#0F8A47'],
                    ['Semi-remorques',         (float)$m['semi'], '#7C3AED'],
                    ['Motos',                  (float)$m['mo'],   '#B45309'],
                ];
                echo '<div class="dv2-split">';
                foreach ($parts as [$lb, $v, $col]) {
                    $pct = $tot > 0 ? $v / $tot * 100 : 0;
                    echo '<div>';
                    echo   '<div class="dv2-sp-v" style="color:' . $col . '">' . h(fmt_number($v))
                       .   '<span class="dv2-sp-i">' . number_format($pct, 0) . ' %</span></div>';
                    echo   '<div class="dv2-sp-l">' . h($lb) . '</div>';
                    echo   '<div class="dv2-sp-bar"><div class="dv2-sp-fill" style="width:'
                       .     number_format($pct, 1, '.', '') . '%;background:' . $col . '"></div></div>';
                    echo '</div>';
                }
                echo '</div>';
            }
        },
    ],

    'v2_jauge' => [
        'titre'     => 'Taux de validation',
        'soustitre' => 'Points validés sur points saisis — ce mois',
        'module'    => 'operations',
        'largeur'   => 'demi',
        'donnees'   => function (array $p) {
            [$ws, $as] = dash_filtre_site($p, 'site_id');
            $debut = date('Y-m-01');
            $r = db_fetch_one(
                "SELECT SUM(CASE WHEN statut='valide' THEN 1 ELSE 0 END) AS ok,
                        SUM(CASE WHEN statut IN ('valide','en_attente_validation') THEN 1 ELSE 0 END) AS tot
                 FROM op_points_journaliers WHERE date_point >= ? $ws", array_merge([$debut], $as));
            return ['ok'=>(int)($r['ok'] ?? 0), 'tot'=>(int)($r['tot'] ?? 0)];
        },
        'rendu' => function (array $d) {
            if ($d['tot'] === 0) {
                dash_vide('Aucun point saisi ce mois-ci : rien à valider.', true); return;
            }
            $pct    = $d['ok'] / $d['tot'] * 100;
            $cible  = 95;
            $teinte = $pct >= $cible ? '#0F8A47' : ($pct >= 75 ? '#B45309' : '#D32F35');
            echo '<div class="dv2-gauge">';
            dash_graphe('jauge', [$pct], ['couleur'=>$teinte,'cible'=>$cible,'hauteur'=>140,
                'alt'=>'Taux de validation : ' . number_format($pct,0) . ' %, objectif ' . $cible . ' %']);
            echo   '<div class="dv2-g-v" style="color:' . $teinte . '">' . number_format($pct, 0) . ' %</div>';
            echo   '<div class="dv2-g-s">' . h(fmt_number($d['ok'])) . ' point(s) validé(s) sur '
                 .   h(fmt_number($d['tot'])) . ' · objectif ' . $cible . ' %</div>';
            echo '</div>';
        },
    ],

    'activites' => [
        'titre'     => 'Dernières activités',
        'soustitre' => 'Journal des opérations',
        'module'    => 'audit',
        'largeur'   => 'plein',
        'lien'      => ['/pages/admin/audit.php', 'Le journal'],
        'donnees'   => fn() => db_fetch_all(
            "SELECT al.action, al.module, al.description, al.created_at,
                    CONCAT(u.prenom, ' ', u.nom) AS user_nom
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.created_at DESC
             LIMIT 10"),
        'rendu' => function (array $d) {
            if (!$d) { dash_vide('Aucune activité enregistrée.'); return; }
            echo '<div class="table-wrap"><table class="dtbl"><thead><tr><th>Action</th>'
               . '<th>Description</th><th>Par</th><th style="text-align:right">Quand</th>'
               . '</tr></thead><tbody>';
            foreach ($d as $r) {
                $cls = match ($r['action']) {
                    'CREATE'          => 'mvh-g',
                    'DELETE'          => 'mvh-r',
                    'UPDATE'          => 'mvh-o',
                    default           => 'mvh-o',
                };
                echo '<tr><td><span class="mvh ' . $cls . '">' . h($r['action']) . '</span></td>'
                   . '<td>' . h($r['description'] ?? '—') . '</td>'
                   . '<td style="color:#5a6678">' . h($r['user_nom'] ?? 'Système') . '</td>'
                   . '<td style="text-align:right;color:#5a6678;white-space:nowrap">'
                   . h(fmt_datetime($r['created_at'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        },
    ],

    ];
}

/**
 * Affiche un bloc. Une requête ou un rendu qui échoue laisse une carte
 * explicite au lieu de faire tomber la page entière : un tableau de bord
 * amputé d'un bloc reste utilisable, une page blanche non.
 */
function dash_afficher_bloc(array $bloc, array $portee): void {
    try {
        $donnees = ($bloc['donnees'])($portee);
    } catch (Throwable $e) {
        error_log('dashboard : données du bloc ' . $bloc['id'] . ' — ' . $e->getMessage());
        dash_carte_debut($bloc);
        dash_vide('Ces données sont momentanément indisponibles.');
        dash_carte_fin();
        return;
    }

    dash_carte_debut($bloc);
    try {
        ($bloc['rendu'])($donnees, $portee);
    } catch (Throwable $e) {
        error_log('dashboard : rendu du bloc ' . $bloc['id'] . ' — ' . $e->getMessage());
        dash_vide('Affichage indisponible pour ce bloc.');
    }
    dash_carte_fin();
}

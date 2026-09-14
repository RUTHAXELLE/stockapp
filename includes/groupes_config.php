<?php
// ============================================================
//  includes/groupes_config.php — Configuration centralisée des groupes de menus
//  7 groupes — aucune page en double
// ============================================================

define('TOUS_LES_GROUPES', [
    'DASHBOARD','OPERATIONS','BOBINES','STOCK','INVENTAIRE',
    'INFORMATIQUE','RAPPORTS','DEMANDES','ACHATS','ADMINISTRATION'
]);

function _groupes_def(): array {
    return [

        // ── 1. DASHBOARD
        'DASHBOARD' => [
            'icon'        => 'ph-squares-four',
            'titre'       => 'Dashboard',
            'description' => 'Vue d\'ensemble et indicateurs clés de performance',
            'couleur'     => '#06033A',
            'gradient'    => 'linear-gradient(135deg, #06033A 0%, #1B75BC 100%)',
            'first_page'  => 'pages/dashboard.php',
            'nav' => [
                // Le lecteur n'a que la Vue exécutive : le tableau de bord
                // opérationnel ne lui sert pas.
                ['label'=>'Tableau de bord','icon'=>'ph-squares-four',
                 'url'=>'pages/dashboard.php','active_keys'=>['dashboard'],
                 'roles_exclude'=>['lecteur']],
                // n° 07 réunion ERP : visible par tous les comptes, pas
                // réservée au lecteur — second bouton menu vers la même vue
                // que celle du PDG.
                ['label'=>'Vue exécutive','icon'=>'ph-chart-pie-slice',
                 'url'=>'pages/pdg_overview.php','active_keys'=>['pdg_overview']],
            ],
        ],

        // ── 2. STOCK (équipements + articles + réceptions)
        'STOCK' => [
            'icon'        => 'ph-package',
            'titre'       => 'Stock',
            'description' => 'Équipements, articles, affectations et inventaire',
            'couleur'     => '#1B75BC',
            'gradient'    => 'linear-gradient(135deg, #1B75BC 0%, #00AEEF 100%)',
            'first_page'  => 'pages/equipements.php',
            'nav' => [
                ['label'=>'Équipements Informatique',   'icon'=>'ph-monitor',
                 'url'=>'pages/equipements.php?categorie=informatique',
                 'active_keys'=>['equipements','equipements_info'],
                 'perm'=>['equipements','can_read']],
                ['label'=>'Équipements Opérationnel',   'icon'=>'ph-hard-hat',
                 'url'=>'pages/equipements.php?categorie=operationnel',
                 'active_keys'=>['equipements_op'],
                 'perm'=>['equipements','can_read']],
                ['label'=>'Articles',      'icon'=>'ph-cube',
                 'url'=>'pages/articles.php','active_keys'=>['consommables'],
                 'perm'=>['consommables','can_read']],
                ['label'=>'Historique des mouvements', 'icon'=>'ph-clipboard-text',
                 'url'=>'pages/mouvements_equipements.php','active_keys'=>['mouvements_equipements'],
                 'perm'=>['affectations','can_read'],
                 'roles_exclude'=>['coordinateur_site']],
                ['label'=>'PMMA',      'icon'=>'ph-printer',
                 'url'=>'pages/pmma.php','active_keys'=>['pmma'],
                 'perm'=>['pmma','can_read']],
                // perm plutôt que roles_include : pages/operations/bobines.php
                // appelle désormais require_permission('bobines','can_read')
                // (audit permissions du 2026-08-29 — l'ancienne liste en dur
                // ignorait totalement la table permissions). gestionnaire_stock
                // reste exclu via can_read=0 en base (cf. sql/migration_
                // bobines_permission_alignement.sql), pas via roles_exclude ici.
                ['label'=>'Bobines',   'icon'=>'ph-film-strip',
                 'url'=>'pages/operations/bobines.php','active_keys'=>['bobines'],
                 'perm'=>['bobines','can_read']],
                // Meme page que "Bobines" (?categorie=vignette), meme droit —
                // Reservoir/Pare-brise plutot que Auto/Carre/Moto/MotoII.
                ['label'=>'Vignette',  'icon'=>'ph-sticker',
                 'url'=>'pages/operations/bobines.php?categorie=vignette','active_keys'=>['bobines_vignette'],
                 'perm'=>['bobines','can_read']],
                ['label'=>'Rivets',    'icon'=>'ph-nut',
                 'url'=>'pages/operations/rivets.php','active_keys'=>['rivets'],
                 'perm'=>['rivets','can_read']],
                ['label'=>'Commandes', 'icon'=>'ph-shopping-cart',
                 'url'=>'pages/commandes.php','active_keys'=>['commandes'],
                 'perm'=>['commandes','can_read']],
            ],
        ],

        // ── 3. BOBINES (module dédié — était dispersé dans STOCK + OPERATIONS)
        'BOBINES' => [
            'icon'        => 'ph-film-strip',
            'titre'       => 'Bobines',
            'description' => 'Stock bobines, validation matin, PMMA, rivets et imports',
            'couleur'     => '#0D5C8A',
            'gradient'    => 'linear-gradient(135deg, #0D5C8A 0%, #1B75BC 100%)',
            'first_page'  => 'pages/operations/bobines.php',
            'nav' => [
                ['label'=>'Commande bobines',     'icon'=>'ph-shopping-cart',
                 'url'=>'pages/commandes_bobines.php','active_keys'=>['commandes_bobines'],
                 'perm'=>['commandes_bobines','can_read']],
                // perm + bypass, réplique exactement le gate à 3 conditions de
                // pages/validation_stock_matin.php : can('validation_stock',
                // 'can_create') || is_support_it_with('gestionnaire_bobines')
                // || $is_coord (audit permissions du 2026-08-29 — l'ancien
                // roles_exclude ne reflétait pas la logique réelle de la page).
                ['label'=>'Validation stock jour','icon'=>'ph-seal-check',
                 'url'=>'pages/validation_stock_matin.php','active_keys'=>['validation_stock_matin'],
                 'perm'=>['validation_stock','can_create'],
                 'ou_role'=>['coordinateur_site'],
                 'ou_support_it_sous_role'=>'gestionnaire_bobines'],
                // perm plutôt que roles_include : pages/rapports_gsb.php
                // n'a qu'un require_permission('rapports_gsb','can_read'), la
                // liste en dur ici correspondait déjà exactement aux rôles
                // ayant can_read=1 en base (audit permissions du 2026-08-29).
                ['label'=>'Rapports & Exports',   'icon'=>'ph-file-arrow-down',
                 'url'=>'pages/rapports_gsb.php','active_keys'=>['rapports_gsb'],
                 'perm'=>['rapports_gsb','can_read']],
                // perm plutôt que roles_exclude : pages/stock_bobines_vue.php
                // n'a qu'un require_permission('stock_bobines','can_read') —
                // cf. sql/migration_stock_bobines_rapports_gsb_alignement.sql
                // pour l'alignement de coordinateur_site en base (audit
                // permissions du 2026-08-29).
                ['label'=>'Vue stock par site',   'icon'=>'ph-table',
                 'url'=>'pages/stock_bobines_vue.php','active_keys'=>['stock_bobines_vue'],
                 'perm'=>['stock_bobines','can_read']],
            ],
        ],

        // ── 3bis. INVENTAIRE (module dédié — sorti de BOBINES le 2026-08-28,
        //         demande utilisateur : sessions + inventaire + écarts pour
        //         les 4 commodités partageant le même mécanisme, cf.
        //         includes/inventaire.php)
        'INVENTAIRE' => [
            'icon'        => 'ph-clipboard-text',
            'titre'       => 'Inventaire',
            'description' => 'Sessions et suivi des inventaires physiques — bobines, rivets, PMMA, équipements',
            'couleur'     => '#7C3AED',
            'gradient'    => 'linear-gradient(135deg, #7C3AED 0%, #A78BFA 100%)',
            'first_page'  => 'pages/inventaire_sessions.php',
            'nav' => [
                // n° 19 réunion ERP : réservé au responsable des sessions
                // (admin/superadmin ou délégation, cf. can() et Délégations).
                // Placée avant les 4 paires Inventaire/Écarts : c'est l'écran
                // qui pilote (ouverture/clôture) les quatre à la fois.
                ['label'=>"Sessions d'inventaire", 'icon'=>'ph-calendar-check',
                 'url'=>'pages/inventaire_sessions.php','active_keys'=>['inventaire_sessions'],
                 'perm'=>['inventaire_sessions','can_read']],
                // perm plutôt que roles_include : sinon le droit 'inventaire'
                // (visibilité du groupe) ne suffit jamais à faire apparaître
                // cet écran pour un rôle qui n'était pas dans la liste en dur,
                // même avec inventaire_bobines.can_read=1 en base (trouvé en
                // activant 'inventaire' pour superviseur_it, 2026-08-29).
                ['label'=>'Inventaire bobines',   'icon'=>'ph-clipboard-text',
                 'url'=>'pages/inventaire_bobines.php','active_keys'=>['inventaire_bobines'],
                 'perm'=>['inventaire_bobines','can_read']],
                ['label'=>'Écarts bobines',       'icon'=>'ph-warning-diamond',
                 'url'=>'pages/ecarts_bobines.php','active_keys'=>['ecarts_bobines'],
                 'perm'=>['ecarts_bobines','can_read']],
                ['label'=>'Inventaire rivets', 'icon'=>'ph-clipboard-text',
                 'url'=>'pages/inventaire_rivets.php','active_keys'=>['inventaire_rivets'],
                 'perm'=>['inventaire_rivets','can_read']],
                ['label'=>'Écarts rivets', 'icon'=>'ph-warning-diamond',
                 'url'=>'pages/ecarts_rivets.php','active_keys'=>['ecarts_rivets'],
                 'perm'=>['ecarts_rivets','can_read']],
                ['label'=>'Inventaire PMMA', 'icon'=>'ph-clipboard-text',
                 'url'=>'pages/inventaire_pmma.php','active_keys'=>['inventaire_pmma'],
                 'perm'=>['inventaire_pmma','can_read']],
                ['label'=>'Écarts PMMA', 'icon'=>'ph-warning-diamond',
                 'url'=>'pages/ecarts_pmma.php','active_keys'=>['ecarts_pmma'],
                 'perm'=>['ecarts_pmma','can_read']],
                ['label'=>'Inventaire équipements', 'icon'=>'ph-clipboard-text',
                 'url'=>'pages/inventaire_equipements.php','active_keys'=>['inventaire_equipements'],
                 'perm'=>['inventaire_equipements','can_read']],
                ['label'=>'Écarts équipements', 'icon'=>'ph-warning-diamond',
                 'url'=>'pages/ecarts_equipements.php','active_keys'=>['ecarts_equipements'],
                 'perm'=>['ecarts_equipements','can_read']],
            ],
        ],

        // ── 4. OPERATIONS (terrain quotidien — PRODUCTION fusionné ici)
        'OPERATIONS' => [
            'icon'        => 'ph-lightning',
            'titre'       => 'Opérations',
            'description' => 'Point journalier, commandes terrain, EMUCI et résumés',
            'couleur'     => '#00629B',
            'gradient'    => 'linear-gradient(135deg, #00629B 0%, #00AEEF 100%)',
            'first_page'  => 'pages/operations/point_journalier.php',
            'nav' => [
                ['label'=>'Point journalier',       'icon'=>'ph-clipboard-text',
                 'url'=>'pages/operations/point_journalier.php','active_keys'=>['operations'],
                 'perm'=>['operations','can_read']],
                ['label'=>'Demande d\'intervention','icon'=>'ph-warning-circle',
                 'url'=>'pages/interventions.php','active_keys'=>['interventions'],
                 'perm'=>['interventions','can_read'],
                 'roles_exclude'=>['coordinateur_site']],
                // Commandes dans OPERATIONS uniquement pour les profils sans STOCK
                ['label'=>'Commandes',              'icon'=>'ph-shopping-cart',
                 'url'=>'pages/commandes.php','active_keys'=>['commandes'],
                 'perm'=>['commandes','can_read'],
                 'roles_include'=>['superviseur_operation','gestionnaire_operation']],
                // EMUCI : réservé aux profils de supervision, pas au coordinateur
                ['label'=>'Point EMUCI',            'icon'=>'ph-chart-scatter',
                 'url'=>'pages/point_emuci.php','active_keys'=>['point_emuci'],
                 'perm'=>['point_emuci','can_read'],
                 'roles_exclude'=>['coordinateur_site']],
                ['label'=>'Import EMUCI',           'icon'=>'ph-upload-simple',
                 'url'=>'pages/import_emuci.php','active_keys'=>['import_emuci'],
                 'perm'=>['import_emuci','can_read'],
                 'roles_exclude'=>['coordinateur_site']],
            ],
        ],

        // ── 5. INFORMATIQUE (maintenance IT)
        'INFORMATIQUE' => [
            'icon'        => 'ph-desktop-tower',
            'titre'       => 'Informatique',
            'description' => 'Interventions maintenance, rapports IT et affectations support',
            'couleur'     => '#06033A',
            'gradient'    => 'linear-gradient(135deg, #06033A 0%, #3B4FBE 100%)',
            'first_page'  => 'pages/interventions.php',
            'nav' => [
                ['label'=>'Interventions',    'icon'=>'ph-wrench',
                 'url'=>'pages/interventions.php','active_keys'=>['interventions'],
                 'perm'=>['interventions','can_read']],
                ['label'=>'Rapport journalier','icon'=>'ph-file-text',
                 'url'=>'pages/rapport_journalier.php','active_keys'=>['rapport_journalier'],
                 'perm'=>['rapport_journalier','can_read']],
                ['label'=>'Affectations IT',  'icon'=>'ph-arrows-left-right',
                 'url'=>'pages/affectations_it.php','active_keys'=>['affectations_it'],
                 'perm'=>['affectations_it','can_read']],
                // Deplace depuis STOCK et renomme (2026-08-28) — meme page/perm,
                // juste un nouvel emplacement dans le menu.
                ['label'=>'Transfert équipement',  'icon'=>'ph-arrows-left-right',
                 'url'=>'pages/affectations.php','active_keys'=>['affectations'],
                 'perm'=>['affectations','can_create'],
                 'roles_exclude'=>['coordinateur_site']],
            ],
        ],

        // ── 7. RAPPORTS (analyse et exports)
        'RAPPORTS' => [
            'icon'        => 'ph-chart-bar',
            'titre'       => 'Rapports',
            'description' => 'Résumés superviseur, analyses et exports de données',
            'couleur'     => '#047857',
            'gradient'    => 'linear-gradient(135deg, #047857 0%, #10B981 100%)',
            'first_page'  => 'pages/resume_superviseur.php',
            'nav' => [
                // perm sur un module dédié plutôt que roles_exclude — cf.
                // sql/migration_resume_superviseur_permission_dediee.sql
                // (audit permissions du 2026-08-29).
                ['label'=>'Résumé superviseur','icon'=>'ph-chart-line-up',
                 'url'=>'pages/resume_superviseur.php','active_keys'=>['resume_superviseur'],
                 'perm'=>['resume_superviseur','can_read']],
                // perm ajouté : pages/rapports.php appelle require_permission(
                // 'rapports','can_read') mais l'item n'avait aucun gate ici —
                // sans effet aujourd'hui (les 6 rôles voyant ce groupe ont tous
                // can_read=1 en base), mais protège contre un futur droit
                // désactivé qui laisserait un lien mort (audit permissions du
                // 2026-08-29).
                ['label'=>'Rapports généraux','icon'=>'ph-chart-bar',
                 'url'=>'pages/rapports.php','active_keys'=>['rapports'],
                 'perm'=>['rapports','can_read']],
                ['label'=>'Exports',          'icon'=>'ph-export',
                 'url'=>'pages/export.php','active_keys'=>['export']],
            ],
        ],

        // ── DEMANDES INTERNES (transversal — tous les employés)
        'DEMANDES' => [
            'icon'        => 'ph-file-text',
            'titre'       => 'Demandes internes',
            'description' => 'Demandes administratives dématérialisées et circuits de validation',
            'couleur'     => '#3B4FBE',
            'gradient'    => 'linear-gradient(135deg, #3B4FBE 0%, #7C92FF 100%)',
            'first_page'  => 'pages/demandes.php',
            'nav' => [
                // Le lecteur ne dépose pas de demande : ni la création, ni la
                // liste de ses propres demandes n'ont de sens pour lui. Il ne
                // garde que la file de validation.
                ['label'=>'Mes demandes',    'icon'=>'ph-list-checks',
                 'url'=>'pages/demandes.php','active_keys'=>['demandes'],
                 'perm'=>['demandes','can_read'],
                 'roles_exclude'=>['lecteur']],
                ['label'=>'Nouvelle demande','icon'=>'ph-plus-circle',
                 'url'=>'pages/demandes_new.php','active_keys'=>['demandes_new'],
                 'perm'=>['demandes','can_create'],
                 'roles_exclude'=>['lecteur']],
                ['label'=>'À valider',       'icon'=>'ph-seal-check',
                 'url'=>'pages/demandes_a_valider.php','active_keys'=>['demandes_valider'],
                 'perm'=>['demandes','can_read'],
                 'roles_exclude'=>['coordinateur_site']],
                // roles_include correspond exactement aux rôles ERP dont
                // di_user_roles() inclut 'it' (includes/demandes.php) — accès
                // garanti quel que soit le département. ou_departement_it
                // couvre le cas restant : un rôle hors de cette liste mais
                // rattaché au département lié à di_roles(code='it') passe
                // aussi di_user_can_traiter_it() côté page (audit permissions
                // du 2026-08-29).
                ['label'=>'Traitements IT',  'icon'=>'ph-wrench',
                 'url'=>'pages/demandes_it.php','active_keys'=>['demandes_it'],
                 'perm'=>['demandes','can_read'],
                 'roles_include'=>['admin','superadmin','support_it','superviseur_it','maintenance_info'],
                 'ou_departement_it'=>true],
                ['label'=>'Types & circuits','icon'=>'ph-git-branch',
                 'url'=>'pages/demandes_types.php','active_keys'=>['demandes_types'],
                 'roles_include'=>['admin','superadmin']],
                ['label'=>'Circuits avancés','icon'=>'ph-buildings',
                 'url'=>'pages/demandes_roles.php','active_keys'=>['demandes_roles'],
                 'roles_include'=>['admin','superadmin']],
                ['label'=>'Annuaire agents', 'icon'=>'ph-address-book',
                 'url'=>'pages/agents.php','active_keys'=>['agents'],
                 'perm'=>['agents','can_read']],
            ],
        ],

        // ── ACHATS — transversal (cf. get_groupes_pour_role) : « Mes FEB » et
        //    « Nouvelle FEB » sont ouverts à tout titulaire du droit de
        //    lecture/création sur `achats` (tout le monde sauf le lecteur/PDG,
        //    cf. migration_achats_03_permissions.sql et ach_peut_creer()) ;
        //    les 5 écrans de paramétrage restent filtrés par `achats_param`,
        //    donc invisibles pour la plupart des rôles malgré le groupe visible.
        'ACHATS' => [
            'icon'        => 'ph-shopping-cart',
            'titre'       => 'Achats',
            'description' => 'Expression de besoin, fournisseurs et paramétrage budgétaire',
            'couleur'     => '#B45309',
            'gradient'    => 'linear-gradient(135deg, #B45309 0%, #F59E0B 100%)',
            'first_page'  => 'pages/achats/mes_feb.php',
            'nav' => [
                ['label'=>'Dashboard Achats',      'icon'=>'ph-gauge',
                 'url'=>'pages/achats/dashboard.php','active_keys'=>['achats_dashboard'],
                 'perm'=>['achats_dashboard','can_read']],
                // L'ex-Dashboard Direction est fusionné dans la Vue exécutive
                // (pages/pdg_overview.php) : un seul dashboard pour le PDG.
                ['label'=>'Mes FEB',               'icon'=>'ph-list-checks',
                 'url'=>'pages/achats/mes_feb.php','active_keys'=>['achats_mes_feb'],
                 'perm'=>['achats','can_read']],
                ['label'=>'Nouvelle FEB',          'icon'=>'ph-plus-circle',
                 'url'=>'pages/achats/feb_fiche.php','active_keys'=>['achats_feb_fiche'],
                 'perm'=>['achats','can_read'],
                 'roles_exclude'=>['lecteur']],
                ['label'=>'File d\'attente Achats', 'icon'=>'ph-queue',
                 'url'=>'pages/achats/file_attente.php','active_keys'=>['achats_file_attente'],
                 'perm'=>['achats','can_update'],
                 // can_update sur `achats` est aussi porté par les valideurs
                 // (visa, mes_visas.php) — cf. ach_est_acheteur() dans
                 // includes/achats.php : ils n'ouvrent plus la page (403),
                 // exclus ici en plus pour ne pas leur laisser un lien mort.
                 'roles_exclude'=>['raf','daf','directeur_general','lecteur']],
                ['label'=>'Mes visas',             'icon'=>'ph-signature',
                 'url'=>'pages/achats/mes_visas.php','active_keys'=>['achats_mes_visas'],
                 'perm'=>['achats','can_update'], 'ou_n1'=>true],
                ['label'=>'Suivi Achats (DA/BC)',  'icon'=>'ph-truck',
                 'url'=>'pages/achats/suivi_achats.php','active_keys'=>['achats_suivi'],
                 'perm'=>['achats_suivi','can_read']],
                // can_read, pas can_create : la garde reelle de la page est
                // can('achats_suivi','can_read') || N+1 (cf. $peut_voir_etape3
                // dans pages/achats/receptions.php) - un profil can_read seul
                // (raf/daf/lecteur) voit l'ecran meme sans pouvoir confirmer.
                ['label'=>'Réceptions',            'icon'=>'ph-package',
                 'url'=>'pages/achats/receptions.php','active_keys'=>['achats_receptions'],
                 'perm'=>['achats_suivi','can_read'], 'ou_n1'=>true],
                ['label'=>'Stock par département', 'icon'=>'ph-buildings',
                 'url'=>'pages/achats/stock_departements.php','active_keys'=>['achats_stock_departements'],
                 'perm'=>['achats_suivi','can_read'], 'ou_n1'=>true],
                // Commandes internes issues d'un arbitrage « stock » (cf.
                // ach_basculer_vers_commande()) — pages/commandes.php porte
                // sa propre entrée dans STOCK/OPERATIONS, mais ces groupes
                // sont masqués sur recette-achats (get_groupes_pour_role()
                // n'y renvoie que ACHATS) : sans cet item, le superviseur
                // achat n'avait aucun moyen d'y suivre la commande créée par
                // sa propre bascule.
                ['label'=>'Commandes', 'icon'=>'ph-shopping-cart',
                 'url'=>'pages/commandes.php','active_keys'=>['commandes'],
                 'perm'=>['commandes','can_read'], 'recette_only'=>true],
                ['label'=>"File d'attente équipements", 'icon'=>'ph-desktop-tower',
                 'url'=>'pages/achats/equipements_attente.php','active_keys'=>['achats_equipements_attente'],
                 'perm'=>['achats_suivi','can_read'], 'ou_n1'=>true],
                // RAF/DAF/PDG (lecteur) portent achats_param can_read/can_update
                // uniquement pour le budget (migration_achats_13) : exclus des
                // 4 autres écrans, qui restent à l'administration (même 403
                // côté page, cf. param_fournisseurs.php et consorts).
                ['label'=>'Fournisseurs',          'icon'=>'ph-storefront',
                 'url'=>'pages/achats/param_fournisseurs.php','active_keys'=>['achats_param_fournisseurs'],
                 'perm'=>['achats_param','can_read'],
                 'roles_exclude'=>['raf','daf','lecteur']],
                ['label'=>'Familles & types',      'icon'=>'ph-tag',
                 'url'=>'pages/achats/param_familles.php','active_keys'=>['achats_param_familles'],
                 'perm'=>['achats_param','can_read'],
                 'roles_exclude'=>['raf','daf','lecteur']],
                ['label'=>'Paliers de validation',  'icon'=>'ph-stairs',
                 'url'=>'pages/achats/param_paliers.php','active_keys'=>['achats_param_paliers'],
                 'perm'=>['achats_param','can_read'],
                 'roles_exclude'=>['raf','daf','lecteur']],
                ['label'=>'Lignes budgétaires',     'icon'=>'ph-calculator',
                 'url'=>'pages/achats/param_budget.php','active_keys'=>['achats_param_budget'],
                 'perm'=>['achats_param','can_read']],
                ['label'=>'Paramètres généraux',    'icon'=>'ph-gear',
                 'url'=>'pages/achats/param_general.php','active_keys'=>['achats_param_general'],
                 'perm'=>['achats_param','can_read'],
                 'roles_exclude'=>['raf','daf','lecteur']],
            ],
        ],

        // ── 8. ADMINISTRATION
        'ADMINISTRATION' => [
            'icon'        => 'ph-shield-check',
            'titre'       => 'Administration',
            'description' => 'Utilisateurs, rôles, permissions et configuration système',
            'couleur'     => '#374151',
            'gradient'    => 'linear-gradient(135deg, #374151 0%, #6B7280 100%)',
            'first_page'  => 'pages/admin/users.php',
            'nav' => [
                ['label'=>'Utilisateurs',  'icon'=>'ph-users',
                 'url'=>'pages/admin/users.php','active_keys'=>['users']],
                ['label'=>'Permissions',   'icon'=>'ph-lock-key',
                 'url'=>'pages/admin/permissions.php','active_keys'=>['permissions']],
                ['label'=>'Nomenclatures', 'icon'=>'ph-tag',
                 'url'=>'pages/admin/nomenclatures.php','active_keys'=>['nomenclatures']],
                ['label'=>'Audit',         'icon'=>'ph-shield-check',
                 'url'=>'pages/admin/audit.php','active_keys'=>['audit']],
                ['label'=>'Délégations',   'icon'=>'ph-handshake',
                 'url'=>'pages/admin/delegations.php','active_keys'=>['delegations']],
                ['label'=>'Sites',         'icon'=>'ph-buildings',
                 'url'=>'pages/admin/sites.php','active_keys'=>['sites']],
                ['label'=>'Départements',  'icon'=>'ph-tree-structure',
                 'url'=>'pages/admin/departements.php','active_keys'=>['departements']],
            ],
        ],
    ];
}

// ── Groupes accessibles selon le rôle (9 → 7 groupes, plus de doublons)
function get_groupes_pour_role(string $role_slug): array {
    // Recette Achats : un seul groupe de menus, quel que soit le rôle. Les
    // filtres par item de get_groupe_nav_items() continuent de s'appliquer,
    // donc chacun ne voit que les écrans Achats de son périmètre.
    if (defined('RECETTE_ACHATS') && RECETTE_ACHATS) return ['ACHATS'];

    $all = TOUS_LES_GROUPES;
    $map = [
        // Terrain production
        'coordinateur_site'          => ['DASHBOARD','OPERATIONS','BOBINES','STOCK'],
        // Stock & approvisionnement (accès commandes via OPERATIONS)
        'gestionnaire_stock'         => ['DASHBOARD','OPERATIONS','BOBINES','STOCK'],
        // Supervision opérationnelle
        'superviseur_operation'      => ['DASHBOARD','OPERATIONS','BOBINES','STOCK','RAPPORTS'],
        // IT maintenance
        'maintenance_info'           => ['DASHBOARD','INFORMATIQUE','STOCK'],
        'superviseur_it'             => ['DASHBOARD','INFORMATIQUE','STOCK'],
        'support_it'                 => ['DASHBOARD','INFORMATIQUE'],
        // Achat & approvisionnement (accès commandes via OPERATIONS)
        'superviseur_achat'          => ['DASHBOARD','OPERATIONS','STOCK','ACHATS'],
        // Production (ex-PRODUCTION → OPERATIONS)
        'controleur_production'      => ['DASHBOARD','OPERATIONS','BOBINES'],
        // Opérations
        'gestionnaire_operation'     => ['DASHBOARD','OPERATIONS'],
        // GSB (gestionnaire stock bobines)
        'gestionnaire_stock_bobines' => ['DASHBOARD','OPERATIONS','BOBINES','STOCK'],
        // Lecture seule : uniquement la vue PDG et les demandes à valider.
        // Les droits fins page par page (ex. resume_superviseur.php, qui
        // autorise explicitement 'lecteur') ne changent pas — seule la
        // page d'accueil et la navigation par groupes se resserrent.
        'lecteur'                    => ['DASHBOARD'],
        // RAF / DAF — validation administrative et financière
        'raf'                        => ['DASHBOARD','RAPPORTS'],
        'daf'                        => ['DASHBOARD','RAPPORTS'],
        // Directeur général — porte rapports.can_read/can_export en base
        // (audit permissions du 2026-08-27) mais n'avait aucun groupe pour
        // s'y rendre ; ajouté ici plutôt que laissé en accès fantôme.
        'directeur_general'          => ['DASHBOARD','RAPPORTS'],
        // Admin
        'admin'                      => $all,
        'superadmin'                 => $all,
    ];
    $groupes = $map[$role_slug] ?? ['DASHBOARD'];
    // « Demandes internes » et « Achats » sont transversaux : visibles par
    // tous les rôles. Pour Achats, seuls « Mes FEB »/« Nouvelle FEB »
    // s'affichent réellement pour la plupart (filtrage par item dans
    // get_groupe_nav_items — les écrans de paramétrage restent réservés à
    // achats_param).
    if (!in_array('DEMANDES', $groupes, true)) $groupes[] = 'DEMANDES';
    if (!in_array('ACHATS', $groupes, true)) $groupes[] = 'ACHATS';
    return $groupes;
}

// ── Retourne les groupes accessibles pour l'utilisateur connecté (clé => def)
//    N'inclut un groupe que s'il reste au moins un item de nav visible pour
//    ce rôle une fois les permissions appliquées (get_groupe_nav_items) :
//    sinon la carte "Mes espaces" mène à un groupe entièrement vide, qui
//    retombe sur 'first_page' et finit en 403 (trouvé avec DEMANDES pour un
//    rôle dont can_read a été désactivé sur ce module, 2026-08-28).
function get_groupes_utilisateur(): array {
    $user = current_user();
    if (!$user) return [];
    $slugs = get_groupes_pour_role($user['role_slug'] ?? '');
    // Le groupe INVENTAIRE (sorti de BOBINES le 2026-08-28) a son propre
    // droit en base ('inventaire', can_read) plutôt qu'un rôle en dur dans
    // $map ci-dessus : modifiable depuis Admin → Permissions sans toucher
    // au code. Les filtres par item de get_groupe_nav_items() continuent
    // de s'appliquer en plus (ex. Écarts X reste gardé par ecarts_X).
    if (!in_array('INVENTAIRE', $slugs, true) && can('inventaire', 'can_read')) {
        $slugs[] = 'INVENTAIRE';
    }
    $def   = _groupes_def();
    $result = [];
    foreach ($slugs as $slug) {
        if (!isset($def[$slug])) continue;
        if (empty(get_groupe_nav_items($slug))) continue;
        $result[$slug] = $def[$slug];
    }
    return $result;
}

// ── Retourne la définition d'un groupe par sa clé
function get_groupe_def(string $slug): ?array {
    $def = _groupes_def();
    return $def[$slug] ?? null;
}

// ── Retourne les nav items d'un groupe, filtrés par permissions ET rôle
function get_groupe_nav_items(string $slug): array {
    $def  = get_groupe_def($slug);
    if (!$def) return [];
    $user = current_user();
    $role = $user['role_slug'] ?? '';
    $items = [];
    foreach ($def['nav'] as $item) {
        // Filtre permission DB — sauf pour un item marqué 'ou_n1' : un
        // supérieur hiérarchique (user_departements.is_n1) ouvre la page
        // même sans le droit du module (cf. ach_peut_ouvrir_visas(),
        // includes/achats.php — même règle, dupliquée ici pour ne pas
        // dépendre du chargement de ce fichier sur les pages non-Achats).
        if (!empty($item['perm'])) {
            [$module, $droit] = $item['perm'];
            $autorise = can($module, $droit);
            if (!$autorise && !empty($item['ou_n1']) && $user) {
                $autorise = (bool) db_fetch_value(
                    "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND is_n1=1",
                    [(int)$user['id']]
                );
            }
            // Bypass rôle(s) — réplique une condition OR par rôle du gate réel
            // de la page (ex. pages/validation_stock_matin.php : $is_coord),
            // sans dépendre du droit DB du module.
            if (!$autorise && !empty($item['ou_role']) && in_array($role, (array)$item['ou_role'], true)) {
                $autorise = true;
            }
            // Bypass sous-rôle support_it — réplique is_support_it_with() du
            // gate réel de la page (ex. validation_stock_matin.php : support_it
            // avec le sous-rôle 'gestionnaire_bobines').
            if (!$autorise && !empty($item['ou_support_it_sous_role']) && $role === 'support_it' && $user) {
                $autorise = is_support_it_with($item['ou_support_it_sous_role']);
            }
            if (!$autorise) continue;
        }
        // Filtre rôles exclus (blacklist)
        if (!empty($item['roles_exclude']) && in_array($role, $item['roles_exclude'])) continue;
        // Filtre rôles autorisés (whitelist) — si défini, seuls ces rôles voient l'item
        if (!empty($item['roles_include']) && !in_array($role, $item['roles_include'])) {
            // Bypass département IT — réplique di_user_can_traiter_it()
            // (includes/demandes.php) sans dépendre de son chargement sur les
            // pages hors Demandes, même principe que 'ou_n1' ci-dessus.
            $ou_dept_it = false;
            if (!empty($item['ou_departement_it']) && $user) {
                $dept_it = db_fetch_value("SELECT departement_id FROM di_roles WHERE code='it'");
                $ou_dept_it = $dept_it && (bool) db_fetch_value(
                    "SELECT COUNT(*) FROM user_departements WHERE user_id=? AND departement_id=?",
                    [(int)$user['id'], (int)$dept_it]
                );
            }
            if (!$ou_dept_it) continue;
        }
        // Item ajouté uniquement pour compenser un groupe masqué en mode
        // recette (cf. 'Commandes' dans ACHATS) — inutile et redondant en
        // production, où ce groupe reste accessible normalement.
        if (!empty($item['recette_only']) && !(defined('RECETTE_ACHATS') && RECETTE_ACHATS)) continue;
        $items[] = $item;
    }
    return $items;
}

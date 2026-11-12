<?php
// ============================================================
//  includes/finance_dossiers.php — Finance : suivi des dossiers
//
//  Suit chaque dossier de son ouverture chez CIL jusqu'à la pose de ses
//  plaques, en recoupant six sources :
//    · API CIL (ControlPayement)  ouverture, paiement, montants  → fin_src_cil
//    · export Brique              arrivée du dossier chez EMUCI   → fin_src_brique
//    · export CGI                 enrôlement, Primo/Post/Reimmat  → fin_src_cgi
//    · export Sage X3             pose, réconciliation, facture   → fin_src_x3
//    · export Caisse              encaissement, bon de commande   → fin_src_caisse
//    · export OptoPlate           plaques posées                  → fin_src_pose
//      (+ la table import_optoplate des opérations, si elle existe)
//
//  Chaque import met à jour sa table sans doublon (clé naturelle de la
//  source) ; fin_consolider() reconstruit ensuite fin_dossiers, un dossier
//  par ligne. Clé du dossier : le VIN ; à défaut le chrono CIL ou le n°
//  NSIIV, rattachés au VIN dès qu'une autre source les relie.
//
//  Schéma : sql/migration_finance_01_dossiers.sql.
// ============================================================

require_once __DIR__ . '/db.php';

const FIN_API_PERIODE = 'https://cidata.cilogistique.ci/EmuciServices_prod/getdata/ControlPayement/%s/%s';
// Même environnement (_prod) que la route par période : elle accepte aussi un
// chrono et renvoie le genre, que la route _bus omet.
const FIN_API_CHRONO  = 'https://cidata.cilogistique.ci/EmuciServices_prod/getdata/ControlPayement/%s';

// Dossier payé chez CIL mais toujours pas arrivé chez EMUCI après ce délai :
// signalé « ouvert non reçu ».
const FIN_DELAI_RECEPTION_JOURS = 7;

// Synchronisation automatique de l'API à l'ouverture de l'écran de suivi
// quand la dernière date de plus de ce délai (en heures).
const FIN_SYNC_AUTO_HEURES = 6;

const FIN_SOURCES = [
    'api_cil'   => 'API CIL',
    'brique'    => 'Brique',
    'cgi'       => 'CGI',
    'x3'        => 'X3 (Sage)',
    'caisse'    => 'Caisse',
    'optoplate' => 'OptoPlate',
];

// Table et clé de chaque source importée.
const FIN_CONF = [
    'api_cil'   => ['table' => 'fin_src_cil',    'cle' => 'chrono'],
    'brique'    => ['table' => 'fin_src_brique', 'cle' => 'id_brique'],
    'cgi'       => ['table' => 'fin_src_cgi',    'cle' => 'nsiiv'],
    'x3'        => ['table' => 'fin_src_x3',     'cle' => 'cle_ligne'],
    'caisse'    => ['table' => 'fin_src_caisse', 'cle' => 'id_caisse'],
    'optoplate' => ['table' => 'fin_src_pose',   'cle' => 'cle_ligne'],
];

const FIN_ETAPES = [
    'ouvert'     => 'Ouvert (CIL)',
    'recu'       => 'Reçu',
    'reconcilie' => 'Réconcilié',
    'pose'       => 'Posé',
];

const FIN_CANAUX = ['CIL' => 'CIL', 'DGTTC' => 'DGTTC', 'FDS' => 'FDS', 'REIMMAT' => 'Reimmat', 'SPECIALE' => 'Opération spéciale'];

// Catégorie affichée à la place du genre détaillé, et clé des tarifs (le genre
// détaillé reste stocké). Moto : MOTOCYCLETTE, MOTO, MOTO TRICYCLE ;
// Semi-remorque : SEMI REMORQUE (ROUTIER), REMORQUE ROUTIERE ; tout le reste
// (voiture, camionnette, camion, citerne, tracteur, autocar, chariot…) : Auto.
const FIN_CATEGORIES = ['AUTO' => 'Auto', 'MOTO' => 'Moto', 'SEMI' => 'Semi-remorque'];

const FIN_TYPES = ['Primo', 'Post', 'Reimmat', 'Diplo'];

// Mode d'application d'une formule de services.
const FIN_MODES_FORMULE = ['site' => 'Par site', 'dossier' => 'Par dossier'];

// Seuls les sites ERP de pose ou mixtes servent au suivi des dossiers.
const FIN_SITES_TYPES_SQL = "type IN ('pose', 'mixte')";

function fin_categorie(?string $genre): ?string {
    if ($genre === null || $genre === '') return null;
    if (str_starts_with($genre, 'MOTO')) return 'MOTO';
    if (str_contains($genre, 'REMORQUE')) return 'SEMI';
    return 'AUTO';
}

function fin_categorie_libelle(?string $genre): ?string {
    $c = fin_categorie($genre);
    return $c === null ? null : FIN_CATEGORIES[$c];
}

// Même règle en SQL, pour le filtre.
function fin_categorie_sql(string $col): string {
    return "CASE WHEN COALESCE($col, '') = '' THEN NULL WHEN $col LIKE 'MOTO%' THEN 'MOTO'"
         . " WHEN $col LIKE '%REMORQUE%' THEN 'SEMI' ELSE 'AUTO' END";
}

// Tarifs par catégorie (fin_tarifs.genre = AUTO / MOTO / SEMI ; les anciennes
// lignes par genre détaillé sont ignorées). Opération spéciale : clé
// « #SPECIALE:<catégorie> », sans migration. Sans tarif saisi : valeur par
// défaut ci-dessous, sinon tarif normal de la catégorie.
const FIN_TARIF_SPECIALE_PREFIXE = '#SPECIALE:';
const FIN_TARIF_SPECIALE_DEFAUTS = ['MOTO' => 6300];

function fin_tarif_speciale_cle(string $categorie): string {
    return FIN_TARIF_SPECIALE_PREFIXE . $categorie;
}

// CASE SQL des valeurs par défaut, pour la colonne genre $col.
function fin_tarif_speciale_defaut_sql(string $col): string {
    $w = '';
    foreach (FIN_TARIF_SPECIALE_DEFAUTS as $g => $m) $w .= " WHEN $col = " . get_db()->quote($g) . ' THEN ' . (int)$m;
    return $w === '' ? 'NULL' : "CASE$w END";
}

// Anomalies : colonne booléenne de fin_dossiers => libellé.
const FIN_ANOMALIES = [
    'a_ouvert_non_recu'     => 'Payé chez CIL, pas encore reçu',
    'a_pose_non_reconcilie' => 'Posé, pas encore réconcilié',
    'a_ecart'               => 'Écart de montant',
    'a_recu_sans_paiement'  => 'Reçu CIL sans paiement connu',
    'a_pose_sans_dossier'   => 'Posé sans dossier reçu',
    'a_rejet'               => 'Rejet ou échec d\'enrôlement',
];

// Le module est-il installé (migration finance 01 passée) ?
function fin_module_pret(): bool {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)db_fetch_value("SELECT to_regclass('public.fin_dossiers') IS NOT NULL"); }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// Services et formules installés (migration finance 02) ?
function fin_services_pret(): bool {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)db_fetch_value("SELECT to_regclass('public.fin_formules') IS NOT NULL"); }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// ── Normalisation des valeurs ──────────────────────────────────

// Texte UTF-8 encodé deux fois (« NÂ° CIL », « crÃ©ation ») : l'export
// Caisse arrive ainsi, réenregistré par un outil qui l'a lu en Latin-1.
function fin_utf8(string $s): string {
    if (!str_contains($s, 'Ã') && !str_contains($s, 'Â')) return $s;
    $r = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    return ($r !== false && $r !== $s && mb_check_encoding($r, 'UTF-8')) ? $r : $s;
}

function fin_norm_entete(string $s): string {
    $s = mb_strtolower(trim(fin_utf8($s)), 'UTF-8');
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a','î'=>'i','ï'=>'i',
                    'ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
    return preg_replace('/[^a-z0-9]+/', '', $s);
}

function fin_txt($v, int $max = 250): ?string {
    if ($v === null) return null;
    $v = trim(preg_replace('/\s+/u', ' ', fin_utf8((string)$v)));
    if ($v === '' || strtolower($v) === 'null') return null;
    return mb_substr($v, 0, $max);
}

function fin_maj($v, int $max = 60): ?string {
    $v = fin_txt($v, $max);
    return $v === null ? null : mb_strtoupper($v);
}

function fin_vin($v): ?string {
    $v = strtoupper(preg_replace('/\s+/', '', (string)$v));
    return ($v === '' || $v === 'NULL') ? null : substr($v, 0, 40);
}

// Genre du véhicule, comparable d'une source à l'autre :
// « SEMI-REMORQUE ROUTIER » et « SEMI REMORQUE ROUTIER » donnent la même valeur.
function fin_genre($v): ?string {
    $v = fin_txt($v, 60);
    if ($v === null) return null;
    $v = str_replace(['-', '_'], ' ', mb_strtoupper($v));
    return trim(preg_replace('/\s+/', ' ', $v));
}

function fin_nombre($v): ?float {
    if ($v === null || is_bool($v)) return null;
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim((string)$v));
    if ($s === '' || strtolower($s) === 'null') return null;
    if (str_contains($s, ',') && !str_contains($s, '.')) $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : null;
}

function fin_entier($v): ?int {
    $n = fin_nombre($v);
    return $n === null ? null : (int)round($n);
}

function fin_montant($v): ?string {
    $n = fin_nombre($v);
    return $n === null ? null : number_format($n, 2, '.', '');
}

// Date (ou date et heure) depuis les formats rencontrés dans les exports :
// numéro de série Excel, 20261027, 2026-10-01 08:12:33.0, 14/10/2026…
function fin_date($v, bool $heure = false): ?string {
    if ($v === null || $v === '') return null;
    $fmt = $heure ? 'Y-m-d H:i:s' : 'Y-m-d';
    $s = trim((string)$v);
    if ($s === '' || strtolower($s) === 'null') return null;
    if (preg_match('/^\d{8}$/', $s)) {
        $d = DateTime::createFromFormat('!Ymd', $s);
        return $d ? $d->format($fmt) : null;
    }
    if (is_numeric($s)) {                      // série Excel (jours depuis 1899-12-30)
        $n = (float)$s;
        if ($n < 20000 || $n > 80000) return null;
        $d = new DateTime('1899-12-30 00:00:00');
        $jours = (int)floor($n);
        $sec = (int)round(($n - $jours) * 86400);
        $d->modify("+$jours days")->modify("+$sec seconds");
        return $d->format($fmt);
    }
    $s = preg_replace('/\.\d+$/', '', $s);     // fraction de seconde
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $f) {
        $d = DateTime::createFromFormat('!' . $f, $s);
        if ($d && $d->format($f) === $s) return $d->format($fmt);
    }
    return null;
}

// ── Lecture des fichiers ───────────────────────────────────────

// Lignes d'un .xlsx, lues en continu (ZipArchive + XMLReader) : les exports
// Brique et CGI dépassent 15 000 lignes, PhpSpreadsheet les chargerait en
// entier en mémoire. Chaque ligne : [index de colonne => valeur brute].
function fin_xlsx_lignes(string $chemin): Generator {
    $zip = new ZipArchive();
    if ($zip->open($chemin) !== true) throw new RuntimeException('Fichier Excel illisible.');
    $feuille = 'xl/worksheets/sheet1.xml';
    $wb   = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb && $rels && preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m)
        && preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($m[1], '/') . '"[^>]*\bTarget="([^"]+)"/', $rels, $t)) {
        $cible = ltrim($t[1], '/');
        $feuille = str_starts_with($cible, 'xl/') ? $cible : 'xl/' . $cible;
    }
    if ($zip->locateName($feuille) === false) { $zip->close(); throw new RuntimeException('Feuille introuvable dans le fichier Excel.'); }
    $partages = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    if ($xml !== false) {
        $r = new XMLReader();
        $r->XML($xml);
        $cur = null; $phon = 0;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'si') $cur = '';
                elseif ($r->localName === 'rPh' && !$r->isEmptyElement) $phon++;
                elseif ($r->localName === 't' && $cur !== null && !$phon) $cur .= $r->readString();
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                if ($r->localName === 'si') { $partages[] = $cur; $cur = null; }
                elseif ($r->localName === 'rPh') $phon--;
            }
        }
        $r->close();
        unset($xml);
    }
    $r = new XMLReader();
    if (!$r->open('zip://' . $chemin . '#' . $feuille)) throw new RuntimeException('Feuille Excel illisible.');
    $ligne = null;
    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
            if ($r->isEmptyElement) { yield []; continue; }
            $ligne = [];
        } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row') {
            yield $ligne;
            $ligne = null;
        } elseif ($ligne !== null && $r->nodeType === XMLReader::ELEMENT && $r->localName === 'c') {
            $ref = (string)$r->getAttribute('r');
            $type = (string)$r->getAttribute('t');
            $col = count($ligne);
            if (preg_match('/^([A-Z]+)/', $ref, $mm)) {
                $col = 0;
                foreach (str_split($mm[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col--;
            }
            $val = null;
            if (!$r->isEmptyElement) {
                $n = $r->expand();
                $v = null; $is = null;
                foreach ($n->childNodes as $ch) {
                    if ($ch->nodeName === 'v') $v = $ch->textContent;
                    elseif ($ch->nodeName === 'is') $is = $ch->textContent;
                }
                if ($type === 's')                                $val = $v !== null ? ($partages[(int)$v] ?? null) : null;
                elseif ($type === 'inlineStr')                    $val = $is;
                elseif ($type === 'b')                            $val = $v === '1' ? '1' : '0';
                elseif ($type === 'e')                            $val = null;
                else                                              $val = $v ?? $is;
                // Le curseur reste sur <c> : read() parcourt ensuite <v>/<is>,
                // ignorés par la boucle (pas de next(), qui sauterait la
                // cellule suivante).
            }
            $ligne[$col] = $val;
        }
    }
    $r->close();
}

// Lignes d'un .csv : séparateur deviné (; , tabulation), UTF-8 avec ou sans
// BOM, sinon Windows-1252 converti.
function fin_csv_lignes(string $chemin): Generator {
    $f = fopen($chemin, 'rb');
    if (!$f) throw new RuntimeException('Fichier CSV illisible.');
    $debut = fread($f, 65536);
    rewind($f);
    $premiere = strtok($debut, "\n");
    $sep = ';';
    $max = -1;
    foreach ([';', ',', "\t"] as $c) {
        $n = substr_count((string)$premiere, $c);
        if ($n > $max) { $max = $n; $sep = $c; }
    }
    // Échantillon coupé à la dernière fin de ligne : un caractère accentué
    // tronqué en limite de lecture ne doit pas faire conclure à du Windows-1252.
    $p = strrpos($debut, "\n");
    $utf8 = mb_check_encoding($p ? substr($debut, 0, $p) : $debut, 'UTF-8');
    $prem = true;
    while (($l = fgetcsv($f, 0, $sep, '"', '')) !== false) {
        if ($prem) { $l[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$l[0]); $prem = false; }
        if (!$utf8) $l = array_map(fn($c) => $c === null ? null : mb_convert_encoding($c, 'UTF-8', 'Windows-1252'), $l);
        yield $l;
    }
    fclose($f);
}

// Source d'un export d'après ses colonnes (en-têtes normalisés).
function fin_detecter_source(array $cles): ?string {
    $a = array_flip($cles);
    if (isset($a['nchrono'], $a['coderec']))               return 'x3';
    if (isset($a['ndedossier'], $a['typedenrolement']))    return 'cgi';
    if (isset($a['nnsiiv'], $a['sourcerequete']))          return 'brique';
    if (isset($a['ncil'], $a['statusdossier']))            return 'caisse';
    if (isset($a['statutplaque'], $a['datedinstallation'])) return 'optoplate';
    return null;
}

// ── Correspondance colonnes de l'export → table ───────────────
//    Chaque fonction renvoie [ligne, null] ou [null, motif du rejet].

function fin_map_brique(array $v): array {
    $id = fin_entier($v['id'] ?? null);
    if (!$id) return [null, 'ID Brique manquant'];
    $l = [
        'id_brique'     => $id,
        'chrono'        => fin_maj($v['ncil'] ?? null, 30),
        'vin'           => fin_vin($v['vin'] ?? null),
        'nsiiv'         => fin_txt($v['nnsiiv'] ?? null, 20),
        'source'        => fin_maj($v['sourcerequete'] ?? null, 30),
        'date_creation' => fin_date($v['datecreation'] ?? null, true),
        'statut'        => fin_txt($v['status'] ?? null, 160),
        'date_maj'      => fin_date($v['datemiseajour'] ?? null, true),
        'immat'         => fin_maj($v['immat'] ?? null, 20),
        'type_plaque'   => fin_txt($v['typeplaque'] ?? null, 60),
        'genre'         => fin_genre($v['genre'] ?? null),
        'motif_rejet'   => fin_txt($v['motifrejet'] ?? null, 250),
        'type_personne' => ($t = fin_txt($v['typepersonne'] ?? null, 20)) ? mb_strtolower($t) : null,
        'n_carte_grise' => fin_txt($v['ncartegrise'] ?? null, 30),
    ];
    if (!$l['vin'] && !$l['chrono'] && !$l['nsiiv']) return [null, 'ni VIN, ni N° CIL, ni N° NSIIV'];
    return [$l, null];
}

function fin_map_cgi(array $v): array {
    $nsiiv = fin_txt($v['ndedossier'] ?? null, 20);
    if (!$nsiiv) return [null, 'N° de dossier manquant'];
    $etat = fin_txt($v['etat'] ?? null, 40);
    return [[
        'nsiiv'           => $nsiiv,
        'chrono'          => fin_maj($v['numerocil'] ?? null, 30),
        'vin'             => fin_vin($v['vin'] ?? null),
        'date_creation'   => fin_date($v['datedecreationdudossier'] ?? null, true),
        'date_validation' => fin_date($v['datedevalidation'] ?? null, true),
        'immat'           => fin_maj($v['numerodimmatriculation'] ?? null, 20),
        'ancienne_immat'  => fin_maj($v['ancienneimmat'] ?? null, 20),
        'type_dossier'    => fin_txt($v['type'] ?? null, 20),
        'type_enrolement' => fin_txt($v['typedenrolement'] ?? null, 40),
        'site_enrolement' => fin_txt($v['siteenrolement'] ?? null, 120),
        'etat'            => fin_txt($v['etatdossier'] ?? null, 40),
        'genre'           => fin_genre($v['categorie'] ?? null),
        'nb_plaques'      => fin_entier($v['nombredeplaque'] ?? null),
        'concessionnaire' => fin_txt($v['concessionnaire'] ?? null, 120),
        'client'          => fin_txt($v['nomclient'] ?? null, 250),
        'mode_paiement'   => fin_txt($v['modepaiement'] ?? null, 20),
        'source'          => fin_maj($v['sourcerequete'] ?? null, 30),
        'supprime'        => $etat !== null && stripos($etat, 'supprim') === 0,
    ], null];
}

function fin_map_x3(array $v): array {
    $ref = fin_maj($v['nchrono'] ?? null, 30);
    if (!$ref) return [null, 'N° Chrono manquant'];
    // Les dossiers sans chrono CIL (ré-immatriculation) portent leur n° NSIIV.
    $est_nsiiv = (bool)preg_match('/^\d{8}\.\d{5}$/', $ref);
    $date = fin_date($v['datedepose'] ?? null);
    return [[
        'cle_ligne'          => $ref . '|' . ($date ?? ''),
        'chrono'             => $est_nsiiv ? null : $ref,
        'nsiiv'              => $est_nsiiv ? $ref : null,
        'date_pose'          => $date,
        'client'             => fin_txt($v['client'] ?? null, 250),
        'genre'              => fin_genre($v['genrevehicule'] ?? null),
        'vin'                => fin_vin($v['vinvehicule'] ?? null),
        'code_rec'           => fin_txt($v['coderec'] ?? null, 40),
        'montant_reconcilie' => fin_montant($v['montantreconcilie'] ?? null),
        'n_facture'          => fin_txt($v['nfacture'] ?? null, 40),
        'montant_ht'         => fin_montant($v['montantht'] ?? null),
        'montant_ttc'        => fin_montant($v['montantttc'] ?? null),
        'n_sortie'           => fin_txt($v['nsortiestock'] ?? null, 40),
        'quantite'           => fin_entier($v['quantitesortie'] ?? null),
        'article1'           => fin_txt($v['article1'] ?? null, 60),
        'serie1'             => fin_txt($v['serie1'] ?? null, 40),
        'article2'           => fin_txt($v['article2'] ?? null, 60),
        'serie2'             => fin_txt($v['serie2'] ?? null, 40),
    ], null];
}

function fin_map_caisse(array $v): array {
    $id = fin_entier($v['id'] ?? null);
    if (!$id) return [null, 'ID Caisse manquant'];
    // Le montant n'est pas dans l'export actuel : pris s'il apparaît.
    $montant = null;
    foreach (['montantencaisse', 'montantpaye', 'montantttc', 'montant'] as $k) {
        if (array_key_exists($k, $v)) { $montant = fin_montant($v[$k]); break; }
    }
    $l = [
        'id_caisse'         => $id,
        'chrono'            => fin_maj($v['ncil'] ?? null, 30),
        'vin'               => fin_vin($v['vin'] ?? null),
        'concessionnaire'   => fin_txt($v['nomconcess'] ?? null, 120),
        'statut'            => fin_maj($v['statut'] ?? null, 30),
        'mode_paiement'     => fin_txt($v['modepaiement'] ?? null, 30),
        'n_bon'             => fin_txt($v['nboncommande'] ?? null, 60),
        'montant'           => $montant,
        'date_facturation'  => fin_date($v['datefacturation'] ?? null, true),
        'date_encaissement' => fin_date($v['dateencaissement'] ?? null, true),
        'date_creation'     => fin_date($v['datecreation'] ?? null, true),
        'date_maj'          => fin_date($v['datemaj'] ?? null, true),
        'statut_dossier'    => fin_txt($v['statusdossier'] ?? null, 160),
        'genre'             => fin_genre($v['genrevehicule'] ?? null),
        'nb_plaques'        => fin_entier($v['nbplaques'] ?? null),
    ];
    if (!$l['vin'] && !$l['chrono']) return [null, 'ni VIN ni N° CIL'];
    return [$l, null];
}

function fin_map_optoplate(array $v): array {
    $nsiiv = fin_txt($v['numerodedossier'] ?? null, 20);
    $vin   = fin_vin($v['vin'] ?? null);
    $immat = fin_maj($v['numerodimmatriculation'] ?? null, 20);
    $pos   = ($p = fin_txt($v['position'] ?? null, 10)) ? mb_strtolower($p) : '';
    $ref   = $nsiiv ?? $vin ?? $immat;
    if (!$ref) return [null, 'ni N° de dossier, ni VIN, ni immatriculation'];
    return [[
        'cle_ligne'         => $ref . '|' . $pos,
        'nsiiv'             => $nsiiv,
        'vin'               => $vin,
        'immat'             => $immat,
        'position'          => $pos ?: null,
        'num_consommable'   => fin_txt($v['nconsommable'] ?? null, 40),
        'statut_plaque'     => fin_txt($v['statutplaque'] ?? null, 30),
        'date_installation' => fin_date($v['datedinstallation'] ?? null, true),
        'site'              => fin_txt($v['nomdusitedinstallation'] ?? null, 120),
    ], null];
}

function fin_map_api_cil(array $x): array {
    $chrono = fin_maj($x['NUMCHRONOCIL'] ?? null, 30);
    if (!$chrono) return [null, 'chrono CIL manquant'];
    $pdf = fin_txt(explode(',', (string)($x['PDFRECUCIL'] ?? ''))[0], 500);
    return [[
        'chrono'         => $chrono,
        'vin'            => fin_vin($x['NUMEROCHASISIS'] ?? null),
        'numero_recu'    => fin_txt($x['NUMERORECUCIL'] ?? null, 30),
        'date_paiement'  => fin_date($x['DATEPAIEMENT'] ?? null),
        'date_ouverture' => fin_date($x['DATEOUVERTUREDOSSIER'] ?? null),
        'type_dossier'   => fin_txt($x['TYPEDOSSIER'] ?? null, 80),
        'genre'          => fin_genre($x['GENRE'] ?? null),
        'mode_paiement'  => fin_maj($x['MODEDEPAIEMENT'] ?? null, 20),
        'type_client'    => fin_maj($x['TYPE_CLIENT'] ?? null, 40),
        'client'         => fin_txt($x['IDENTITE_CLIENT'] ?? null, 250),
        'mt_plaques'     => fin_entier($x['MT_PLAQUES'] ?? null),
        'mt_secupose'    => fin_entier($x['MT_SECUPOSE'] ?? null),
        'pdf_recu'       => $pdf,
    ], null];
}

// Deux lignes du même dossier dans un fichier : les valeurs renseignées de la
// seconde complètent la première.
function fin_fusion(array $a, array $b): array {
    foreach ($b as $k => $v) if ($v !== null && $v !== '') $a[$k] = $v;
    return $a;
}

// ── Écriture ───────────────────────────────────────────────────

// Insère ou met à jour par la clé ; une ligne identique n'est pas réécrite.
// Renvoie le nombre de lignes ajoutées, mises à jour et inchangées.
function fin_upsert(string $table, string $cle, array $lignes): array {
    $n = ['ajoutees' => 0, 'maj' => 0, 'inchangees' => 0];
    if (!$lignes) return $n;
    $cols = array_keys($lignes[0]);
    $maj  = array_values(array_diff($cols, [$cle]));
    $set  = implode(', ', array_map(fn($c) => "$c = EXCLUDED.$c", $maj)) . ', maj_le = CURRENT_TIMESTAMP';
    $diff = '(' . implode(', ', array_map(fn($c) => "$table.$c", $maj)) . ') IS DISTINCT FROM ('
          . implode(', ', array_map(fn($c) => "EXCLUDED.$c", $maj)) . ')';
    $ph   = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $par  = max(1, intdiv(20000, count($cols)));
    foreach (array_chunk($lignes, $par) as $lot) {
        $params = [];
        foreach ($lot as $l) {
            foreach ($cols as $c) {
                $val = $l[$c] ?? null;
                $params[] = is_bool($val) ? ($val ? 't' : 'f') : $val;
            }
        }
        $st = db_query(
            "INSERT INTO $table (" . implode(', ', $cols) . ") VALUES " . implode(', ', array_fill(0, count($lot), $ph))
            . " ON CONFLICT ($cle) DO UPDATE SET $set WHERE $diff RETURNING (xmax = 0) AS ajout",
            $params);
        foreach ($st->fetchAll() as $r) {
            if ($r['ajout'] === true || $r['ajout'] === 't' || $r['ajout'] === 1 || $r['ajout'] === '1') $n['ajoutees']++;
            else $n['maj']++;
        }
    }
    $n['inchangees'] = count($lignes) - $n['ajoutees'] - $n['maj'];
    return $n;
}

function fin_journaliser(string $source, ?string $fichier, array $b, ?int $uid, ?string $du = null, ?string $au = null): int {
    db_query(
        "INSERT INTO fin_imports (source, fichier, periode_du, periode_au, nb_lignes, nb_ajoutees, nb_maj, nb_inchangees, nb_rejetees, details, duree_ms, importe_par)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [$source, $fichier, $du, $au, $b['lignes'], $b['ajoutees'], $b['maj'], $b['inchangees'], $b['rejetees'],
         $b['rejets'] ? implode("\n", $b['rejets']) : null, $b['duree_ms'], $uid]);
    return (int)db_last_id('fin_imports_id_seq');
}

// Importe un export déposé (.xlsx ou .csv) : source reconnue à ses colonnes.
function fin_importer_fichier(string $chemin, string $nom, ?int $uid): array {
    $t0 = microtime(true);
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true))
        throw new RuntimeException('Format non pris en charge : déposez un fichier .xlsx ou .csv.');
    @set_time_limit(300);
    $gen = $ext === 'xlsx' ? fin_xlsx_lignes($chemin) : fin_csv_lignes($chemin);
    $entetes = null; $source = null; $num = 0; $lignes = []; $rejets = []; $nb_rej = 0; $nb = 0;
    foreach ($gen as $brut) {
        $num++;
        $remplies = array_filter($brut, fn($c) => $c !== null && trim((string)$c) !== '');
        if ($entetes === null) {
            if (count($remplies) < 3) continue;          // titre au-dessus de l'en-tête
            $entetes = [];
            foreach ($brut as $i => $c) {
                $k = fin_norm_entete((string)$c);
                if ($k !== '' && !isset($entetes[$k])) $entetes[$k] = $i;
            }
            $source = fin_detecter_source(array_keys($entetes));
            if (!$source) throw new RuntimeException('Fichier non reconnu : ses colonnes ne correspondent à aucun export connu (Brique, CGI, X3, Caisse, OptoPlate).');
            continue;
        }
        if (!$remplies) continue;
        $nb++;
        $v = [];
        foreach ($entetes as $k => $i) $v[$k] = $brut[$i] ?? null;
        [$l, $err] = ('fin_map_' . $source)($v);
        if ($err) {
            $nb_rej++;
            if (count($rejets) < 20) $rejets[] = "Ligne $num : $err";
            continue;
        }
        $cle = (string)$l[FIN_CONF[$source]['cle']];
        $lignes[$cle] = isset($lignes[$cle]) ? fin_fusion($lignes[$cle], $l) : $l;
    }
    if ($entetes === null) throw new RuntimeException('Fichier vide ou sans ligne d\'en-tête.');

    db_begin();
    try {
        $n = fin_upsert(FIN_CONF[$source]['table'], FIN_CONF[$source]['cle'], array_values($lignes));
        if ($source === 'x3') {
            db_query("UPDATE fin_src_x3 SET reconcilie_le = CURRENT_DATE
                      WHERE reconcilie_le IS NULL AND (code_rec IS NOT NULL OR COALESCE(montant_reconcilie, 0) > 0)");
        }
        $bilan = $n + [
            'source'   => $source,
            'libelle'  => FIN_SOURCES[$source],
            'lignes'   => $nb,
            'doublons' => $nb - $nb_rej - count($lignes),
            'rejetees' => $nb_rej,
            'rejets'   => $rejets,
            'duree_ms' => (int)round((microtime(true) - $t0) * 1000),
        ];
        $bilan['import_id'] = fin_journaliser($source, mb_substr($nom, 0, 250), $bilan, $uid);
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
    return $bilan;
}

// ── API CIL ────────────────────────────────────────────────────

function fin_api_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT      => 'ERP-EMUCI',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $corps = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);
    if ($corps === false) throw new RuntimeException('API CIL injoignable : ' . $err);
    if ($code !== 200)   throw new RuntimeException("API CIL : réponse HTTP $code.");
    $d = json_decode($corps, true);
    if ($d === null && trim($corps) !== 'null') throw new RuntimeException('API CIL : réponse illisible.');
    if (!is_array($d)) return [];
    return array_is_list($d) ? $d : [$d];
}

// Paiements CIL de la période (date de paiement), par tranches de 31 jours.
function fin_sync_cil(string $du, string $au, ?int $uid): array {
    $t0 = microtime(true);
    $d1 = DateTime::createFromFormat('!Y-m-d', $du);
    $d2 = DateTime::createFromFormat('!Y-m-d', $au);
    if (!$d1 || !$d2) throw new RuntimeException('Période invalide.');
    if ($d2 < $d1) [$d1, $d2] = [$d2, $d1];
    if ($d1->diff($d2)->days > 92) throw new RuntimeException('Période trop longue : 92 jours au plus par synchronisation.');
    @set_time_limit(300);
    $lignes = []; $rejets = []; $nb_rej = 0; $nb = 0;
    for ($deb = clone $d1; $deb <= $d2; $deb->modify('+31 days')) {
        $fin = (clone $deb)->modify('+30 days');
        if ($fin > $d2) $fin = clone $d2;
        foreach (fin_api_get(sprintf(FIN_API_PERIODE, $deb->format('Ymd'), $fin->format('Ymd'))) as $x) {
            $nb++;
            [$l, $err] = fin_map_api_cil(is_array($x) ? $x : []);
            if ($err) { $nb_rej++; if (count($rejets) < 20) $rejets[] = $err; continue; }
            $lignes[$l['chrono']] = $l;
        }
    }
    db_begin();
    try {
        $n = fin_upsert('fin_src_cil', 'chrono', array_values($lignes));
        $bilan = $n + [
            'source' => 'api_cil', 'libelle' => FIN_SOURCES['api_cil'],
            'lignes' => $nb, 'doublons' => $nb - $nb_rej - count($lignes),
            'rejetees' => $nb_rej, 'rejets' => $rejets,
            'duree_ms' => (int)round((microtime(true) - $t0) * 1000),
        ];
        $bilan['import_id'] = fin_journaliser('api_cil', null, $bilan, $uid, $d1->format('Y-m-d'), $d2->format('Y-m-d'));
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
    return $bilan;
}

// Un dossier CIL par son chrono (fiche dossier, « Actualiser depuis CIL »).
// Cette route ne renvoie pas le genre : celui déjà connu est gardé.
function fin_cil_par_chrono(string $chrono): ?array {
    $chrono = strtoupper(trim($chrono));
    if (!preg_match('/^[A-Z0-9.]{4,30}$/', $chrono)) throw new RuntimeException('Chrono invalide.');
    $data = fin_api_get(sprintf(FIN_API_CHRONO, rawurlencode($chrono)));
    if (!$data) return null;
    [$l, $err] = fin_map_api_cil($data[0]);
    if ($err) return null;
    if ($l['genre'] === null) $l['genre'] = db_fetch_value("SELECT genre FROM fin_src_cil WHERE chrono=?", [$l['chrono']]) ?: null;
    fin_upsert('fin_src_cil', 'chrono', [$l]);
    return $l;
}

function fin_derniere_sync(): ?string {
    return db_fetch_value("SELECT MAX(cree_le) FROM fin_imports WHERE source='api_cil'") ?: null;
}

// ── Consolidation ──────────────────────────────────────────────

// Reconstruit fin_dossiers à partir des tables sources. Un dossier par clé
// (VIN, sinon chrono ou NSIIV) ; pour chaque source, la ligne la plus
// récente du dossier.
function fin_consolider(): array {
    $t0 = microtime(true);
    $opto = (bool)db_fetch_value("SELECT to_regclass('public.import_optoplate') IS NOT NULL");
    $delai = (int)FIN_DELAI_RECEPTION_JOURS;
    db_begin();
    try {
        // Correspondances chrono / NSIIV → VIN, connues par n'importe quelle source.
        db_query("CREATE TEMP TABLE fin_t_map ON COMMIT DROP AS
            SELECT DISTINCT ON (k) k, vin FROM (
                SELECT 'C:' || chrono AS k, vin, 1 AS prio FROM fin_src_cil WHERE vin IS NOT NULL
                UNION ALL SELECT 'C:' || chrono, vin, 2 FROM fin_src_brique WHERE vin IS NOT NULL AND chrono IS NOT NULL
                UNION ALL SELECT 'N:' || nsiiv,  vin, 2 FROM fin_src_brique WHERE vin IS NOT NULL AND nsiiv IS NOT NULL
                UNION ALL SELECT 'C:' || chrono, vin, 3 FROM fin_src_cgi WHERE vin IS NOT NULL AND chrono IS NOT NULL
                UNION ALL SELECT 'N:' || nsiiv,  vin, 3 FROM fin_src_cgi WHERE vin IS NOT NULL
                UNION ALL SELECT 'C:' || chrono, vin, 4 FROM fin_src_caisse WHERE vin IS NOT NULL AND chrono IS NOT NULL
                UNION ALL SELECT 'C:' || chrono, vin, 5 FROM fin_src_x3 WHERE vin IS NOT NULL AND chrono IS NOT NULL
                UNION ALL SELECT 'N:' || nsiiv,  vin, 5 FROM fin_src_x3 WHERE vin IS NOT NULL AND nsiiv IS NOT NULL
                UNION ALL SELECT 'N:' || nsiiv,  vin, 6 FROM fin_src_pose WHERE vin IS NOT NULL AND nsiiv IS NOT NULL"
                . ($opto ? "
                UNION ALL SELECT 'N:' || numero_dossier, upper(trim(vin)), 7 FROM import_optoplate
                          WHERE numero_dossier IS NOT NULL AND COALESCE(trim(vin), '') <> ''" : '') . "
            ) m ORDER BY k, prio");
        db_query("CREATE INDEX ON fin_t_map (k)");

        $cle = fn(string $s, bool $chrono, bool $nsiiv) => 'COALESCE(' . $s . '.vin'
            . ($chrono ? ", (SELECT vin FROM fin_t_map WHERE k = 'C:' || $s.chrono)" : '')
            . ($nsiiv  ? ", (SELECT vin FROM fin_t_map WHERE k = 'N:' || $s.nsiiv)" : '')
            . ", '#' || " . ($chrono && $nsiiv ? "COALESCE($s.chrono, $s.nsiiv)" : ($chrono ? "$s.chrono" : "$s.nsiiv")) . ')';

        db_query("CREATE TEMP TABLE fin_t_cil ON COMMIT DROP AS
            SELECT DISTINCT ON (cle) * FROM (SELECT " . $cle('s', true, false) . " AS cle, s.* FROM fin_src_cil s) x
            ORDER BY cle, date_paiement DESC NULLS LAST");
        db_query("CREATE TEMP TABLE fin_t_bri ON COMMIT DROP AS
            SELECT DISTINCT ON (cle) * FROM (
                SELECT y.*, MIN(y.date_creation) OVER (PARTITION BY y.cle) AS premiere_creation
                FROM (SELECT " . $cle('s', true, true) . " AS cle, s.* FROM fin_src_brique s) y) x
            ORDER BY cle, date_maj DESC NULLS LAST, id_brique DESC");
        db_query("CREATE TEMP TABLE fin_t_cgi ON COMMIT DROP AS
            SELECT DISTINCT ON (cle) * FROM (SELECT " . $cle('s', true, true) . " AS cle, s.* FROM fin_src_cgi s WHERE NOT s.supprime) x
            ORDER BY cle, date_creation DESC NULLS LAST");
        db_query("CREATE TEMP TABLE fin_t_x3 ON COMMIT DROP AS
            SELECT DISTINCT ON (cle) * FROM (SELECT " . $cle('s', true, true) . " AS cle, s.* FROM fin_src_x3 s) x
            ORDER BY cle, (code_rec IS NOT NULL OR COALESCE(montant_reconcilie, 0) > 0) DESC, date_pose DESC NULLS LAST");
        db_query("CREATE TEMP TABLE fin_t_cai ON COMMIT DROP AS
            SELECT DISTINCT ON (cle) * FROM (SELECT " . $cle('s', true, false) . " AS cle, s.* FROM fin_src_caisse s) x
            ORDER BY cle, date_maj DESC NULLS LAST, id_caisse DESC");
        db_query("CREATE TEMP TABLE fin_t_pos ON COMMIT DROP AS
            SELECT cle, MIN(date_installation) AS date_installation,
                   MAX(num_consommable) FILTER (WHERE position IN ('front', 'avant')) AS avant,
                   MAX(num_consommable) FILTER (WHERE position IN ('rear', 'arriere', 'arrière')) AS arriere,
                   MAX(immat) AS immat, MAX(nsiiv) AS nsiiv,
                   (array_agg(site ORDER BY date_installation DESC NULLS LAST) FILTER (WHERE COALESCE(site, '') <> ''))[1] AS site_pose
            FROM (
                SELECT " . $cle('s', false, true) . " AS cle, s.date_installation, s.position, s.num_consommable, s.immat, s.nsiiv, s.site
                FROM fin_src_pose s WHERE COALESCE(s.statut_plaque, 'in_use') = 'in_use'"
                . ($opto ? "
                UNION ALL
                SELECT COALESCE(NULLIF(upper(trim(o.vin)), ''), (SELECT vin FROM fin_t_map WHERE k = 'N:' || o.numero_dossier), '#' || o.numero_dossier),
                       o.date_installation, lower(o.position), o.num_consommable, upper(o.immatriculation), o.numero_dossier, o.site_nom_emuci
                FROM import_optoplate o WHERE o.statut_plaque = 'in_use'" : '') . "
            ) p WHERE cle IS NOT NULL AND cle <> '#' GROUP BY cle");

        // Résultat calculé à part (table temporaire, hors journal), puis seuls
        // les dossiers qui ont changé sont réécrits : réécrire les 200 000
        // lignes à chaque import gonflerait la base (Neon) pour rien.
        $cols = ['cle', 'vin', 'chrono', 'nsiiv', 'immat', 'canal', 'type_dossier', 'type_dossier_cil', 'genre', 'centre', 'client', 'type_client',
            'date_ouverture', 'date_paiement', 'mode_paiement', 'numero_recu', 'pdf_recu', 'mt_plaques', 'mt_secupose', 'montant_dossier',
            'date_reception', 'statut_brique', 'motif_rejet', 'date_enrolement', 'etat_cgi',
            'code_rec', 'n_facture', 'montant_reconcilie', 'montant_ht', 'montant_ttc', 'date_reconciliation', 'caisse_statut', 'n_bon',
            'date_installation', 'date_pose', 'plaque_avant', 'plaque_arriere', 'source_pose', 'montant_attendu', 'ecart',
            'est_ouvert', 'est_recu', 'est_reconcilie', 'est_pose', 'paye_par_bon', 'etape',
            'a_ouvert_non_recu', 'a_pose_non_reconcilie', 'a_ecart', 'a_recu_sans_paiement', 'a_rejet', 'a_pose_sans_dossier',
            'date_ref', 'maj_le'];
        $services = fin_services_pret();
        if ($services) array_push($cols, 'site', 'site_ids', 'formule_id', 'montant_variable', 'formule_attente');
        // Formules : services fixes additionnés par catégorie (prix manquant pour
        // un service = montant inconnu) ; un service variable (reliquat) accepte
        // le complément réconcilié au-delà. Une formule par site l'emporte sur une
        // formule par dossier, puis la plus précise (canal, type, catégorie).
        if ($services) {
            db_query("CREATE TEMP TABLE fin_t_form ON COMMIT DROP AS
                SELECT f.id, f.mode, f.site_ids, f.canaux, f.types, f.categories,
                       (CASE WHEN f.mode = 'site' THEN 8 ELSE 0 END + CASE WHEN f.canaux IS NOT NULL THEN 4 ELSE 0 END
                        + CASE WHEN f.types IS NOT NULL THEN 2 ELSE 0 END + CASE WHEN f.categories IS NOT NULL THEN 1 ELSE 0 END) AS prec,
                       COALESCE(bool_or(s.variable), false) AS variable
                FROM fin_formules f
                LEFT JOIN fin_formule_services fs ON fs.formule_id = f.id
                LEFT JOIN fin_services s ON s.id = fs.service_id AND s.actif
                WHERE f.actif GROUP BY f.id");
            db_query("CREATE TEMP TABLE fin_t_form_prix ON COMMIT DROP AS
                SELECT fs.formule_id, c.cat,
                       CASE WHEN COUNT(*) FILTER (WHERE NOT s.variable AND p.montant_ttc IS NULL) = 0
                            THEN COALESCE(SUM(p.montant_ttc) FILTER (WHERE NOT s.variable), 0) END AS montant
                FROM fin_formule_services fs
                JOIN fin_services s ON s.id = fs.service_id AND s.actif
                CROSS JOIN (VALUES ('AUTO'), ('MOTO'), ('SEMI')) c (cat)
                LEFT JOIN fin_service_prix p ON p.service_id = s.id AND p.categorie = c.cat
                GROUP BY 1, 2");
            $cat_b = fin_categorie_sql('b.genre');
            // Sites ERP du dossier (pose ou mixte) : rattachements manuels du code
            // (un code peut aller vers plusieurs sites), sinon un site trouvé
            // automatiquement (nom du site, puis code, puis préfixe « XXX-01 »).
            db_query("CREATE TEMP TABLE fin_t_site_auto ON COMMIT DROP AS
                SELECT DISTINCT ON (k) k, id AS site_id FROM (
                    SELECT upper(trim(nom)) AS k, id, 1 AS prio, actif FROM sites WHERE " . FIN_SITES_TYPES_SQL . "
                    UNION ALL SELECT upper(trim(code)), id, 2, actif FROM sites WHERE " . FIN_SITES_TYPES_SQL . "
                    UNION ALL SELECT upper(regexp_replace(trim(code), '-01$', '')), id, 3, actif FROM sites
                              WHERE code LIKE '%-01' AND " . FIN_SITES_TYPES_SQL . "
                ) x ORDER BY k, prio, actif DESC, id");
            db_query("CREATE TEMP TABLE fin_t_site ON COMMIT DROP AS
                SELECT upper(trim(m.code)) AS k, array_agg(m.site_id ORDER BY m.site_id) AS site_ids
                FROM fin_site_codes m JOIN sites s ON s.id = m.site_id AND s." . FIN_SITES_TYPES_SQL . "
                GROUP BY 1");
            db_query("INSERT INTO fin_t_site SELECT a.k, ARRAY[a.site_id] FROM fin_t_site_auto a
                      WHERE NOT EXISTS (SELECT 1 FROM fin_site_codes m WHERE upper(trim(m.code)) = a.k)");
            db_query("CREATE INDEX ON fin_t_site (k)");
            $formule = "LEFT JOIN fin_t_site sc ON sc.k = upper(trim(b.site))
                LEFT JOIN LATERAL (
                    SELECT f.id, f.mode, f.variable, fp.montant FROM fin_t_form f
                    LEFT JOIN fin_t_form_prix fp ON fp.formule_id = f.id AND fp.cat = $cat_b
                    WHERE (f.mode = 'dossier' OR sc.site_ids && f.site_ids)
                      AND (f.canaux IS NULL OR b.canal = ANY (f.canaux))
                      AND (f.types IS NULL OR b.type_dossier = ANY (f.types))
                      AND (f.categories IS NULL OR $cat_b = ANY (f.categories))
                    ORDER BY f.prec DESC, f.id LIMIT 1) fo ON true";
        } else {
            $formule = "LEFT JOIN LATERAL (SELECT NULL::integer[] AS site_ids) sc ON true
                LEFT JOIN LATERAL (SELECT NULL::integer AS id, NULL::varchar AS mode, false AS variable, NULL::integer AS montant) fo ON true";
        }
        // Montant réconcilié comparable au montant attendu ; au-delà, complément
        // accepté si la formule a un service variable.
        $comparable = "z.est_reconcilie AND COALESCE(z.montant_reconcilie, 0) > 0 AND z.montant_attendu IS NOT NULL";
        $complement = "(z.variable AND z.montant_reconcilie > z.montant_attendu)";
        // Opération spéciale (Abobo) : repérée dans le CGI par le site d'enrôlement
        // ou le concessionnaire, ou dossier Primo sans N° CIL ni source requête.
        $speciale = "COALESCE(g.site_enrolement ILIKE 'ABOBO PREFECTURE POLICE%' OR g.concessionnaire ILIKE 'OPERATION ABOBO%'
                      OR (g.cle IS NOT NULL AND g.type_dossier = 'Primo' AND g.chrono IS NULL AND COALESCE(g.source, '') = ''), false)";
        $tarif_speciale_defaut = fin_tarif_speciale_defaut_sql(fin_categorie_sql('b.genre'));
        db_query("CREATE TEMP TABLE fin_t_new (LIKE fin_dossiers INCLUDING DEFAULTS) ON COMMIT DROP");
        db_query("INSERT INTO fin_t_new (" . implode(', ', $cols) . ")
            SELECT z.cle, z.vin, z.chrono, z.nsiiv, z.immat, z.canal, z.type_dossier, z.type_dossier_cil, z.genre, z.centre, z.client, z.type_client,
                z.date_ouverture, z.date_paiement, z.mode_paiement, z.numero_recu, z.pdf_recu, z.mt_plaques, z.mt_secupose, z.montant_dossier,
                z.date_reception, z.statut_brique, z.motif_rejet, z.date_enrolement, z.etat_cgi,
                z.code_rec, z.n_facture, z.montant_reconcilie, z.montant_ht, z.montant_ttc, z.date_reconciliation, z.caisse_statut, z.n_bon,
                z.date_installation, z.date_pose, z.plaque_avant, z.plaque_arriere, z.source_pose, z.montant_attendu,
                CASE WHEN $comparable THEN CASE WHEN $complement THEN 0 ELSE z.montant_reconcilie - z.montant_attendu END END,
                z.est_ouvert, z.est_recu, z.est_reconcilie, z.est_pose, z.bon AND NOT z.est_reconcilie,
                -- Dossier connu seulement de la Caisse : il est arrivé chez EMUCI.
                CASE WHEN z.est_pose THEN 'pose' WHEN z.est_reconcilie THEN 'reconcilie' WHEN z.est_ouvert AND NOT z.est_recu THEN 'ouvert' ELSE 'recu' END,
                z.est_ouvert AND NOT z.est_recu AND NOT z.est_pose
                    AND COALESCE(z.date_paiement, z.date_ouverture) < CURRENT_DATE - $delai,
                z.est_pose AND NOT z.est_reconcilie AND NOT z.bon,
                $comparable AND z.montant_reconcilie <> z.montant_attendu AND NOT $complement,
                COALESCE(z.canal, '') = 'CIL' AND z.est_recu AND NOT z.est_ouvert,
                COALESCE(z.statut_brique ILIKE '%rejet%' OR z.statut_brique ILIKE '%chou%', false),
                z.est_pose AND NOT z.est_recu,
                COALESCE(z.date_reception::date, z.date_paiement, z.date_pose, z.date_ouverture, z.date_caisse::date),
                CURRENT_TIMESTAMP" . ($services ? ",
                z.site, z.site_ids, z.formule_id, CASE WHEN $comparable AND $complement THEN z.montant_reconcilie - z.montant_attendu END,
                z.formule_attente" : '') . "
            FROM (
                -- Dossier rattaché à plusieurs sites (pas encore posé) dont la
                -- formule retenue est une formule par site : il attend la pose,
                -- qui fixera son site, pour recevoir sa formule et son montant.
                SELECT b.*, sc.site_ids, fa.attente AS formule_attente,
                       CASE WHEN NOT fa.attente THEN fo.id END AS formule_id,
                       COALESCE(fo.variable AND NOT fa.attente, false) AS variable,
                       CASE WHEN fa.attente THEN NULL
                            WHEN fo.id IS NOT NULL THEN fo.montant
                            WHEN b.canal = 'SPECIALE' THEN COALESCE(ts.montant_ttc, $tarif_speciale_defaut, t.montant_ttc)
                            ELSE COALESCE(t.montant_ttc, b.montant_dossier) END AS montant_attendu
                FROM (
                    SELECT k.cle,
                        COALESCE(a.vin, r.vin, g.vin, x.vin, c.vin, CASE WHEN k.cle NOT LIKE '#%' THEN k.cle END) AS vin,
                        COALESCE(a.chrono, r.chrono, g.chrono, x.chrono, c.chrono) AS chrono,
                        COALESCE(g.nsiiv, r.nsiiv, x.nsiiv, p.nsiiv) AS nsiiv,
                        COALESCE(g.immat, r.immat, p.immat) AS immat,
                        -- Canal : export CGI (source principale), sinon Brique pour un
                        -- dossier pas encore enrôlé. Source requête vide au CGI :
                        -- Reimmat / Post → Reimmat, Primo sans N° CIL → opération spéciale.
                        CASE WHEN $speciale THEN 'SPECIALE'
                             WHEN COALESCE(g.source, r.source) LIKE 'DGTTC_FDS%' THEN 'FDS'
                             WHEN COALESCE(g.source, r.source) LIKE 'DGTTC%' THEN 'DGTTC'
                             WHEN COALESCE(g.source, r.source) = 'CIL' THEN 'CIL'
                             WHEN g.type_dossier = 'Reimmat' THEN 'REIMMAT'
                             WHEN a.chrono IS NOT NULL THEN 'CIL' END AS canal,
                        -- Type : CGI uniquement. Un dossier connu seulement de CIL est
                        -- une primo-immatriculation (les réimmatriculations n'ont pas de N° CIL).
                        CASE WHEN g.type_enrolement ILIKE '%diplo%' OR r.type_plaque ILIKE '%DIPLO%'
                                  OR a.type_dossier ILIKE '%diplomati%' THEN 'Diplo'
                             WHEN g.type_dossier IS NOT NULL THEN g.type_dossier
                             WHEN a.cle IS NOT NULL OR r.source = 'CIL' THEN 'Primo' END AS type_dossier,
                        a.type_dossier AS type_dossier_cil,
                        COALESCE(a.genre, g.genre, r.genre, c.genre,
                                 CASE x.genre WHEN 'MOTO' THEN 'MOTOCYCLETTE' ELSE x.genre END) AS genre,
                        COALESCE(g.site_enrolement,
                                 substring(COALESCE(a.chrono, r.chrono, g.chrono, x.chrono, c.chrono) FROM '^[A-Z]{2,3}')) AS centre,
                        -- Site : celui de la pose (OptoPlate) s'il est connu, sinon celui de
                        -- l'enrôlement (préfixe du chrono : BKE, ABJ… ; sinon site CGI).
                        COALESCE(p.site_pose, substring(COALESCE(a.chrono, r.chrono, g.chrono, x.chrono, c.chrono) FROM '^[A-Z]{2,3}'),
                                 g.site_enrolement) AS site,
                        COALESCE(a.client, g.client, x.client, c.concessionnaire) AS client,
                        a.type_client,
                        a.date_ouverture, a.date_paiement, a.mode_paiement, a.numero_recu, a.pdf_recu, a.mt_plaques, a.mt_secupose,
                        CASE WHEN a.cle IS NOT NULL THEN COALESCE(a.mt_plaques, 0) + COALESCE(a.mt_secupose, 0) END AS montant_dossier,
                        COALESCE(r.premiere_creation, g.date_creation) AS date_reception,
                        r.statut AS statut_brique, r.motif_rejet,
                        g.date_creation AS date_enrolement, g.etat AS etat_cgi,
                        x.code_rec, x.n_facture, x.montant_reconcilie, x.montant_ht, x.montant_ttc,
                        COALESCE(c.date_encaissement::date, x.reconcilie_le) AS date_reconciliation,
                        c.statut AS caisse_statut, c.n_bon, c.date_creation AS date_caisse,
                        p.date_installation,                              -- OptoPlate
                        COALESCE(x.date_pose, p.date_installation::date) AS date_pose,   -- X3, sinon OptoPlate
                        COALESCE(p.avant, x.serie1) AS plaque_avant,
                        COALESCE(p.arriere, x.serie2) AS plaque_arriere,
                        CASE WHEN p.date_installation IS NOT NULL THEN 'optoplate' WHEN x.date_pose IS NOT NULL THEN 'x3' END AS source_pose,
                        (a.cle IS NOT NULL AND NOT $speciale) AS est_ouvert,
                        (r.cle IS NOT NULL OR g.cle IS NOT NULL) AS est_recu,
                        (x.code_rec IS NOT NULL OR COALESCE(x.montant_reconcilie, 0) > 0
                            OR COALESCE(c.statut, '') = 'ENCAISSER') AS est_reconcilie,
                        (p.cle IS NOT NULL OR x.date_pose IS NOT NULL) AS est_pose,
                        -- Payé par bon : ligne X3 réconciliée à 0 (sauf dossier CIL payé
                        -- en espèces avec un montant dû) ou facture sur bon en caisse.
                        ((x.cle IS NOT NULL AND x.code_rec IS NULL AND COALESCE(x.montant_reconcilie, 0) = 0
                          AND NOT (COALESCE(a.mode_paiement, '') = 'ESPECE'
                                   AND COALESCE(a.mt_plaques, 0) + COALESCE(a.mt_secupose, 0) > 0))
                         OR COALESCE(c.statut, '') = 'FACTURE_BON') AS bon
                    FROM (SELECT cle FROM fin_t_cil UNION SELECT cle FROM fin_t_bri UNION SELECT cle FROM fin_t_cgi
                          UNION SELECT cle FROM fin_t_x3 UNION SELECT cle FROM fin_t_cai UNION SELECT cle FROM fin_t_pos) k
                    LEFT JOIN fin_t_cil a ON a.cle = k.cle
                    LEFT JOIN fin_t_bri r ON r.cle = k.cle
                    LEFT JOIN fin_t_cgi g ON g.cle = k.cle
                    LEFT JOIN fin_t_x3  x ON x.cle = k.cle
                    LEFT JOIN fin_t_cai c ON c.cle = k.cle
                    LEFT JOIN fin_t_pos p ON p.cle = k.cle
                ) b
                LEFT JOIN fin_tarifs t ON t.genre = " . fin_categorie_sql('b.genre') . "
                LEFT JOIN fin_tarifs ts ON b.canal = 'SPECIALE' AND ts.genre = " . get_db()->quote(FIN_TARIF_SPECIALE_PREFIXE) . " || " . fin_categorie_sql('b.genre') . "
                $formule
                CROSS JOIN LATERAL (SELECT COALESCE(fo.mode = 'site' AND cardinality(sc.site_ids) > 1, false) AS attente) fa
            ) z");
        db_query("CREATE INDEX ON fin_t_new (cle)");
        $supprimes = db_query("DELETE FROM fin_dossiers d WHERE NOT EXISTS (SELECT 1 FROM fin_t_new n WHERE n.cle = d.cle)")->rowCount();
        $comp = array_values(array_diff($cols, ['cle', 'maj_le']));
        $res = db_query("INSERT INTO fin_dossiers (" . implode(', ', $cols) . ")
            SELECT " . implode(', ', $cols) . " FROM fin_t_new
            ON CONFLICT (cle) DO UPDATE SET " . implode(', ', array_map(fn($c) => "$c = EXCLUDED.$c", array_merge($comp, ['maj_le']))) . "
            WHERE (" . implode(', ', array_map(fn($c) => "fin_dossiers.$c", $comp)) . ") IS DISTINCT FROM ("
                     . implode(', ', array_map(fn($c) => "EXCLUDED.$c", $comp)) . ")");
        $ecrits = $res->rowCount();
        $nb = (int)db_fetch_value("SELECT COUNT(*) FROM fin_t_new");
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        throw $e;
    }
    return ['dossiers' => $nb, 'modifies' => $ecrits, 'supprimes' => $supprimes,
            'duree_ms' => (int)round((microtime(true) - $t0) * 1000)];
}

// ── Lecture pour les écrans ────────────────────────────────────

// Date sur laquelle porte la période Du/Au de l'écran de suivi. Elle était
// fixée à date_ref (réception, sinon paiement…) : un dossier payé hors de la
// période mais reçu dedans s'affichait quand même, et l'écran semblait ignorer
// le filtre. Le choix est maintenant explicite (liste « Période selon »).
const FIN_PERIODES = [
    'paiement'  => 'Paiement (sinon réception)',
    'reception' => 'Réception chez EMUCI',
    'pose'      => 'Pose des plaques',
];
function fin_periode_sql(string $periode): string {
    switch ($periode) {
        case 'reception': return 'date_reception::date';
        case 'pose':      return 'date_pose';
        default:          return 'COALESCE(date_paiement, date_reception::date)';
    }
}

// Filtres de l'écran de suivi → [clause WHERE, paramètres].
function fin_filtres_sql(array $f): array {
    $w = ['1=1']; $p = [];
    $d = fin_periode_sql((string)($f['periode'] ?? 'paiement'));
    if (!empty($f['du']))   { $w[] = "$d >= ?"; $p[] = $f['du']; }
    if (!empty($f['au']))   { $w[] = "$d <= ?"; $p[] = $f['au']; }
    if (!empty($f['canal']) && isset(FIN_CANAUX[$f['canal']])) { $w[] = 'canal = ?'; $p[] = $f['canal']; }
    if (!empty($f['type'])) { $w[] = 'type_dossier = ?'; $p[] = $f['type']; }
    if (!empty($f['genre']) && isset(FIN_CATEGORIES[$f['genre']])) { $w[] = fin_categorie_sql('genre') . ' = ?'; $p[] = $f['genre']; }
    if (!empty($f['etape']) && isset(FIN_ETAPES[$f['etape']])) { $w[] = 'etape = ?'; $p[] = $f['etape']; }
    if (!empty($f['anomalie'])) {
        if (isset(FIN_ANOMALIES[$f['anomalie']])) $w[] = $f['anomalie'];
        elseif ($f['anomalie'] === 'bon')        $w[] = 'paye_par_bon';
        elseif ($f['anomalie'] === 'toutes')     $w[] = '(' . implode(' OR ', array_keys(FIN_ANOMALIES)) . ')';
    }
    if (!empty($f['q'])) {
        $q = strtoupper(trim($f['q']));
        $w[] = '(vin = ? OR chrono = ? OR nsiiv = ? OR immat = ? OR vin LIKE ? OR chrono LIKE ?)';
        array_push($p, $q, $q, $q, $q, $q . '%', $q . '%');
    }
    return [implode(' AND ', $w), $p];
}

function fin_kpis(string $where, array $p): array {
    $anom = implode(', ', array_map(fn($a) => "COUNT(*) FILTER (WHERE $a) AS $a", array_keys(FIN_ANOMALIES)));
    return db_fetch_one("SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE etape = 'ouvert') AS n_ouvert,
            COUNT(*) FILTER (WHERE etape = 'recu') AS n_recu,
            COUNT(*) FILTER (WHERE etape = 'reconcilie') AS n_reconcilie,
            COUNT(*) FILTER (WHERE etape = 'pose') AS n_pose,
            COUNT(*) FILTER (WHERE est_reconcilie) AS n_reconcilies,
            COUNT(*) FILTER (WHERE paye_par_bon) AS n_bon,
            COALESCE(SUM(montant_dossier), 0) AS mt_dossiers,
            COALESCE(SUM(mt_plaques), 0) AS mt_plaques,
            COALESCE(SUM(mt_secupose), 0) AS mt_secupose,
            COALESCE(SUM(montant_attendu), 0) AS mt_attendu,
            COALESCE(SUM(montant_reconcilie) FILTER (WHERE est_reconcilie), 0) AS mt_reconcilie,
            COALESCE(SUM(montant_attendu) FILTER (WHERE est_pose AND NOT est_reconcilie AND NOT paye_par_bon), 0) AS mt_a_reconcilier,
            COALESCE(SUM(ecart) FILTER (WHERE a_ecart), 0) AS mt_ecart,
            $anom,
            ROUND(AVG(date_reception::date - date_paiement) FILTER (WHERE date_reception IS NOT NULL AND date_paiement IS NOT NULL), 1) AS d_transmission,
            percentile_cont(0.5) WITHIN GROUP (ORDER BY date_reception::date - date_paiement)
                FILTER (WHERE date_reception IS NOT NULL AND date_paiement IS NOT NULL) AS d_transmission_med,
            ROUND(AVG(date_pose - date_reception::date) FILTER (WHERE date_pose IS NOT NULL AND date_reception IS NOT NULL), 1) AS d_pose,
            percentile_cont(0.5) WITHIN GROUP (ORDER BY date_pose - date_reception::date)
                FILTER (WHERE date_pose IS NOT NULL AND date_reception IS NOT NULL) AS d_pose_med
        FROM fin_dossiers WHERE $where", $p) ?? [];
}

// Période couverte par chaque source : les anomalies ne valent que sur les
// dates couvertes par toutes.
function fin_couverture(): array {
    $r = [];
    $defs = [
        'api_cil'   => ['fin_src_cil', 'date_paiement'],
        'brique'    => ['fin_src_brique', 'date_creation'],
        'cgi'       => ['fin_src_cgi', 'date_creation'],
        'x3'        => ['fin_src_x3', 'date_pose'],
        'caisse'    => ['fin_src_caisse', 'date_creation'],
        'optoplate' => ['fin_src_pose', 'date_installation'],
    ];
    foreach ($defs as $src => [$t, $c]) {
        $l = db_fetch_one("SELECT COUNT(*) AS n, MIN($c)::date AS du, MAX($c)::date AS au FROM $t");
        $l['dernier'] = db_fetch_value("SELECT MAX(cree_le) FROM fin_imports WHERE source = ?", [$src]);
        $r[$src] = $l;
    }
    return $r;
}

// Toutes les lignes sources d'un dossier, pour la fiche.
function fin_dossier_detail(string $cle): ?array {
    $d = db_fetch_one("SELECT * FROM fin_dossiers WHERE cle = ?", [$cle]);
    if (!$d) return null;
    $d['categorie'] = fin_categorie_libelle($d['genre']);
    $d['canal_libelle'] = FIN_CANAUX[$d['canal'] ?? ''] ?? $d['canal'];
    $d['formule'] = null; $d['services'] = []; $d['site_nom'] = null;
    if (fin_services_pret() && !empty($d['site_ids']))
        $d['site_nom'] = db_fetch_value("SELECT string_agg(nom, ', ' ORDER BY nom) FROM sites WHERE id = ANY (?::integer[])", [$d['site_ids']]) ?: null;
    if (fin_services_pret() && !empty($d['formule_id'])) {
        $d['formule'] = db_fetch_value("SELECT libelle FROM fin_formules WHERE id = ?", [(int)$d['formule_id']]);
        $d['services'] = db_fetch_all("SELECT s.libelle, s.variable, p.montant_ttc FROM fin_formule_services fs
            JOIN fin_services s ON s.id = fs.service_id AND s.actif
            LEFT JOIN fin_service_prix p ON p.service_id = s.id AND p.categorie = ?
            WHERE fs.formule_id = ? ORDER BY s.variable, s.ordre, s.libelle",
            [fin_categorie($d['genre']) ?? '', (int)$d['formule_id']]);
    }
    $vin = $d['vin'] ?: '#'; $ch = $d['chrono'] ?: '#'; $ns = $d['nsiiv'] ?: '#';
    $d['sources'] = [
        'api_cil' => db_fetch_all("SELECT * FROM fin_src_cil WHERE vin = ? OR chrono = ? ORDER BY date_paiement DESC", [$vin, $ch]),
        'brique'  => db_fetch_all("SELECT * FROM fin_src_brique WHERE vin = ? OR chrono = ? OR nsiiv = ? ORDER BY date_creation DESC", [$vin, $ch, $ns]),
        'cgi'     => db_fetch_all("SELECT * FROM fin_src_cgi WHERE vin = ? OR chrono = ? OR nsiiv = ? ORDER BY date_creation DESC", [$vin, $ch, $ns]),
        'x3'      => db_fetch_all("SELECT * FROM fin_src_x3 WHERE vin = ? OR chrono = ? OR nsiiv = ? ORDER BY date_pose DESC", [$vin, $ch, $ns]),
        'caisse'  => db_fetch_all("SELECT * FROM fin_src_caisse WHERE vin = ? OR chrono = ? ORDER BY date_maj DESC", [$vin, $ch]),
        'optoplate' => db_fetch_all("SELECT * FROM fin_src_pose WHERE vin = ? OR nsiiv = ? ORDER BY date_installation", [$vin, $ns]),
    ];
    return $d;
}

// Genres connus et montants observés, pour le paramétrage des tarifs.
function fin_tarifs_observes(): array {
    return db_fetch_all("
        WITH d AS (SELECT " . fin_categorie_sql('genre') . " AS genre, canal, a_ecart, montant_dossier, montant_reconcilie
                   FROM fin_dossiers WHERE COALESCE(genre, '') <> ''),
             n AS (SELECT genre, COUNT(*) AS nb, COUNT(*) FILTER (WHERE a_ecart) AS nb_ecarts,
                          COUNT(*) FILTER (WHERE canal = 'SPECIALE') AS nb_speciale
                   FROM d GROUP BY genre),
             api AS (SELECT DISTINCT ON (genre) genre, montant_dossier AS v
                     FROM (SELECT genre, montant_dossier, COUNT(*) AS c FROM d
                           WHERE montant_dossier > 0 AND canal <> 'SPECIALE' GROUP BY 1, 2) s
                     ORDER BY genre, c DESC),
             x3 AS (SELECT DISTINCT ON (genre) genre, montant_reconcilie AS v
                    FROM (SELECT genre, montant_reconcilie, COUNT(*) AS c FROM d
                          WHERE montant_reconcilie > 0 AND canal <> 'SPECIALE' GROUP BY 1, 2) s
                    ORDER BY genre, c DESC)
        SELECT g.genre, t.montant_ttc, COALESCE(n.nb, 0) AS nb, api.v AS api_habituel,
               x3.v AS x3_habituel, COALESCE(n.nb_ecarts, 0) AS nb_ecarts,
               COALESCE(n.nb_speciale, 0) AS nb_speciale, ts.montant_ttc AS montant_speciale,
               " . fin_tarif_speciale_defaut_sql('g.genre') . " AS defaut_speciale
        FROM (VALUES ('MOTO', 1), ('AUTO', 2), ('SEMI', 3)) g (genre, ordre)
        LEFT JOIN n ON n.genre = g.genre
        LEFT JOIN api ON api.genre = g.genre
        LEFT JOIN x3 ON x3.genre = g.genre
        LEFT JOIN fin_tarifs t ON t.genre = g.genre
        LEFT JOIN fin_tarifs ts ON ts.genre = " . get_db()->quote(FIN_TARIF_SPECIALE_PREFIXE) . " || g.genre
        ORDER BY g.ordre");
}

// ── Services et formules (Finance → Tarifs) ───────────────────

// Services avec leurs prix par catégorie et le nombre de formules qui les utilisent.
function fin_services_liste(): array {
    $l = db_fetch_all("SELECT s.*, (SELECT COUNT(*) FROM fin_formule_services fs WHERE fs.service_id = s.id) AS nb_formules
                       FROM fin_services s ORDER BY s.actif DESC, s.ordre, s.libelle");
    $prix = db_fetch_all("SELECT service_id, categorie, montant_ttc FROM fin_service_prix");
    foreach ($l as &$s) {
        $s['prix'] = [];
        foreach ($prix as $p) if ((int)$p['service_id'] === (int)$s['id']) $s['prix'][$p['categorie']] = (int)$p['montant_ttc'];
    }
    return $l;
}

// Formules avec leurs services, le montant fixe par catégorie et le nombre de dossiers.
function fin_formules_liste(): array {
    $l = db_fetch_all("SELECT f.id, f.libelle, f.mode, f.actif, array_to_json(f.site_ids) AS site_ids, array_to_json(f.canaux) AS canaux,
            array_to_json(f.types) AS types, array_to_json(f.categories) AS categories,
            (SELECT COUNT(*) FROM fin_dossiers d WHERE d.formule_id = f.id) AS nb_dossiers
        FROM fin_formules f ORDER BY f.actif DESC, f.libelle");
    $liens = db_fetch_all("SELECT formule_id, service_id FROM fin_formule_services");
    $services = [];
    foreach (fin_services_liste() as $s) $services[(int)$s['id']] = $s;
    foreach ($l as &$f) {
        foreach (['site_ids', 'canaux', 'types', 'categories'] as $c) $f[$c] = $f[$c] ? json_decode($f[$c], true) : [];
        $f['services'] = [];
        foreach ($liens as $x) if ((int)$x['formule_id'] === (int)$f['id']) $f['services'][] = (int)$x['service_id'];
        // Montant fixe par catégorie : null si un service fixe actif n'a pas de prix.
        $f['montants'] = []; $f['variable'] = false;
        foreach (FIN_CATEGORIES as $cat => $_) {
            $m = 0;
            foreach ($f['services'] as $sid) {
                $s = $services[$sid] ?? null;
                if (!$s || !$s['actif']) continue;
                if ($s['variable']) { $f['variable'] = true; continue; }
                if (!isset($s['prix'][$cat])) { $m = null; break; }
                $m += $s['prix'][$cat];
            }
            $f['montants'][$cat] = $m;
        }
    }
    return $l;
}

// Codes de site trouvés dans les dossiers (site de pose OptoPlate, sinon
// préfixe du chrono ou site d'enrôlement CGI), par fréquence, avec les sites
// ERP retenus au dernier calcul et les rattachements manuels.
function fin_codes_site(): array {
    $l = db_fetch_all("SELECT d.site AS code, COUNT(*) AS nb, array_to_json(MIN(d.site_ids)) AS sites,
            (SELECT array_to_json(array_agg(m.site_id ORDER BY m.site_id)) FROM fin_site_codes m WHERE m.code = d.site) AS manuels
        FROM fin_dossiers d WHERE COALESCE(d.site, '') <> '' GROUP BY d.site ORDER BY nb DESC, d.site");
    foreach ($l as &$c) {
        $c['sites'] = $c['sites'] ? json_decode($c['sites'], true) : [];
        $c['manuels'] = $c['manuels'] ? json_decode($c['manuels'], true) : [];
    }
    return $l;
}

// Sites de pose ou mixtes de l'ERP (Administration → Sites), avec leur nombre de dossiers.
function fin_sites_liste(): array {
    return db_fetch_all("SELECT s.id, s.code, s.nom, s.actif, COALESCE(n.nb, 0) AS nb_dossiers
        FROM sites s LEFT JOIN (SELECT u.id, COUNT(*) AS nb FROM fin_dossiers d, unnest(d.site_ids) u (id) GROUP BY u.id) n ON n.id = s.id
        WHERE s." . FIN_SITES_TYPES_SQL . " ORDER BY s.actif DESC, s.nom");
}

// Liste de valeurs autorisées → tableau PostgreSQL littéral, ou null (= tous).
function fin_pg_tableau(array $v): ?string {
    $v = array_values(array_unique(array_filter(array_map('strval', $v), fn($x) => $x !== '')));
    if (!$v) return null;
    return '{' . implode(',', array_map(fn($x) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $x) . '"', $v)) . '}';
}

// Actions AJAX de l'écran des tarifs → [message, recalcul nécessaire].
function fin_tarifs_action_services(string $action, array $post, int $uid): array {
    $id = (int)($post['id'] ?? 0);
    switch ($action) {
    case 'site_code':
        // Rattache un code à un ou plusieurs sites ERP ; aucun = automatique.
        $code = fin_txt($post['code'] ?? null, 120);
        if ($code === null) throw new RuntimeException('Code manquant.');
        $ids = json_decode((string)($post['site_ids'] ?? '[]'), true);
        if (!is_array($ids)) throw new RuntimeException('Données invalides.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $noms = $ids ? array_column(db_fetch_all("SELECT nom FROM sites WHERE id IN (" . implode(',', $ids) . ") AND "
                                                 . FIN_SITES_TYPES_SQL . " ORDER BY nom"), 'nom') : [];
        if (count($noms) !== count($ids)) throw new RuntimeException('Site inconnu, ou ni de pose ni mixte.');
        db_begin();
        db_query("DELETE FROM fin_site_codes WHERE code = ?", [$code]);
        foreach ($ids as $sid) db_query("INSERT INTO fin_site_codes (code, site_id) VALUES (?, ?)", [$code, $sid]);
        db_commit();
        $txt = $noms ? 'rattaché à ' . implode(', ', $noms) : 'rattachement automatique';
        audit_log($uid, 'UPDATE', 'finance_dossiers', null, "Code site « $code » : $txt");
        return ["« $code » : $txt.", true];

    case 'service_enregistrer':
        $lib = fin_txt($post['libelle'] ?? null, 80);
        if ($lib === null) throw new RuntimeException('Libellé du service obligatoire.');
        $variable = !empty($post['variable']) && $post['variable'] !== '0';
        $actif = !isset($post['actif']) || $post['actif'] !== '0';
        $prix = json_decode((string)($post['prix'] ?? '{}'), true);
        if (!is_array($prix)) throw new RuntimeException('Prix invalides.');
        if (db_fetch_value("SELECT 1 FROM fin_services WHERE lower(libelle) = lower(?) AND id <> ?", [$lib, $id]))
            throw new RuntimeException("Un service « $lib » existe déjà.");
        db_begin();
        if ($id) {
            if (!db_query("UPDATE fin_services SET libelle = ?, variable = ?, actif = ?, maj_par = ?, maj_le = CURRENT_TIMESTAMP WHERE id = ?",
                          [$lib, $variable ? 't' : 'f', $actif ? 't' : 'f', $uid, $id])->rowCount())
                throw new RuntimeException('Service introuvable.');
        } else {
            db_query("INSERT INTO fin_services (libelle, variable, actif, ordre, maj_par)
                      VALUES (?, ?, ?, (SELECT COALESCE(MAX(ordre), 0) + 1 FROM fin_services), ?)",
                     [$lib, $variable ? 't' : 'f', $actif ? 't' : 'f', $uid]);
            $id = (int)db_fetch_value("SELECT id FROM fin_services WHERE libelle = ?", [$lib]);
        }
        db_query("DELETE FROM fin_service_prix WHERE service_id = ?", [$id]);
        $detail = [];
        if (!$variable) foreach (FIN_CATEGORIES as $cat => $libcat) {
            $brut = trim(str_replace(' ', '', (string)($prix[$cat] ?? '')));
            if ($brut === '') continue;
            $m = fin_entier($brut);
            if ($m === null || $m < 0 || $m > 10000000) throw new RuntimeException("Prix invalide pour $libcat.");
            db_query("INSERT INTO fin_service_prix (service_id, categorie, montant_ttc) VALUES (?, ?, ?)", [$id, $cat, $m]);
            $detail[] = "$libcat " . number_format($m, 0, ',', ' ');
        }
        db_commit();
        audit_log($uid, 'UPDATE', 'finance_dossiers', $id, "Service tarif « $lib » : "
            . ($variable ? 'montant variable' : ($detail ? implode(', ', $detail) : 'sans prix')) . ($actif ? '' : ' (inactif)'));
        $utilise = (bool)db_fetch_value("SELECT 1 FROM fin_formule_services WHERE service_id = ?", [$id]);
        return ["Service « $lib » enregistré.", $utilise];

    case 'service_supprimer':
        $lib = db_fetch_value("SELECT libelle FROM fin_services WHERE id = ?", [$id]);
        if ($lib === null) throw new RuntimeException('Service introuvable.');
        $utilise = (bool)db_fetch_value("SELECT 1 FROM fin_formule_services WHERE service_id = ?", [$id]);
        db_query("DELETE FROM fin_services WHERE id = ?", [$id]);
        audit_log($uid, 'DELETE', 'finance_dossiers', $id, "Service tarif « $lib » supprimé");
        return ["Service « $lib » supprimé.", $utilise];

    case 'formule_enregistrer':
        $lib = fin_txt($post['libelle'] ?? null, 120);
        if ($lib === null) throw new RuntimeException('Libellé de la formule obligatoire.');
        $liste = function (string $k, ?array $permis = null) use ($post): array {
            $v = json_decode((string)($post[$k] ?? '[]'), true);
            if (!is_array($v)) throw new RuntimeException('Données invalides.');
            $v = array_map(fn($x) => mb_substr(trim((string)$x), 0, 120), $v);
            return $permis === null ? $v : array_values(array_intersect($v, $permis));
        };
        $mode = isset(FIN_MODES_FORMULE[$post['mode'] ?? '']) ? $post['mode'] : 'site';
        $site_ids = array_values(array_unique(array_filter(array_map('intval', $liste('sites')))));
        if ($mode === 'site') {
            if (!$site_ids) throw new RuntimeException('Choisissez au moins un site, ou passez la formule en mode « par dossier ».');
            if ((int)db_fetch_value("SELECT COUNT(*) FROM sites WHERE id IN (" . implode(',', $site_ids) . ") AND " . FIN_SITES_TYPES_SQL) !== count($site_ids))
                throw new RuntimeException('Site inconnu, ou ni de pose ni mixte.');
            $types = [];
        } else {
            $site_ids = [];
            $types = $liste('types', FIN_TYPES);
        }
        $canaux = $liste('canaux', array_keys(FIN_CANAUX));
        $cats = $liste('categories', array_keys(FIN_CATEGORIES));
        $sids = array_values(array_unique(array_map('intval', $liste('services'))));
        if (!$sids) throw new RuntimeException('Choisissez au moins un service.');
        if ((int)db_fetch_value("SELECT COUNT(*) FROM fin_services WHERE id IN (" . implode(',', $sids) . ")") !== count($sids))
            throw new RuntimeException('Service inconnu.');
        $actif = !isset($post['actif']) || $post['actif'] !== '0';
        $p = [$lib, $mode, fin_pg_tableau($site_ids), fin_pg_tableau($canaux), fin_pg_tableau($types), fin_pg_tableau($cats), $actif ? 't' : 'f', $uid];
        db_begin();
        if ($id) {
            if (!db_query("UPDATE fin_formules SET libelle = ?, mode = ?, site_ids = ?::integer[], canaux = ?::text[], types = ?::text[],
                           categories = ?::text[], actif = ?, maj_par = ?, maj_le = CURRENT_TIMESTAMP WHERE id = ?", array_merge($p, [$id]))->rowCount())
                throw new RuntimeException('Formule introuvable.');
        } else {
            $id = (int)db_fetch_value("INSERT INTO fin_formules (libelle, mode, site_ids, canaux, types, categories, actif, maj_par)
                                       VALUES (?, ?, ?::integer[], ?::text[], ?::text[], ?::text[], ?, ?) RETURNING id", $p);
        }
        db_query("DELETE FROM fin_formule_services WHERE formule_id = ?", [$id]);
        foreach ($sids as $sid) db_query("INSERT INTO fin_formule_services (formule_id, service_id) VALUES (?, ?)", [$id, $sid]);
        db_commit();
        $c = fn(array $v) => $v ? implode(', ', $v) : 'tous';
        $noms = $site_ids ? array_column(db_fetch_all("SELECT nom FROM sites WHERE id IN (" . implode(',', $site_ids) . ") ORDER BY nom"), 'nom') : [];
        audit_log($uid, 'UPDATE', 'finance_dossiers', $id, "Formule tarif « $lib » (" . mb_strtolower(FIN_MODES_FORMULE[$mode]) . ') : '
            . ($mode === 'site' ? 'sites ' . $c($noms) : 'types ' . $c($types)) . ', canaux ' . $c($canaux) . ', catégories ' . $c($cats) . ', ' . count($sids) . ' service(s)' . ($actif ? '' : ' (inactive)'));
        return ["Formule « $lib » enregistrée.", true];

    case 'formule_supprimer':
        $lib = db_fetch_value("SELECT libelle FROM fin_formules WHERE id = ?", [$id]);
        if ($lib === null) throw new RuntimeException('Formule introuvable.');
        db_query("DELETE FROM fin_formules WHERE id = ?", [$id]);
        audit_log($uid, 'DELETE', 'finance_dossiers', $id, "Formule tarif « $lib » supprimée");
        return ["Formule « $lib » supprimée.", true];
    }
    throw new RuntimeException('Action inconnue.');
}

// ── Export Excel au format du tableau de bord finance ─────────

// Groupes de colonnes : [titre du groupe, [[en-tête, valeur, type, largeur], …]].
// La valeur est une colonne de fin_dossiers ou une fonction du dossier ;
// type : txt, num (montant) ou date.
function fin_export_colonnes(): array {
    $si = fn(string $drapeau, string $col) => fn(array $d) => $d[$drapeau] ? $d[$col] : null;
    return [
        // Identifiants présents pour tout dossier, même seulement posé : les
        // colonnes VIN des sections ne sont remplies qu'à l'étape concernée.
        ['Dossier', [
            ['VIN', 'vin', 'txt', 20],
            ['Immatriculation', 'immat', 'txt', 12],
        ]],
        ['Dossier ouvert CIL', [
            ["Date d'ouverture", $si('est_ouvert', 'date_ouverture'), 'date', 12],
            ['Date de paiement', $si('est_ouvert', 'date_paiement'), 'date', 12],
            ['Chrono', $si('est_ouvert', 'chrono'), 'txt', 16],
            ['VIN', $si('est_ouvert', 'vin'), 'txt', 20],
            ['Genre', fn($d) => $d['est_ouvert'] ? fin_categorie_libelle($d['genre']) : null, 'txt', 14],
            ['Client', $si('est_ouvert', 'client'), 'txt', 30],
            ['Montant plaques', $si('est_ouvert', 'mt_plaques'), 'num', 12],
            ['Montant sécurisation pose', $si('est_ouvert', 'mt_secupose'), 'num', 13],
            ['Montant', $si('est_ouvert', 'montant_dossier'), 'num', 12],
        ]],
        ['Dossier reçu', [
            ['Date de création', $si('est_recu', 'date_reception'), 'date', 12],
            ['Chrono (N° NSIIV si Reimmat)', fn($d) => $d['est_recu'] ? ($d['chrono'] ?: $d['nsiiv']) : null, 'txt', 18],
            ['N° NSIIV', $si('est_recu', 'nsiiv'), 'txt', 16],
            ['VIN', $si('est_recu', 'vin'), 'txt', 20],
            ['Genre', fn($d) => $d['est_recu'] ? fin_categorie_libelle($d['genre']) : null, 'txt', 14],
            ['Client', $si('est_recu', 'client'), 'txt', 30],
            ['Montant', $si('est_recu', 'montant_attendu'), 'num', 12],
        ]],
        ['', [
            ['Type enrôlement', fn($d) => FIN_CANAUX[$d['canal']] ?? null, 'txt', 12],
            ['Type de dossier', 'type_dossier', 'txt', 11],
        ]],
        ['Réconciliation', [
            ['VIN réconcilié', $si('est_reconcilie', 'vin'), 'txt', 20],
            ['Montant réconcilié', $si('est_reconcilie', 'montant_reconcilie'), 'num', 12],
            ['Date réconciliée', $si('est_reconcilie', 'date_reconciliation'), 'date', 12],
            ['Code rec.', 'code_rec', 'txt', 16],
            ['N° facture', 'n_facture', 'txt', 18],
            ['Payé par bon', fn($d) => $d['paye_par_bon'] ? 'Oui' : null, 'txt', 8],
        ]],
        ['Production', [
            ["Date d'installation", 'date_installation', 'date', 12],
            ['Statut', 'statut_brique', 'txt', 26],
            ['Plaque avant', 'plaque_avant', 'txt', 13],
            ['Plaque arrière', 'plaque_arriere', 'txt', 13],
            ['Date pose', 'date_pose', 'date', 12],
        ]],
        ['Contrôle', [
            ['Montant attendu', 'montant_attendu', 'num', 12],
            ['Écart', 'ecart', 'num', 10],
            ['Étape', fn($d) => FIN_ETAPES[$d['etape']] ?? null, 'txt', 12],
            ['Exceptions', fn($d) => implode(' ; ', array_keys(array_filter(
                array_combine(array_values(FIN_ANOMALIES), array_map(fn($k) => (bool)$d[$k], array_keys(FIN_ANOMALIES)))))), 'txt', 40],
        ]],
    ];
}

function fin_xlsx_lettre(int $i): string {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function fin_xlsx_cellule(string $ref, $v, string $type, int $style = 0): string {
    if ($v === null || $v === '' || $v === false) return '';
    if ($type === 'date') {
        $d = DateTime::createFromFormat('!Y-m-d', substr((string)$v, 0, 10), new DateTimeZone('UTC'));
        return $d ? "<c r=\"$ref\" s=\"3\"><v>" . (intdiv($d->getTimestamp(), 86400) + 25569) . '</v></c>' : '';
    }
    if ($type === 'num' && is_numeric($v)) return "<c r=\"$ref\" s=\"4\"><v>" . (0 + $v) . '</v></c>';
    $t = htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return "<c r=\"$ref\" t=\"inlineStr\"" . ($style ? " s=\"$style\"" : '') . "><is><t xml:space=\"preserve\">$t</t></is></c>";
}

// Écrit l'export des dossiers filtrés dans un .xlsx, ligne à ligne : plus de
// 200 000 dossiers ne tiennent pas en mémoire dans PhpSpreadsheet.
// Renvoie [chemin du fichier temporaire, nombre de dossiers].
function fin_exporter_xlsx(string $where, array $params): array {
    @set_time_limit(300);
    $groupes  = fin_export_colonnes();
    $colonnes = array_merge(...array_map(fn($g) => $g[1], $groupes));
    $derniere = fin_xlsx_lettre(count($colonnes) - 1);

    $feuille = tempnam(sys_get_temp_dir(), 'finx_');
    $f = fopen($feuille, 'wb');
    fwrite($f, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/><cols>');
    foreach ($colonnes as $i => $c) fwrite($f, '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $c[3] . '" customWidth="1"/>');
    fwrite($f, '</cols><sheetData>');

    // Ligne 1 : groupes (fusionnés) ; ligne 2 : en-têtes.
    $l1 = $l2 = ''; $fusions = []; $i = 0;
    foreach ($groupes as [$titre, $cols]) {
        $deb = $i;
        foreach ($cols as $c) {
            $ref = fin_xlsx_lettre($i);
            $l1 .= ($i === $deb && $titre !== '') ? fin_xlsx_cellule($ref . '1', $titre, 'txt', 1) : "<c r=\"{$ref}1\" s=\"1\"/>";
            $l2 .= fin_xlsx_cellule($ref . '2', $c[0], 'txt', 2);
            $i++;
        }
        if (count($cols) > 1 && $titre !== '') $fusions[] = fin_xlsx_lettre($deb) . '1:' . fin_xlsx_lettre($i - 1) . '1';
    }
    fwrite($f, '<row r="1" ht="20" customHeight="1">' . $l1 . '</row><row r="2" ht="30" customHeight="1">' . $l2 . '</row>');

    // Dossiers par lots de 5 000, du plus récent au plus ancien (pagination
    // par clé : date de référence puis clé du dossier).
    $r = 2; $curseur = null;
    do {
        $cond = $where; $p = $params;
        if ($curseur) {
            $cond .= " AND (COALESCE(date_ref, DATE '1900-01-01'), cle) < (CAST(? AS date), CAST(? AS varchar))";
            array_push($p, $curseur[0], $curseur[1]);
        }
        $lot = db_fetch_all("SELECT *, COALESCE(date_ref, DATE '1900-01-01') AS _tri FROM fin_dossiers WHERE $cond
                             ORDER BY COALESCE(date_ref, DATE '1900-01-01') DESC, cle DESC LIMIT 5000", $p);
        foreach ($lot as $d) {
            $r++;
            $x = '';
            foreach ($colonnes as $k => $c) {
                $v = is_callable($c[1]) ? $c[1]($d) : ($d[$c[1]] ?? null);
                $x .= fin_xlsx_cellule(fin_xlsx_lettre($k) . $r, $v, $c[2]);
            }
            fwrite($f, "<row r=\"$r\">$x</row>");
        }
        if ($lot) { $der = end($lot); $curseur = [$der['_tri'], $der['cle']]; }
    } while (count($lot) === 5000);

    fwrite($f, '</sheetData><autoFilter ref="A2:' . $derniere . max(2, $r) . '"/>');
    if ($fusions) fwrite($f, '<mergeCells count="' . count($fusions) . '">'
        . implode('', array_map(fn($m) => "<mergeCell ref=\"$m\"/>", $fusions)) . '</mergeCells>');
    fwrite($f, '</worksheet>');
    fclose($f);

    $xlsx = tempnam(sys_get_temp_dir(), 'finx_');
    $zip = new ZipArchive();
    if ($zip->open($xlsx, ZipArchive::OVERWRITE) !== true) { @unlink($feuille); throw new RuntimeException('Création du fichier Excel impossible.'); }
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $zip->addFromString('[Content_Types].xml', $xml
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', $xml
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', $xml
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Dossiers" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Dossiers!$A$2:$' . $derniere . '$' . max(2, $r) . '</definedName></definedNames>'
        . '</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $xml
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    // Styles : 1 groupe (blanc sur marine), 2 en-tête (marine sur bleu pâle),
    // 3 date jj/mm/aaaa, 4 montant avec séparateur de milliers.
    $bord = '<left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right>'
          . '<top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/>';
    $zip->addFromString('xl/styles.xml', $xml
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>'
        . '<fonts count="3"><font><sz val="10"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FF06033A"/><name val="Calibri"/></font></fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF06033A"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8ECF7"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>' . $bord . '</border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>');
    $zip->addFile($feuille, 'xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($feuille);
    return [$xlsx, $r - 2];
}

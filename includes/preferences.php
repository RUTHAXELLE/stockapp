<?php
// ============================================================
//  includes/preferences.php
//  Résolution des filtres d'affichage.
//
//  Les filtres ne vivaient que dans l'URL : partageables, jamais
//  retenus. Chacun reposait ses filtres à chaque ouverture, et le
//  défaut « tous les sites / mensuel » ne correspondait à aucun des
//  quatre profils de PRODUCT.md.
//
//  ── La chaîne de résolution ──
//    1. l'URL             — ce que l'utilisateur vient de demander
//    2. sa préférence     — ce qu'il avait choisi la dernière fois
//    3. le défaut du rôle — réglé en administration
//    4. le défaut du code — dernier recours, jamais absent
//
//  ── Pourquoi on n'enregistre pas à chaque URL portant des filtres ──
//  Un lien reçu d'un collègue porte SES filtres. Les enregistrer
//  écraserait silencieusement les vôtres : vous ouvrez un lien pour
//  regarder un site qui n'est pas le vôtre, et le lendemain votre
//  tableau de bord s'ouvre sur ce site-là sans que rien ne l'explique.
//  L'enregistrement n'a donc lieu que sur une interaction explicite,
//  signalée par le marqueur MARQUEUR_INTERACTION que les formulaires
//  posent eux-mêmes. Un lien partagé reste en lecture.
//
//  Modèle clé/valeur volontaire : les filtres diffèrent d'un écran à
//  l'autre et s'ajoutent avec le temps ; une colonne par filtre et par
//  écran ne tiendrait pas.
// ============================================================

const MARQUEUR_INTERACTION = '_p';

/**
 * Cache des préférences de l'utilisateur courant, rendu PAR RÉFÉRENCE.
 * Une écriture doit pouvoir le mettre à jour : sans cela, un filtre
 * enregistré puis relu dans la même requête ressortirait à son ancienne
 * valeur, et deux appels sur la même page se contrediraient.
 */
function &pref_cache(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $u = function_exists('current_user') ? current_user() : null;
        if ($u) {
            try {
                foreach (db_fetch_all(
                    "SELECT cle, valeur FROM preferences_utilisateur WHERE user_id = ?",
                    [(int)$u['id']]) as $r) $cache[$r['cle']] = $r['valeur'];
            } catch (Throwable $e) {
                // Migration pas encore passée : l'écran marche sans mémoire.
            }
        }
    }
    return $cache;
}

/** Toutes les préférences de l'utilisateur courant. */
function pref_toutes(): array { return pref_cache(); }

/** Défauts du rôle de l'utilisateur courant, lus une fois. */
function pref_defauts_role(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u) return $cache;
    try {
        foreach (db_fetch_all(
            "SELECT d.cle, d.valeur FROM defauts_affichage d
               JOIN roles r ON r.id = d.role_id
              WHERE r.slug = ?", [(string)($u['role_slug'] ?? '')]) as $r)
            $cache[$r['cle']] = $r['valeur'];
    } catch (Throwable $e) {
        // Idem : absence de table = pas de défaut de rôle, pas une erreur.
    }
    return $cache;
}

/** Enregistre une préférence. Silencieux si la table n'existe pas. */
function pref_ecrire(string $cle, string $valeur): void {
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u) return;
    try {
        db_query(
            "INSERT INTO preferences_utilisateur (user_id, cle, valeur)
             VALUES (?,?,?)
             ON CONFLICT (user_id, cle)
             DO UPDATE SET valeur = EXCLUDED.valeur, updated_at = CURRENT_TIMESTAMP",
            [(int)$u['id'], $cle, $valeur]);
        $c = &pref_cache();
        $c[$cle] = $valeur;
    } catch (Throwable $e) {
        // Écriture impossible : la page continue, sans mémoriser.
    }
}

/** L'utilisateur vient-il d'agir sur un filtre, ou suit-il un lien ? */
function pref_interaction(): bool {
    return !empty($_GET[MARQUEUR_INTERACTION]);
}

/**
 * Résout un filtre selon la chaîne URL → préférence → rôle → code,
 * et mémorise la valeur quand elle vient d'une interaction explicite.
 *
 * $valider reçoit la valeur candidate et retourne la valeur retenue,
 * ou null si elle est irrecevable. Sans ce filtre, une préférence
 * enregistrée avant un changement de vocabulaire (un site supprimé,
 * une granularité retirée) ressortirait telle quelle des années plus
 * tard, et l'écran s'ouvrirait sur un périmètre qui n'existe plus.
 */
function pref_filtre(string $cle, $depuis_url, callable $valider, $defaut) {
    $garde = fn($v) => $v === null ? null : $valider($v);

    if ($depuis_url !== null && $depuis_url !== '') {
        $v = $garde($depuis_url);
        if ($v !== null) {
            if (pref_interaction()) pref_ecrire($cle, is_array($v) ? implode(',', $v) : (string)$v);
            return $v;
        }
    }
    $memo = pref_toutes()[$cle] ?? null;
    if ($memo !== null) { $v = $garde($memo); if ($v !== null) return $v; }

    $role = pref_defauts_role()[$cle] ?? null;
    if ($role !== null) { $v = $garde($role); if ($v !== null) return $v; }

    return $defaut;
}

/**
 * Liste d'entiers depuis l'URL (sites[]=3&sites[]=7) ou depuis une
 * préférence mémorisée (« 3,7 »), restreinte aux valeurs autorisées.
 * Un tableau vide signifie « tout le périmètre », jamais « rien » :
 * c'est le sens que l'utilisateur donne à une case tout décochée.
 */
function pref_liste_ids($brut, array $autorises): ?array {
    if (is_string($brut)) $brut = $brut === '' ? [] : explode(',', $brut);
    if (!is_array($brut)) return null;
    $out = [];
    foreach ($brut as $v) {
        $i = (int)$v;
        if ($i > 0 && in_array($i, $autorises, true) && !in_array($i, $out, true)) $out[] = $i;
    }
    return $out;
}

/**
 * Fragment SQL « IN (…) » pour un filtre multi-valeurs, ou chaîne vide
 * si la liste est vide. Les identifiants sont castés en entier avant
 * interpolation : aucune valeur de l'URL n'atteint la requête telle
 * quelle.
 */
function pref_clause_in(string $colonne, array $ids): string {
    if (!$ids) return '';
    $l = implode(',', array_map('intval', $ids));
    return " AND $colonne IN ($l)";
}

// ============================================================
//  VUES ENREGISTRÉES
// ============================================================

/** Vues visibles par l'utilisateur : les siennes, plus les partagées. */
function vues_listees(string $ecran): array {
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u) return [];
    try {
        return db_fetch_all(
            "SELECT v.id, v.nom, v.filtres, v.partagee, v.user_id,
                    (v.user_id = ?) AS mienne,
                    TRIM(COALESCE(u.prenom,'') || ' ' || COALESCE(u.nom,'')) AS auteur
               FROM vues_enregistrees v
               JOIN users u ON u.id = v.user_id
              WHERE v.ecran = ? AND (v.user_id = ? OR v.partagee = 1)
              ORDER BY mienne DESC, v.nom",
            [(int)$u['id'], $ecran, (int)$u['id']]);
    } catch (Throwable $e) {
        return [];
    }
}

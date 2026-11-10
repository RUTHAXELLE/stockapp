<?php
// ============================================================
//  includes/demandes_champs.php — Config des champs par type de demande
//  Porté depuis CHAMPS_CONFIG (frontend React de emu-demandes).
//  Un seul moteur de rendu générique lit cette config.
//  (Phase 1 : Autorisation d'absence. Les autres types s'ajoutent ici.)
// ============================================================

function di_champs_config(): array {
    return [
        // Type de permission en premier : il porte le nombre de jours autorisés (entre parenthèses).
        // Choisir un type + saisir « Du » remplit automatiquement jours, « Au » et la reprise (JS
        // dans demandes_new.php). « — Aucune — » = saisie manuelle libre (congé/permission simple).
        'autorisation_absence' => [
            ['key'=>'agent_nom',     'label'=>"Nom & Prénoms de l'agent", 'type'=>'agent', 'required'=>true, 'section'=>"Identité de l'agent"],
            ['key'=>'agent_email',   'label'=>"Email de l'agent",         'type'=>'email'],
            ['key'=>'agent_fonction','label'=>"Fonction de l'agent",      'type'=>'text'],
            ['key'=>'type_permission','label'=>'Type de permission', 'type'=>'select', 'span'=>true,
             'options'=>[
                '' => '— Aucune (saisie manuelle) —',
                'Mariage du travailleur (4j)'      => 'Mariage du travailleur (4j)',
                "Mariage d'un enfant (2j)"         => "Mariage d'un enfant (2j)",
                'Mariage frère/sœur (2j)'          => 'Mariage frère/sœur (2j)',
                'Décès conjoint (5j)'              => 'Décès conjoint (5j)',
                'Décès enfant/père/mère (5j)'      => 'Décès enfant/père/mère (5j)',
                'Décès beau-père/belle-mère (2j)'  => 'Décès beau-père/belle-mère (2j)',
                "Naissance d'un enfant (2j)"       => "Naissance d'un enfant (2j)",
                "Baptême d'un enfant (2j)"         => "Baptême d'un enfant (2j)",
                'Première communion (1j)'          => 'Première communion (1j)',
                'Déménagement (1j)'                => 'Déménagement (1j)',
             ], 'section'=>"Absence demandée"],
            ['key'=>'date_debut',    'label'=>'Du (date de départ)',       'type'=>'date',   'required'=>true],
            ['key'=>'nb_jours',      'label'=>'Nombre de jours demandés',  'type'=>'number', 'required'=>true, 'suffixe'=>'jour(s), week-ends compris'],
            ['key'=>'date_fin',      'label'=>'Au (inclus)',               'type'=>'date',   'required'=>true],
            ['key'=>'date_reprise',  'label'=>'Date de reprise du service','type'=>'date',   'required'=>true],
        ],
        'creation_acces' => [
            ['key'=>'agent_nom',      'label'=>"Nom & Prénoms de l'agent", 'type'=>'agent', 'required'=>true, 'section'=>"Identité de l'agent"],
            // Volontairement facultatif : en création d'accès, l'agent n'a pas
            // toujours d'adresse, ou en a une qui n'est pas encore configurée
            // sur les plateformes NSIIV.
            ['key'=>'agent_email',    'label'=>"Email de l'agent",         'type'=>'email', 'editable_after_autofill'=>true, 'suffixe'=>"si l'agent en a déjà une"],
            ['key'=>'agent_fonction', 'label'=>"Fonction de l'agent",      'type'=>'text',  'required'=>true],
            ['key'=>'periode_acces',  'label'=>"Période d'accès",          'type'=>'daterange', 'span'=>true, 'section'=>"Accès demandé"],
            ['key'=>'departement',    'label'=>'Département',               'type'=>'text'],
            ['key'=>'responsable_hierarchique','label'=>'Responsable hiérarchique','type'=>'text'],
            ['key'=>'site',           'label'=>'Site',                     'type'=>'text',  'required'=>true],
            ['key'=>'date_demande',   'label'=>'Date de la demande',       'type'=>'date'],
            ['key'=>'plateformes',    'label'=>'Accès aux plateformes',    'type'=>'plateformes', 'span'=>true],
        ],
        'basculement_acces' => [
            ['key'=>'agent_nom',     'label'=>"Nom & Prénoms de l'agent", 'type'=>'agent', 'required'=>true, 'section'=>"Identité de l'agent"],
            ['key'=>'agent_email',   'label'=>"Email de l'agent",         'type'=>'email'],
            ['key'=>'agent_fonction','label'=>"Fonction de l'agent",      'type'=>'text'],
            ['key'=>'periode_acces', 'label'=>"Période d'accès",          'type'=>'daterange', 'span'=>true, 'section'=>"Accès demandé"],
            ['key'=>'site_origine',  'label'=>"Site d'origine",           'type'=>'site',  'required'=>true],
            ['key'=>'nouveau_site',  'label'=>'Nouveau site',             'type'=>'site',  'required'=>true],
            ['key'=>'ancien_role',   'label'=>'Ancien rôle',              'type'=>'text',  'required'=>true],
            ['key'=>'nouveau_role',  'label'=>'Nouveau rôle',             'type'=>'text',  'required'=>true],
            ['key'=>'plateformes',   'label'=>'Accès aux plateformes',    'type'=>'plateformes', 'span'=>true],
        ],
        'basculement_compte' => [
            ['key'=>'agent_nom',      'label'=>"Nom & Prénoms de l'agent", 'type'=>'agent', 'required'=>true, 'section'=>"Identité de l'agent"],
            ['key'=>'agent_email',    'label'=>"Email de l'agent",         'type'=>'email'],
            ['key'=>'agent_fonction', 'label'=>"Fonction de l'agent",      'type'=>'text'],
            ['key'=>'agent_matricule','label'=>"Matricule de l'agent",     'type'=>'text'],
            ['key'=>'site',           'label'=>'Site',                     'type'=>'text', 'required'=>true, 'section'=>"Poste et accès"],
            ['key'=>'ancien_poste',   'label'=>'Ancien poste',             'type'=>'text', 'required'=>true],
            ['key'=>'nouveau_poste',  'label'=>'Nouveau poste',            'type'=>'text', 'required'=>true],
            ['key'=>'login',          'label'=>"Nom d'utilisateur / Login",'type'=>'text'],
            ['key'=>'email_pro',      'label'=>'Adresse email professionnelle','type'=>'email'],
            ['key'=>'motif',          'label'=>'Motif du basculement',     'type'=>'select', 'required'=>true, 'span'=>true,
             'options'=>['' =>'— Choisir —', 'Mutation interne'=>'Mutation interne', 'Renfort temporaire'=>'Renfort temporaire',
                'Réorganisation des équipes'=>'Réorganisation des équipes', 'Autre'=>'Autre']],
            ['key'=>'applications',   'label'=>'Application(s) concernée(s)', 'type'=>'plateformes', 'span'=>true],
        ],
        'transfert_agent' => [
            ['key'=>'agent_nom',     'label'=>"Nom & Prénoms de l'agent", 'type'=>'agent', 'required'=>true, 'section'=>"Identité de l'agent"],
            ['key'=>'agent_email',   'label'=>"Email de l'agent",         'type'=>'email'],
            ['key'=>'agent_fonction','label'=>"Fonction de l'agent",      'type'=>'text'],
            ['key'=>'site_origine',  'label'=>"Site d'origine",           'type'=>'text', 'required'=>true, 'section'=>"Transfert demandé"],
            ['key'=>'nouveau_site',  'label'=>'Nouveau site',             'type'=>'text', 'required'=>true],
            ['key'=>'motif',         'label'=>'Motif',                    'type'=>'textarea', 'required'=>true, 'span'=>true],
            ['key'=>'type_transfert','label'=>'Type de transfert',        'type'=>'select', 'required'=>true,
             'options'=>['' =>'— Choisir —', 'Temporaire'=>'Temporaire', 'Définitif'=>'Définitif']],
            ['key'=>'duree',         'label'=>'Durée (si temporaire)',    'type'=>'text'],
            ['key'=>'date_effet',    'label'=>"Date de prise d'effet",    'type'=>'date', 'required'=>true],
        ],
        'creation_site' => [
            ['key'=>'nom_site',    'label'=>'Nom du site',   'type'=>'text', 'required'=>true, 'section'=>"Site à créer"],
            ['key'=>'localisation','label'=>'Localisation',  'type'=>'text', 'required'=>true],
            ['key'=>'usage',       'label'=>'Usage',         'type'=>'select',
             'options'=>['' =>'— Choisir —', 'POSE'=>'POSE', 'SAISIE'=>'SAISIE', 'POSE et SAISIE'=>'POSE et SAISIE']],
            ['key'=>'operation',   'label'=>'Opération',     'type'=>'text'],
            ['key'=>'duree',       'label'=>'Durée',         'type'=>'text'],
            ['key'=>'timeout',     'label'=>'Timeout',       'type'=>'text'],
            ['key'=>'longitude',   'label'=>'Longitude',     'type'=>'text'],
            ['key'=>'latitude',    'label'=>'Latitude',      'type'=>'text'],
        ],
        'imputation_courrier' => [
            ['key'=>'reference',      'label'=>'Référence du courrier', 'type'=>'text', 'section'=>"Courrier reçu"],
            ['key'=>'date_reception', 'label'=>'Date de réception',     'type'=>'date'],
            ['key'=>'expediteur',     'label'=>'Expéditeur',            'type'=>'text', 'required'=>true],
            ['key'=>'objet',          'label'=>'Objet du courrier',     'type'=>'text', 'required'=>true, 'span'=>true],
            ['key'=>'resume',         'label'=>'Résumé du contenu',     'type'=>'textarea', 'span'=>true],
            ['key'=>'service_impute', 'label'=>'Département / Service imputé', 'type'=>'select_dept', 'required'=>true, 'span'=>true, 'section'=>"Imputation"],
            ['key'=>'motif_imputation','label'=>"Motif de l'imputation",'type'=>'text', 'span'=>true],
            ['key'=>'priorite',       'label'=>'Niveau de priorité',    'type'=>'select',
             'options'=>['' =>'— Choisir —', 'Urgent'=>'Urgent', 'Normal'=>'Normal', 'Faible'=>'Faible']],
            ['key'=>'type_traitement','label'=>'Type de traitement attendu','type'=>'select',
             'options'=>['' =>'— Choisir —', 'Réponse'=>'Réponse', 'Analyse'=>'Analyse', 'Classement'=>'Classement', 'Suivi'=>'Suivi', 'Autre'=>'Autre']],
            ['key'=>'echeance',       'label'=>'Échéance de traitement', 'type'=>'date'],
        ],
        'exceptionnel' => [
            ['key'=>'agent_nom',     'label'=>"Nom & Prénoms de l'agent",   'type'=>'agent',    'required'=>true, 'section'=>"Identité de l'agent"],
            ['key'=>'agent_email',   'label'=>"Email de l'agent",           'type'=>'email'],
            ['key'=>'agent_fonction','label'=>"Fonction de l'agent",        'type'=>'text'],
            ['key'=>'description',   'label'=>'Description / Justification','type'=>'textarea', 'required'=>true, 'span'=>true, 'section'=>"Objet de la demande"],
            ['key'=>'date_souhaitee','label'=>'Date souhaitée',             'type'=>'date'],
            ['key'=>'date_retour',   'label'=>'Date de retour (incluse)',   'type'=>'date'],
            ['key'=>'heure_retour',  'label'=>'Heure de retour',            'type'=>'time'],
        ],
        'changement_geolocalisation' => [
            // Un seul bandeau ici : les champs alternent l'actuel et le
            // nouveau, une coupure en deux parties les décrirait mal.
            ['key'=>'ancien_nom_site',      'label'=>'Site actuel',        'type'=>'select_site', 'required'=>true, 'section'=>"Coordonnées du site"],
            ['key'=>'ancienne_localisation','label'=>'Adresse actuelle',   'type'=>'text'],
            ['key'=>'nouvelle_localisation','label'=>'Nouvelle adresse',   'type'=>'text'],
            ['key'=>'ancienne_longitude',   'label'=>'Longitude actuelle', 'type'=>'text'],
            ['key'=>'ancienne_latitude',    'label'=>'Latitude actuelle',  'type'=>'text'],
            ['key'=>'nouvelle_longitude',   'label'=>'Nouvelle longitude', 'type'=>'text'],
            ['key'=>'nouvelle_latitude',    'label'=>'Nouvelle latitude',  'type'=>'text'],
            ['key'=>'date_effet',           'label'=>"Date d'effet",       'type'=>'date'],
            ['key'=>'motif',                'label'=>'Motif du changement','type'=>'select',
             'options'=>['' =>'— Choisir —', 'Erreur de saisie'=>'Erreur de saisie', 'Déménagement'=>'Déménagement', 'Réorganisation'=>'Réorganisation', 'Autre'=>'Autre']],
            ['key'=>'commentaires',         'label'=>'Commentaires',       'type'=>'textarea', 'span'=>true],
        ],
    ];
}

// Formulaire générique par défaut (types créés via l'admin, sans config dédiée)
function di_champs_defaut(): array {
    return [
        ['key'=>'objet',   'label'=>'Objet de la demande', 'type'=>'text',     'required'=>true],
        ['key'=>'details', 'label'=>'Détails',             'type'=>'textarea', 'required'=>true, 'span'=>true],
    ];
}

// Champs d'un type (formulaire générique par défaut si pas de config dédiée)
function di_champs_of(string $typeCode): array {
    return di_champs_config()[$typeCode] ?? di_champs_defaut();
}

// Rendu HTML d'un champ de formulaire (moteur générique)
/**
 * Le formulaire entier, rendu en document réglé.
 *
 * La version précédente posait chaque champ dans une boîte flottante, sur une
 * grille à deux colonnes. Deux conséquences mesurées : la bordure des champs
 * était à 1,17:1 sur le fond de la carte, donc invisible là où la norme
 * demande 3:1 pour la limite d'un contrôle ; et tous les champs avaient le
 * même poids, si bien qu'un nombre de jours occupait la même boîte de 430 px
 * qu'un nom complet.
 *
 * Ici, la trame du document porte la limite : chaque champ est une ligne
 * réglée, libellé à gauche et saisie à droite, et la saisie n'a plus de
 * boîte à elle. C'est aussi la forme du document imprimé que la demande
 * produit, ce qui rapproche l'écran de ce que le visa signera.
 *
 * Une entrée qui porte la clé « section » ouvre un bandeau avant elle.
 */
function di_render_form(array $fields, array $valeurs = []): string {
    ob_start();
    echo '<div class="di-doc">';
    foreach ($fields as $f) {
        if (!empty($f['section'])) {
            echo '<h3 class="di-band">' . h($f['section']) . '</h3>';
        }
        echo di_render_field($f, $valeurs[$f['key']] ?? '');
    }
    echo '</div>';
    return ob_get_clean();
}

function di_render_field(array $f, $value = ''): string {
    $key   = h($f['key']);
    $label = h($f['label']);
    // abbr plutôt qu'une étoile nue : lue « obligatoire » et non « astérisque ».
    $req   = !empty($f['required'])
           ? ' <abbr class="di-req" title="Champ obligatoire">*</abbr>' : '';
    $reqA  = !empty($f['required']) ? ' required' : '';
    $type     = $f['type'] ?? 'text';
    $v        = is_scalar($value) ? h((string)$value) : '';
    $editable = !empty($f['editable_after_autofill']) ? ' data-editable="1"' : '';

    // Seul le contenu réellement haut pose son libellé au-dessus : sinon la
    // colonne de libellé resterait vide sur toute la hauteur du bloc.
    //
    // La clé « span » ne suffit pas à en décider. Elle date de la grille à
    // deux colonnes, où elle voulait dire « prend toute la largeur » ; une
    // liste déroulante la porte, et se retrouvait traitée comme un bloc de
    // texte libre, seule ligne du document à rompre la trame.
    $haut = in_array($type, ['textarea', 'plateformes'], true);
    $cls   = 'di-row' . ($haut ? ' di-row-haut' : '');

    ob_start();
    echo "<div class=\"$cls\"><div class=\"di-lbl\"><label for=\"f_$key\">$label$req</label></div>"
       . '<div class="di-val">';
    if ($type === 'textarea') {
        echo "<textarea id=\"f_$key\" name=\"champs[$key]\" rows=\"3\"$reqA>$v</textarea>";
    } elseif ($type === 'select') {
        echo "<select id=\"f_$key\" name=\"champs[$key]\"$reqA>";
        foreach (($f['options'] ?? []) as $ov => $ol) {
            $sel = ((string)$value === (string)$ov) ? ' selected' : '';
            echo '<option value="'.h((string)$ov).'"'.$sel.'>'.h((string)$ol).'</option>';
        }
        echo "</select>";
    } elseif ($type === 'daterange') {
        $vd = is_array($value) ? h((string)($value['debut'] ?? '')) : '';
        $vf = is_array($value) ? h((string)($value['fin'] ?? '')) : '';
        // Deux dates côte à côte, chacune avec son propre libellé visible.
        // Une flèche entre deux champs identiques laisse deviner lequel est
        // lequel, et sur écran étroit les deux ne tenaient pas sur la ligne :
        // la seconde date sortait de la carte.
        echo '<div class="di-plage">'
           . "<span class=\"di-plage-part\"><label for=\"f_$key\">Du</label>"
           . "<input type=\"date\" id=\"f_$key\" name=\"champs[$key][debut]\" value=\"$vd\"></span>"
           . "<span class=\"di-plage-part\"><label for=\"f_{$key}_fin\">au</label>"
           . "<input type=\"date\" id=\"f_{$key}_fin\" name=\"champs[$key][fin]\" value=\"$vf\"></span></div>";
    } elseif ($type === 'select_site') {
        echo "<select id=\"f_$key\" name=\"champs[$key]\"$reqA><option value=\"\">— Choisir un site —</option>";
        foreach (db_fetch_all("SELECT nom FROM sites WHERE actif=1 ORDER BY nom") as $s) {
            $sel = ((string)$value === (string)$s['nom']) ? ' selected' : '';
            echo '<option value="'.h($s['nom']).'"'.$sel.'>'.h($s['nom']).'</option>';
        }
        echo "</select>";
    } elseif ($type === 'plateformes') {
        $sel = is_array($value) ? $value : [];
        echo '<div class="di-plats">';
        foreach (di_plateformes() as $p) {
            $ck = in_array($p['code'], $sel, true) ? ' checked' : '';
            echo '<label class="di-plat">'
               . '<input type="checkbox" name="champs['.$key.'][]" value="'.h($p['code']).'"'.$ck.'>'
               . h($p['label']).'</label>';
        }
        echo '</div>';
    } elseif ($type === 'agent') {
        // Autocomplete sur la table agents (importée via Excel) — alimente email, téléphone,
        // fonction, matricule, departement, direction, site via data-* + JS dans demandes_new.php
        echo "<input type=\"text\" id=\"f_$key\" name=\"champs[$key]\" value=\"$v\" list=\"dl_$key\" autocomplete=\"off\" data-agentfill=\"1\"$reqA>";
        echo "<datalist id=\"dl_$key\">";
        foreach (db_fetch_all("SELECT id, nom, prenom, email, telephone, fonction, departement, direction, site, matricule FROM agents WHERE statut='actif' ORDER BY nom, prenom") as $a) {
            $full = h(trim(($a['nom'] ?? '') . ' ' . ($a['prenom'] ?? '')));
            echo '<option value="' . $full . '"'
               . ' data-email="'       . h((string)($a['email']       ?? '')) . '"'
               . ' data-telephone="'   . h((string)($a['telephone']   ?? '')) . '"'
               . ' data-fonction="'    . h((string)($a['fonction']    ?? '')) . '"'
               . ' data-matricule="'   . h((string)($a['matricule']   ?? '')) . '"'
               . ' data-departement="' . h((string)($a['departement'] ?? '')) . '"'
               . ' data-direction="'   . h((string)($a['direction']   ?? '')) . '"'
               . ' data-site="'        . h((string)($a['site']        ?? '')) . '"'
               . '></option>';
        }
        echo "</datalist>";
    } elseif ($type === 'select_dept') {
        echo "<select id=\"f_$key\" name=\"champs[$key]\"$reqA>";
        echo "<option value=\"\">— Choisir un département —</option>";
        foreach (db_fetch_all("SELECT label FROM departements ORDER BY label") as $d) {
            $sel = ((string)$value === $d['label']) ? ' selected' : '';
            echo '<option value="'.h($d['label']).'"'.$sel.'>'.h($d['label']).'</option>';
        }
        echo "</select>";
    } elseif ($type === 'site') {
        // Liste déroulante recherchable des sites actifs (saisie libre autorisée)
        echo "<input type=\"text\" id=\"f_$key\" name=\"champs[$key]\" value=\"$v\" list=\"dl_$key\" autocomplete=\"off\"$reqA>";
        echo "<datalist id=\"dl_$key\">";
        foreach (db_fetch_all("SELECT nom FROM sites WHERE actif=1 ORDER BY nom") as $s) {
            echo '<option value="'.h($s['nom']).'"></option>';
        }
        echo "</datalist>";
    } else {
        $t = in_array($type, ['number','date','email','time']) ? $type : 'text';
        // La donnée décide de la largeur, pas la grille. Un nombre de jours
        // dans une boîte de 430 px annonce qu'on attend un paragraphe.
        $court = in_array($t, ['number', 'date', 'time'], true) ? ' class="di-court"' : '';
        $min   = ($t === 'number') ? ' min="1" step="1" inputmode="numeric"' : '';
        echo "<input type=\"$t\" id=\"f_$key\" name=\"champs[$key]\" value=\"$v\"$court$min$reqA$editable>";
        if (!empty($f['suffixe'])) {
            echo '<span class="di-suffixe">' . h($f['suffixe']) . '</span>';
        }
    }
    echo '</div></div>';
    return ob_get_clean();
}

// ── Affichage lisible d'une valeur de champ (fiche détail + PDF)
function di_display_value(array $f, $value): string {
    $type = $f['type'] ?? 'text';
    if ($type === 'daterange') {
        if (!is_array($value)) return '';
        $d = $value['debut'] ?? ''; $fi = $value['fin'] ?? '';
        return ($d || $fi) ? h("$d → $fi") : '';
    }
    if ($type === 'plateformes') {
        if (!is_array($value) || !$value) return '';
        $labels = [];
        foreach (di_plateformes() as $p) if (in_array($p['code'], $value, true)) $labels[] = $p['label'];
        return h(implode(', ', $labels));
    }
    if ($type === 'select' && isset($f['options'][$value])) return h((string)$f['options'][$value]);
    return h((string)$value);
}

// Une valeur de champ est-elle "vide" (pour masquer les lignes vides) ?
function di_value_empty($value): bool {
    if (is_array($value)) return count(array_filter($value, fn($x) => $x !== '' && $x !== null)) === 0;
    return $value === '' || $value === null;
}

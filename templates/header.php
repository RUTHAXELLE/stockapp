<?php
// ============================================================
//  templates/header.php

// CSP permissive — compatible Chart.js (unsafe-eval) + Google Fonts
if (!headers_sent()) {
    header_remove('Content-Security-Policy');
    header("Content-Security-Policy: default-src * 'unsafe-inline' 'unsafe-eval' data: blob:; script-src * 'unsafe-inline' 'unsafe-eval' blob: data:; style-src * 'unsafe-inline' data:; font-src * data:; img-src * data: blob:;");
}
// ============================================================

$user  = current_user();
$notifs = notif_get_unread($user['id']);
$unread = count($notifs);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($page_title ?? 'Dashboard') ?> — <?= APP_NAME ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    /* Un changement de filtre (site, statut…) recharge la page en GET classique :
       sans ceci, le navigateur affiche un blanc le temps du aller-retour serveur.
       Avec, il fait un fondu entre l'ancienne et la nouvelle page — la navigation
       reste une vraie navigation, seul le rendu change. Ignoré sans effet de bord
       par les navigateurs qui ne le connaissent pas encore. */
    @view-transition {
      navigation: auto;
    }
    /* ===== DESIGN SYSTEM v4 — Palette Soft UI ===== */
    :root {
      /* Brand */
      --primary:    #7C92FF;
      /* -d = variante « texte lisible » : marque et texte ont des exigences de
         contraste différentes (AA texte = 4.5:1 sur fond clair), donc deux
         rôles distincts plutôt qu'une seule teinte réutilisée partout.
         #7C92FF ne passe pas (2.83:1) ; #3D4FD1 passe (6.49:1) en gardant la
         même teinte. Ne remplace jamais --primary : l'icône active de la
         sidebar (fond sombre) a besoin de la teinte claire, qui y passe déjà
         (4.94:1) — la foncer inverserait le problème. */
      --primary-d:  #3D4FD1;
      --primary-l:  #E8ECFF;
      --secondary:  #A5D8FF;
      --secondary-l:#E8F5FF;
      --tertiary:   #F0F4FF;
      --neutral:    #64748B;

      /* Aliases (compatibilité pages existantes) */
      --navy:       #1E2B4A;
      --blue:       #7C92FF;
      --blue-mid:   #5B76FF;
      --blue-light: #7C92FF;
      --blue-pale:  #E8ECFF;
      --accent:     #7C92FF;
      --accent-d:   #5B76FF;
      --mist:       #A5D8FF;
      --light:      #E8ECFF;
      --lighter:    #F0F4FF;
      --white:      #ffffff;
      --text:       #1E2B4A;
      --muted:      #4B5563;
      --border:     #E2E8F0;
      --danger:     #F87171;
      --success:    #34D399;
      --warning:    #FBBF24;
      --info:       #7C92FF;
      /* Variantes « texte lisible » des couleurs d'état, même logique que
         --primary-d ci-dessus : #F87171/#34D399/#FBBF24 ne passent pas AA en
         texte sur fond clair (2.77 / 1.92 / 1.67:1). À utiliser pour tout
         color: sur fond clair, ou tout fond plein avec texte blanc dessus
         (bouton, pastille) ; --danger/--success/--warning restent la couleur
         « marque » (bordures, puces, fonds décoratifs pâles). */
      --danger-d:   #C0392B;
      --success-d:  #0A7A52;
      --warning-d:  #8A5A00;
      --sidebar-w:  252px;
      --topbar-h:   64px;
      --radius:     16px;
      --radius-sm:  10px;
      --shadow:     0 4px 24px rgba(124,146,255,.10);
      --shadow-md:  0 8px 32px rgba(124,146,255,.15);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Manrope', sans-serif;
      background: var(--tertiary);
      color: var(--text);
      display: flex;
      min-height: 100vh;
    }

    /* ===== SIDEBAR ===== */
    .sidebar {
      width: var(--sidebar-w);
      background: var(--navy);
      height: 100vh;
      position: fixed; top: 0; left: 0; z-index: 100;
      display: flex; flex-direction: column;
      overflow-y: auto; overflow-x: hidden; scroll-behavior: auto;
      transition: width .25s ease;
      box-shadow: 4px 0 24px rgba(30,43,74,.12);
    }

    .sidebar-brand {
      padding: 22px 20px 18px;
      border-bottom: 1px solid rgba(255,255,255,.08);
      display: flex; align-items: center; gap: 12px;
    }
    .brand-logo {
      width: 42px; height: 42px; border-radius: 14px;
      background: linear-gradient(135deg, var(--primary), #00aeef);
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; flex-shrink: 0;
      box-shadow: 0 4px 14px rgba(124,146,255,.4);
    }
    .brand-text p {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 16px; font-weight: 800; color: white;
      letter-spacing: -.3px; margin: 0;
    }
    .brand-text span { font-size: 12px; color: rgba(165,216,255,.6); }

    .sidebar-nav { flex: 1; padding: 12px 0; }

    .nav-section {
      padding: 16px 20px 5px;
      font-size: 12px; font-weight: 700; letter-spacing: 1.4px;
      color: rgba(255,255,255,.35);
      text-transform: uppercase;
    }

    .nav-item {
      display: flex; align-items: center; gap: 11px;
      padding: 10px 16px;
      margin: 2px 10px;
      color: rgba(255,255,255,.7);
      text-decoration: none;
      font-size: 13.5px; font-weight: 500;
      border-radius: 12px;
      border-left: none;
      transition: background-color .18s cubic-bezier(.4,0,.2,1), border-color .18s cubic-bezier(.4,0,.2,1), color .18s cubic-bezier(.4,0,.2,1), box-shadow .18s cubic-bezier(.4,0,.2,1), transform .18s cubic-bezier(.4,0,.2,1), opacity .18s cubic-bezier(.4,0,.2,1);
    }
    .nav-item:hover {
      color: white;
      background: rgba(255,255,255,.08);
    }
    .nav-item.active {
      color: var(--navy);
      background: white;
      font-weight: 700;
      box-shadow: 0 2px 12px rgba(0,0,0,.12);
    }
    .nav-item.active i { color: var(--primary-d); }
    .nav-item .nav-icon {
      width: 34px; height: 34px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      border-radius: 10px;
      background: rgba(255,255,255,.1);
      transition: background-color .18s, border-color .18s, color .18s, box-shadow .18s, transform .18s, opacity .18s;
    }
    .nav-item .nav-icon i { font-size: 17px; line-height: 1; color: white; transition: color .18s; }
    .nav-item:hover .nav-icon { background: rgba(255,255,255,.18); }
    .nav-item.active .nav-icon { background: var(--primary-d); box-shadow: 0 4px 12px rgba(124,146,255,.5); }
    .nav-item.active .nav-icon i { color: white; }
    .nav-item.nav-equip.active .nav-icon { background: rgba(124,146,255,.3); box-shadow: none; }
    .nav-item.nav-equip.active {
      color: var(--navy);
      background: rgba(255,255,255,.12);
      box-shadow: none;
    }
    .nav-item.nav-equip.active i { color: var(--primary-d); }
    .nav-badge {
      margin-left: auto;
      background: var(--primary-d);
      color: white; font-size: 12px; font-weight: 700;
      padding: 2px 7px; border-radius: 20px;
    }

    .sidebar-footer {
      padding: 14px 16px;
      border-top: 1px solid rgba(255,255,255,.08);
      margin: 0 0 4px;
    }
    .user-card {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px;
      border-radius: 12px;
      background: rgba(255,255,255,.06);
    }
    .user-card-link {
      display: flex; align-items: center; gap: 10px;
      flex: 1; min-width: 0;
      text-decoration: none; border-radius: 8px;
      padding: 4px 6px; margin: -4px -6px;
      transition: background .15s;
    }
    .user-card-link:hover { background: rgba(255,255,255,.10); }
    .user-avatar {
      width: 36px; height: 36px; border-radius: 10px;
      background: var(--primary-d);
      display: flex; align-items: center; justify-content: center;
      color: white; font-size: 13px; font-weight: 700; flex-shrink: 0;
    }
    .user-info { flex: 1; min-width: 0; }
    .user-info .name { font-size: 12.5px; font-weight: 700; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .user-info .role { font-size: 10.5px; color: rgba(165,216,255,.6); margin-top: 1px; }
    .logout-btn { color: rgba(255,255,255,.55); text-decoration: none; font-size: 18px; transition: color .15s, background .15s; flex-shrink: 0; width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; }
    .logout-btn:hover { color: #f87171; background: rgba(248,113,113,.12); }

    /* ===== MAIN AREA ===== */
    .main-wrap {
      margin-left: var(--sidebar-w);
      flex: 1;
      display: flex; flex-direction: column;
      min-height: 100vh;
      /* Item flex : sans min-width:0 il garde min-width:auto et refuse de
         descendre sous la largeur de son contenu. Un tableau large ou une
         barre d'onglets nombreuse l'étirait alors au-delà du viewport, et
         c'était toute l'application — barre du haut comprise — qui partait
         en défilement horizontal, au lieu du seul bloc concerné. */
      min-width: 0;
    }

    /* ===== TOP BAR ===== */
    .topbar {
      height: var(--topbar-h);
      background: white;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center;
      padding: 0 28px;
      gap: 16px;
      position: sticky; top: 0; z-index: 50;
      box-shadow: 0 1px 8px rgba(124,146,255,.06);
    }
    .topbar-title {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 17px; font-weight: 700; margin: 0;
      color: var(--navy); flex: 1; min-width: 0;
      /* Pas de white-space:nowrap ici : la sous-ligne <small> (dashboard.php,
         dashboard_legacy.php) est un bloc à part, un nowrap sur le parent la
         forcerait sur la même ligne. min-width:0 suffit à corriger le bug
         (flex item qui refuse de rétrécir sous son contenu, cf. .main-wrap
         plus haut) : le titre repasse à la ligne plutôt que de pousser les
         actions de la barre du haut hors du viewport. */
    }
    .topbar-title small {
      display: block;
      font-family: 'Manrope', sans-serif;
      font-size: 12px; font-weight: 400; color: var(--muted);
      margin-top: 1px;
    }
    .topbar-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }

    .notif-btn {
      position: relative;
      width: 44px; height: 44px; border-radius: 12px;
      border: 1.5px solid var(--border);
      background: var(--tertiary);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: 18px;
      transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;
    }
    .notif-btn:hover { background: var(--primary-l); border-color: var(--primary-d); }
    .notif-count {
      position: absolute; top: -5px; right: -5px;
      background: var(--danger-d); color: white;
      font-size: 12px; font-weight: 700;
      width: 18px; height: 18px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
    }

    /* ===== NOTIFICATION DROPDOWN ===== */
    .notif-dropdown {
      display: none;
      position: absolute; top: calc(var(--topbar-h) + 4px); right: 28px;
      width: 340px;
      background: white;
      border: 1.5px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow-md);
      z-index: 200;
    }
    .notif-dropdown.open { display: block; animation: dropDown .2s ease; }
    @keyframes dropDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
    .notif-header {
      padding: 16px 18px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .notif-header h4 { font-size: 14px; font-weight: 700; color: var(--navy); font-family: 'Plus Jakarta Sans',sans-serif; }
    .notif-header a  { font-size: 12px; color: var(--primary-d); text-decoration: none; font-weight: 600; }
    .notif-list { max-height: 320px; overflow-y: auto; }
    .notif-item { padding: 12px 18px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background .15s; }
    .notif-item:hover { background: var(--tertiary); }
    .notif-item:last-child { border-bottom: none; }
    .notif-item .n-titre { font-size: 13px; font-weight: 600; color: var(--navy); }
    .notif-item .n-date  { font-size: 12px; color: var(--muted); margin-top: 3px; }
    .notif-empty { padding: 28px; text-align: center; color: var(--muted); font-size: 13px; }

    /* ===== PAGE CONTENT ===== */
    /* min-width:0 : même raison que .main-wrap ci-dessus, un niveau plus bas.
       Flex item par défaut refuse de descendre sous la largeur de son
       contenu (min-width:auto) — un tableau large dans .page-content
       l'étirait donc au-delà du viewport, entraînant tout .main-wrap (barre
       du haut comprise) dans le débordement au lieu de rester contenu par
       le overflow-x:auto de .table-wrap. */
    .page-content { flex: 1; min-width: 0; padding: 28px; }

    /* ===== BADGES ===== */
    .badge { padding: 4px 11px; border-radius: 20px; font-size: 11.5px; font-weight: 600; letter-spacing: .2px; }
    .badge-success { background: #D1FAE5; color: #065F46; }
    .badge-info    { background: var(--primary-l); color: var(--primary-d); }
    .badge-warning { background: #FEF3C7; color: #92400E; }
    .badge-danger  { background: #FEE2E2; color: #991B1B; }
    .badge-dark    { background: #F1F5F9; color: var(--neutral); }
    .badge-primary { background: var(--primary-l); color: var(--primary-d); }

    /* ===== CARDS ===== */
    .card {
      background: white;
      border-radius: var(--radius);
      border: 1.5px solid var(--border);
      overflow: hidden;
      box-shadow: var(--shadow);
    }
    .card-header {
      padding: 18px 22px;
      border-bottom: 1.5px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .card-header h3 {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 15px; font-weight: 700; color: var(--navy);
    }
    .card-body { padding: 22px; }

    /* ===== TABLES ===== */
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th {
      background: var(--tertiary) !important;
      color: var(--muted) !important;
      padding: 12px 16px;
      font-size: 12px; font-weight: 700; letter-spacing: .8px;
      text-align: left;
      border-bottom: 1.5px solid var(--border);
      white-space: nowrap;
      text-transform: uppercase;
      font-family: 'Manrope', sans-serif;
    }
    td {
      padding: 13px 16px;
      font-size: 13.5px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
      color: var(--text);
    }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: var(--tertiary); }

    /* ===== BUTTONS ===== */
    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 7px;
      padding: 10px 20px;
      min-height: 44px; /* plancher tactile — cf. n° réunion ERP « adapt » */
      border-radius: var(--radius-sm);
      font-family: 'Manrope', sans-serif;
      font-size: 13px; font-weight: 700;
      cursor: pointer; border: none; text-decoration: none;
      transition: background-color .18s cubic-bezier(.4,0,.2,1), border-color .18s cubic-bezier(.4,0,.2,1), color .18s cubic-bezier(.4,0,.2,1), box-shadow .18s cubic-bezier(.4,0,.2,1), transform .18s cubic-bezier(.4,0,.2,1), opacity .18s cubic-bezier(.4,0,.2,1);
      letter-spacing: .1px;
    }
    .btn-primary   { background: var(--primary-d); color: white; box-shadow: 0 2px 12px rgba(124,146,255,.35); }
    .btn-primary:hover { background: var(--primary-d); box-shadow: 0 4px 16px rgba(124,146,255,.45); transform: translateY(-1px); }
    .btn-secondary { background: white; color: var(--navy); border: 1.5px solid var(--border); }
    .btn-secondary:hover { background: var(--tertiary); border-color: var(--primary-d); color: var(--primary-d); }
    .btn-danger    { background: #FEE2E2; color: #991B1B; border: 1.5px solid #FCA5A5; }
    .btn-danger:hover  { background: var(--danger-d); color: white; border-color: var(--danger-d); }
    .btn-success   { background: #D1FAE5; color: #065F46; border: 1.5px solid #6EE7B7; }
    .btn-success:hover { background: var(--success-d); color: white; border-color: var(--success-d); }
    /* Exception volontaire au plancher de 44px : action secondaire répétée
       dans une colonne « Actions » de tableau (plusieurs par ligne, cf.
       equipements.php, bobines.php, commandes.php...). Un plancher de 44px
       y ferait exploser la hauteur de chaque ligne dans un tableau déjà
       dense. À revoir si l'app doit un jour cibler l'usage tactile plutôt
       que le clic souris pour ces écrans. */
    .btn-sm { padding: 6px 13px; font-size: 12px; border-radius: 8px; min-height: 32px; }

    /* ===== FORMS ===== */
    .form-row { display: grid; gap: 16px; margin-bottom: 16px; }
    .form-row.cols-2 { grid-template-columns: 1fr 1fr; }
    .form-row.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
    .form-group label {
      display: block; font-size: 12.5px; font-weight: 700;
      margin-bottom: 7px; color: var(--navy);
      font-family: 'Manrope', sans-serif; letter-spacing: .1px;
    }
    /* Marquage automatique des champs obligatoires : un seul endroit à tenir
       à jour plutôt qu'un <span>*</span> à ajouter à la main dans chaque
       formulaire (fait dans quelques pages seulement jusqu'ici). Ne s'affiche
       que pour l'attribut required natif — les champs obligatoires
       uniquement en JS (upload, motif conditionnel…) gardent leur marquage
       manuel existant, non concerné par cette règle. */
    .form-group:has(> .form-control:required) > label::after,
    .form-group:has(> input:required) > label::after,
    .form-group:has(> select:required) > label::after,
    .form-group:has(> textarea:required) > label::after,
    .ach-fg:has(> input:required) > label::after,
    .ach-fg:has(> select:required) > label::after,
    .ach-fg:has(> textarea:required) > label::after {
      content: " *";
      color: var(--danger-d);
    }
    .form-control {
      width: 100%; padding: 11px 14px;
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm); font-family: 'Manrope', sans-serif;
      font-size: 13.5px; color: var(--text);
      background: white; outline: none;
      transition: border-color .15s, box-shadow .15s;
    }
    .form-control:focus {
      border-color: var(--primary-d);
      box-shadow: 0 0 0 3px rgba(124,146,255,.15);
    }
    select.form-control { cursor: pointer; }
    textarea.form-control { resize: vertical; min-height: 80px; }

    /* Cible tactile : .btn a deja un plancher 44px (cf. P1 « adapt »), mais
       .form-control (env. 37px avec son padding actuel) ne l'a pas. Limité
       aux largeurs tactiles pour ne pas alourdir les formulaires/tableaux
       denses en desktop — le critère WCAG cible le tactile, pas la souris. */
    @media (max-width: 768px) {
      .form-control:not(textarea) { min-height: 44px; box-sizing: border-box; }
      /* .fsel : redéfini localement (padding différent) dans une dizaine de
         pages plutôt que centralisé — un seul plancher ici, quelle que soit
         la page, au lieu de reprendre chaque définition locale. */
      .fsel { min-height: 44px !important; box-sizing: border-box !important; }
    }

    /* Anneau de focus clavier global : beaucoup de pages posent
       outline:none localement (boutons, liens, .form-control, lignes de
       tableau cliquables) sans le remplacer, ce qui rend la navigation au
       clavier invisible. Un seul bloc ici plutôt qu'un correctif par page —
       :focus-visible ne se déclenche qu'au clavier, jamais au clic souris. */
    :focus-visible {
      outline: 2px solid var(--primary-d) !important;
      outline-offset: 2px !important;
    }

    /* ===== PAGINATION ===== */
    .pagination { display: flex; align-items: center; gap: 4px; padding: 16px; flex-wrap: wrap; }
    .page-btn {
      display: inline-flex; align-items: center; justify-content: center;
      padding: 8px 13px; min-height: 44px; min-width: 44px; box-sizing: border-box;
      border-radius: 10px;
      border: 1.5px solid var(--border);
      font-size: 13px; color: var(--text);
      text-decoration: none; transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;
      font-weight: 500;
    }
    .page-btn:hover { background: var(--primary-l); border-color: var(--primary-d); color: var(--primary-d); }
    .page-btn.active { background: var(--primary-d); color: white; border-color: var(--primary-d); }
    .page-info { margin-left: auto; font-size: 12px; color: var(--muted); }

    /* ===== ALERTS ===== */
    .alert { padding: 13px 18px; border-radius: var(--radius-sm); font-size: 13.5px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
    .alert-danger  { background: #FEE2E2; color: #991B1B; border-left: 4px solid var(--danger); }
    .alert-success { background: #D1FAE5; color: #065F46; border-left: 4px solid var(--success); }
    .alert-warning { background: #FEF3C7; color: #92400E; border-left: 4px solid var(--warning); }
    .alert-info    { background: var(--primary-l); color: var(--primary-d); border-left: 4px solid var(--primary); }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 900px) {
      .sidebar { width: 68px; }
      .sidebar .brand-text, .sidebar .nav-section,
      .sidebar .nav-item span:not(.nav-icon), .sidebar .sidebar-footer .user-info,
      .sidebar .nav-badge { display: none; }
      .main-wrap { margin-left: 68px; }
      .form-row.cols-2, .form-row.cols-3 { grid-template-columns: minmax(0,1fr); }
      /* Rail réduit à 68px : le pied de sidebar (avatar + logout) ne
         tenait déjà plus dans cette largeur avant même ce correctif — il
         débordait silencieusement, masqué par overflow-x:hidden sur
         .sidebar (donc invisible plutôt que planté). Le bouton logout est
         redondant avec celui du menu utilisateur de la barre du haut : on
         le masque ici plutôt que de le contraindre dans un espace qui n'a
         jamais été prévu pour lui, et on retire le padding du user-card
         pour que l'avatar (36px) tienne dans les 36px utiles du rail. */
      .sidebar .sidebar-footer .logout-btn { display: none; }
      .sidebar .sidebar-footer .user-card { padding: 0; justify-content: center; background: none; }
      .sidebar .sidebar-footer .user-card-link { flex: 0 0 auto; padding: 0; margin: 0; }
      /* Le rail est deja force a 68px ici : le bouton de reduction
         manuelle n'a rien a faire sur un ecran deja etroit. */
      .sidebar-toggle { display: none; }
      /* .nav-item garde son padding:10px 16px (pense pour le libelle en
         texte) : une fois celui-ci masque ci-dessus, il ne reste que
         16px de large pour une icone de 34px — elle deborde du bouton
         au lieu de s'y centrer. Meme correctif que la reduction
         manuelle ci-dessous. */
      .sidebar .nav-item { justify-content: center; padding-left: 0; padding-right: 0; }
      .sidebar .nav-group-label { justify-content: center; padding-left: 0; padding-right: 0; }
      .sidebar .nav-group-label span { display: none; }
    }

    /* ===== SIDEBAR — réduction manuelle =====
       Même rail réduit qu'en dessous de 900px ci-dessus, mais choisi par
       l'utilisateur (bouton .sidebar-toggle) et mémorisé dans
       localStorage, indépendamment de la largeur de fenêtre. Piloté par
       --sidebar-w plutôt que par une largeur dupliquée : .sidebar et
       .main-wrap la consomment déjà toutes les deux. */
    html.sidebar-collapsed { --sidebar-w: 68px; }
    html.sidebar-collapsed .sidebar .brand-text,
    html.sidebar-collapsed .sidebar .nav-section,
    html.sidebar-collapsed .sidebar .nav-item span:not(.nav-icon),
    html.sidebar-collapsed .sidebar .sidebar-footer .user-info,
    html.sidebar-collapsed .sidebar .nav-badge { display: none; }
    html.sidebar-collapsed .sidebar .sidebar-footer .logout-btn { display: none; }
    html.sidebar-collapsed .sidebar .sidebar-footer .user-card { padding: 0; justify-content: center; background: none; }
    html.sidebar-collapsed .sidebar .sidebar-footer .user-card-link { flex: 0 0 auto; padding: 0; margin: 0; }
    /* Meme correctif que le rail force en dessous de 900px ci-dessus :
       sans lui, l'icone (34px) deborde des 16px de contenu que laisse
       le padding:10px 16px pense pour le libelle en texte, et se
       retrouve decentree au lieu d'occuper le bouton. */
    html.sidebar-collapsed .sidebar .nav-item { justify-content: center; padding-left: 0; padding-right: 0; }
    html.sidebar-collapsed .sidebar .nav-group-label { justify-content: center; padding-left: 0; padding-right: 0; }
    html.sidebar-collapsed .sidebar .nav-group-label span { display: none; }

    /* Poignée à cheval sur la bordure du rail. En dehors de .sidebar
       (qui a overflow-x:hidden) et positionnée en fixed sur --sidebar-w
       pour glisser avec lui pendant la transition de largeur.
       --text/--card plutôt que --navy/--white : ce bouton est un enfant
       direct de <body>, hors de .main-wrap, où --navy garde toujours sa
       valeur claire de base même en thème sombre (cf. plus bas, pensé
       pour le fond de la sidebar) — --text/--card sont eux redéfinis au
       niveau racine et restent lisibles dans les deux thèmes. */
    .sidebar-toggle {
      position: fixed; top: 26px; left: calc(var(--sidebar-w) - 13px); z-index: 101;
      width: 26px; height: 26px; padding: 0; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      background: var(--card, #fff); color: var(--text);
      border: 1.5px solid var(--border);
      box-shadow: 0 2px 8px rgba(30,43,74,.18);
      cursor: pointer;
      transition: left .25s ease, background-color .15s, border-color .15s, color .15s;
    }
    .sidebar-toggle:hover { background: var(--primary-l); border-color: var(--primary-d); color: var(--primary-d); }
    .sidebar-toggle i { font-size: 12px; transition: transform .25s ease; }
    html.sidebar-collapsed .sidebar-toggle i { transform: rotate(180deg); }
  </style>
  <script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js"></script>

  <style>
  /* ===== SIDEBAR — Groupe actif badge ===== */
  .nav-group-label {
    display: flex; align-items: center; gap: 8px;
    padding: 14px 20px 6px;
    font-size: 12px; font-weight: 700; letter-spacing: 1.4px;
    color: rgba(255,255,255,.35);
    text-transform: uppercase;
    border-top: 1px solid rgba(255,255,255,.07);
    margin-top: 4px;
  }
  .nav-group-label i { font-size: 13px; opacity: .6; }

  /* Accueil button — toujours visible */
  .nav-item-home {
    margin: 8px 10px 2px !important;
    background: rgba(255,255,255,.05) !important;
    border: 1px solid rgba(255,255,255,.1) !important;
  }
  .nav-item-home:hover {
    background: rgba(255,255,255,.12) !important;
    border-color: rgba(255,255,255,.2) !important;
  }
  .nav-item-home.active {
    background: rgba(0,174,239,.2) !important;
    border-color: rgba(0,174,239,.4) !important;
    color: #00AEEF !important;
  }
  .nav-item-home.active .nav-icon { background: rgba(0,174,239,.3) !important; box-shadow: 0 4px 12px rgba(0,174,239,.4) !important; }
  .nav-item-home.active .nav-icon i { color: #00AEEF !important; }

  /* ===== DARK MODE ===== */

  /* 1 — Surcharge des variables CSS : corrige tout le texte utilisant var(--text), var(--muted), etc. */
  [data-theme="dark"] {
    --text:     #E2E8F0;
    --muted:    #94A3B8;
    --border:   #2D4060;
    --tertiary: #162032;
    --white:    #1E293B;
    --lighter:  #162032;
    /* --card n'est jamais défini ailleurs dans le dépôt : les ~20 usages en
       var(--card,#fff) (module demandes internes, admin/permissions.php)
       retombent tous sur le repli #fff. Le définir ici suffit à les
       basculer en sombre sans toucher chaque fichier individuellement. */
    --card:     #1E293B;
  }

  /* 2 — Surfaces principales */
  [data-theme="dark"] body                 { background: #0F172A; color: #E2E8F0; }
  [data-theme="dark"] .main-wrap           { background: #0F172A; }
  [data-theme="dark"] .card                { background: #1E293B !important; border-color: #2D4060; }
  [data-theme="dark"] .card-header         { background: #1E293B !important; border-color: #2D4060; }
  [data-theme="dark"] .card-header h3      { color: #E2E8F0; }
  [data-theme="dark"] .card-body           { background: #1E293B; }
  [data-theme="dark"] .topbar              { background: #1A2848; border-color: #2D4060; box-shadow: 0 1px 8px rgba(0,0,0,.3); }
  [data-theme="dark"] .topbar-title        { color: #E2E8F0; }
  [data-theme="dark"] .topbar-title small  { color: #7A99BE; }

  /* 3 — Tables */
  [data-theme="dark"] th                   { background: #162032 !important; color: #94A3B8 !important; border-color: #2D4060 !important; }
  [data-theme="dark"] td                   { color: #CBD5E1; border-bottom-color: #2D4060; }
  [data-theme="dark"] tr:hover td          { background: #253349; }
  [data-theme="dark"] tr:last-child td     { border-bottom: none; }

  /* 4 — Formulaires */
  [data-theme="dark"] .form-control                { background: #0F172A; color: #E2E8F0; border-color: #334155; }
  [data-theme="dark"] .form-control:focus          { background: #162032; }
  [data-theme="dark"] .form-control::placeholder   { color: #4A6580; }
  [data-theme="dark"] .form-group label            { color: #CBD5E1; }
  [data-theme="dark"] select.form-control option   { background: #1E293B; }
  [data-theme="dark"] textarea.form-control        { background: #0F172A; color: #E2E8F0; }
  [data-theme="dark"] input[type="date"].form-control,
  [data-theme="dark"] input[type="time"].form-control { color-scheme: dark; }

  /* 5 — Boutons */
  [data-theme="dark"] .btn-secondary        { background: #1E293B; color: #CBD5E1; border-color: #334155; }
  [data-theme="dark"] .btn-secondary:hover  { background: #253349; border-color: var(--primary); color: #E2E8F0; }

  /* 6 — Alertes */
  [data-theme="dark"] .alert-danger  { background: #4A1D1D; color: #FCA5A5; border-color: #F87171; }
  [data-theme="dark"] .alert-success { background: #064E3B; color: #6EE7B7; border-color: #34D399; }
  [data-theme="dark"] .alert-warning { background: #451A03; color: #FCD34D; border-color: #FBBF24; }
  [data-theme="dark"] .alert-info    { background: #1C3B6E; color: #93C5FD; border-color: var(--primary); }

  /* 7 — Badges */
  [data-theme="dark"] .badge-dark    { background: #334155; color: #94A3B8; }
  [data-theme="dark"] .badge-success { background: #064E3B; color: #6EE7B7; }
  [data-theme="dark"] .badge-warning { background: #451A03; color: #FCD34D; }
  [data-theme="dark"] .badge-danger  { background: #4A1D1D; color: #FCA5A5; }
  [data-theme="dark"] .badge-info,
  [data-theme="dark"] .badge-primary { background: #1C3B6E; color: #93C5FD; }

  /* 8 — Pagination */
  [data-theme="dark"] .page-btn        { background: #1E293B; color: #CBD5E1; border-color: #334155; }
  [data-theme="dark"] .page-btn:hover  { background: #253349; border-color: var(--primary); color: #93C5FD; }
  [data-theme="dark"] .page-btn.active { background: var(--primary-d); color: white; border-color: var(--primary-d); }
  [data-theme="dark"] .page-info       { color: #7A99BE; }

  /* 9 — Notifications dropdown */
  [data-theme="dark"] .notif-dropdown      { background: #1E293B; border-color: #2D4060; }
  [data-theme="dark"] .notif-header        { border-color: #2D4060; }
  [data-theme="dark"] .notif-header h4     { color: #E2E8F0; }
  [data-theme="dark"] .notif-item          { border-color: #2D4060; }
  [data-theme="dark"] .notif-item:hover    { background: #253349; }
  [data-theme="dark"] .notif-item .n-titre { color: #CBD5E1; }
  [data-theme="dark"] .notif-empty         { color: #7A99BE; }

  /* 10 — User menu topbar */
  [data-theme="dark"] #user-chip           { background: #162032 !important; border-color: #2D4060 !important; }
  [data-theme="dark"] .uc-name             { color: #E2E8F0 !important; }
  [data-theme="dark"] .uc-role             { color: #7A99BE !important; }
  [data-theme="dark"] #user-menu-dd        { background: #1E293B; border-color: #2D4060; }
  [data-theme="dark"] .um-item             { color: #CBD5E1 !important; }
  [data-theme="dark"] .um-item:hover       { background: #253349 !important; }
  [data-theme="dark"] .um-danger           { color: #FCA5A5 !important; }

  /* 11 — Modaux (pattern commun : position:fixed + inner div background white) */
  [data-theme="dark"] .main-wrap [style*="background:white"],
  [data-theme="dark"] .main-wrap [style*="background: white"] {
    background: #1E293B !important;
    color: #E2E8F0;
  }
  /* Séparateurs dans les modaux */
  [data-theme="dark"] .main-wrap [style*="background:white"] [style*="border-bottom:1px solid"],
  [data-theme="dark"] .main-wrap [style*="background:white"] [style*="border-top:1px solid"] {
    border-color: #2D4060 !important;
  }

  /* 12 — Toast */
  [data-theme="dark"] .toast-success { background: #064E3B; color: #6EE7B7; border-left-color: #34D399; }
  [data-theme="dark"] .toast-danger  { background: #4A1D1D; color: #FCA5A5; border-left-color: #F87171; }
  [data-theme="dark"] .toast-warning { background: #451A03; color: #FCD34D; border-left-color: #FBBF24; }
  [data-theme="dark"] .toast-info    { background: #1C3B6E; color: #93C5FD; border-left-color: var(--primary); }

  /* 13 — Éléments inline courants dans les pages */
  [data-theme="dark"] .table-wrap          { background: #1E293B; }
  [data-theme="dark"] .vue-table td        { background: #1E293B; }
  [data-theme="dark"] .vue-table tbody tr:nth-child(even) td { background: #1A2848; }
  [data-theme="dark"] .vue-table tr.total-row td { background: #0F172A !important; color: #E2E8F0 !important; }
  [data-theme="dark"] h2, [data-theme="dark"] h3,
  [data-theme="dark"] h4, [data-theme="dark"] h5 { color: #E2E8F0; }

  /* ═══ FIX PRINCIPAL : --navy dans le contenu principal → clair ═══
     La sidebar est <aside> hors de .main-wrap → background:var(--navy) n'est pas affecté.
     Tous les inline style="color:var(--navy)" dans les pages sont corrigés d'un coup. */
  [data-theme="dark"] .main-wrap { --navy: #E2E8F0; --blue-mid: #93C5FD; --blue-light: #93C5FD; }

  /* ═══ CARTES KPI / STAT (communes à toutes les pages) ═══ */
  [data-theme="dark"] .ik,
  [data-theme="dark"] .ek,
  [data-theme="dark"] .stat-card     { background: #1E293B !important; }
  [data-theme="dark"] .ik-val,
  [data-theme="dark"] .ek-val,
  [data-theme="dark"] .stat-val      { color: #E2E8F0 !important; }
  [data-theme="dark"] .ek-lbl,
  [data-theme="dark"] .ik-lbl,
  [data-theme="dark"] .stat-lbl      { color: #94A3B8 !important; }

  /* ═══ FILTRES SELECT inline background:white ═══ */
  [data-theme="dark"] .fsel,
  [data-theme="dark"] select[style*="background:white"],
  [data-theme="dark"] select[style*="background: white"] {
    background: #0F172A !important;
    color: #E2E8F0 !important;
    border-color: #334155 !important;
  }

  /* ═══ COULEURS HARDCODÉES dans les cellules PHP-générées ═══ */
  [data-theme="dark"] .main-wrap [style*="color:#06033A"],
  [data-theme="dark"] .main-wrap [style*="color: #06033A"]  { color: #E2E8F0 !important; }
  [data-theme="dark"] .main-wrap td[style*="color:#06033A"] { color: #E2E8F0 !important; }
  [data-theme="dark"] .main-wrap td[style*="color:#1E2B4A"] { color: #E2E8F0 !important; }
  [data-theme="dark"] .main-wrap td[style*="color:#1B75BC"] { color: #93C5FD !important; }
  /* Liens/spans colorés hardcodés */
  [data-theme="dark"] .main-wrap [style*="color:#1B75BC"]   { color: #93C5FD !important; }

  /* ═══ LIGNES TR colorées inline ═══ */
  [data-theme="dark"] tr[style*="background:#fff5f5"] td    { background: #2D1515 !important; }

  /* ═══ IMPORT EMUCI — classes spécifiques ═══ */
  [data-theme="dark"] .irb-stat-val                         { color: #E2E8F0 !important; }
  [data-theme="dark"] .irb-stat-lbl                         { color: #94A3B8 !important; }
  [data-theme="dark"] .import-result-banner.success         { background: #064E3B !important; border-color: #34D399 !important; }
  [data-theme="dark"] .import-result-banner.danger          { background: #4A1D1D !important; border-color: #F87171 !important; }
  [data-theme="dark"] .ld-title                             { color: #E2E8F0 !important; }
  [data-theme="dark"] .ld-sub                               { color: #94A3B8 !important; }

  /* ═══ TAB BUTTONS ═══ */
  [data-theme="dark"] .tab-btn       { color: #94A3B8 !important; background: transparent; border-color: #334155 !important; }
  [data-theme="dark"] .tab-btn:hover { color: #E2E8F0 !important; }
  [data-theme="dark"] .tab-btn.active { color: var(--primary-d) !important; border-color: var(--primary) !important; }

  /* ═══ COMMANDES BOBINES — statuts pills ═══ */
  [data-theme="dark"] .s-attente        { background: #451A03 !important; }
  [data-theme="dark"] .s-valide         { background: #1C3B6E !important; }
  [data-theme="dark"] .s-expedie        { background: #3D1A0A !important; }
  [data-theme="dark"] .s-recu           { background: #064E3B !important; }
  [data-theme="dark"] .st-en_attente    { background: #451A03 !important; }
  [data-theme="dark"] .st-valide        { background: #1C3B6E !important; }
  [data-theme="dark"] .st-en_preparation,
  [data-theme="dark"] .st-expedie       { background: #3D1A0A !important; }
  [data-theme="dark"] .st-recu          { background: #064E3B !important; }
  [data-theme="dark"] .st-rejete        { background: #4A1D1D !important; }
  [data-theme="dark"] .st-annule        { background: #1E293B !important; color: #94A3B8 !important; }

  /* ═══ VUE STOCK PAR SITE ═══ */
  [data-theme="dark"] .cell-nb          { color: #E2E8F0 !important; }
  [data-theme="dark"] .cell-films       { color: #7A99BE !important; }
  [data-theme="dark"] .cell-empty       { color: #334155 !important; }
  [data-theme="dark"] .tag-cours        { background: #1C3B6E !important; color: #93C5FD !important; }

  /* ═══ ÉQUIPEMENTS — badges état ═══ */
  [data-theme="dark"] .etat-hs          { background: #334155 !important; color: #E2E8F0 !important; }
  [data-theme="dark"] .main-wrap [style*="background:#e8f4f9"] { background: #1C3B6E !important; color: #93C5FD !important; }
  [data-theme="dark"] .main-wrap [style*="background:#f0f0f0"] { background: #253349 !important; color: #94A3B8 !important; }

  /* ═══ BOBINES — statuts validation ═══ */
  [data-theme="dark"] .s-in_use         { background: #064E3B !important; color: #6EE7B7 !important; }
  [data-theme="dark"] .s-reserved       { background: #1C3B6E !important; color: #93C5FD !important; }
  [data-theme="dark"] .s-declared_broken { background: #4A1D1D !important; color: #FCA5A5 !important; }
  [data-theme="dark"] .s-lost           { background: #253349 !important; color: #94A3B8 !important; }

  /* ═══ COUVERTURE STRUCTURELLE ═══
     Chaque page définit ses propres classes de carte/panneau avec un fond
     blanc en dur (background:white/#fff) plutôt que de réutiliser .card.
     Sans ceci, le texte redevient clair (cf. --navy redéfini plus haut)
     sur un fond resté blanc : illisible. Recensé par script sur tout le
     dépôt (79 sélecteurs), regroupé ici plutôt que repris page par page —
     un fond de carte uniforme partout plutôt qu'une teinte par page. */
  [data-theme="dark"] .modal, [data-theme="dark"] .mhdr, [data-theme="dark"] .mfoot,
  [data-theme="dark"] .kpi, [data-theme="dark"] .kpi-card, [data-theme="dark"] .stat-tile,
  [data-theme="dark"] .ik, [data-theme="dark"] .pmma-card, [data-theme="dark"] .deleg-card,
  [data-theme="dark"] .dept-panel, [data-theme="dark"] .modal-box, [data-theme="dark"] .nom-card,
  [data-theme="dark"] .affecte-item, [data-theme="dark"] .autocomplete-results, [data-theme="dark"] .sit-card,
  [data-theme="dark"] .ag-kpi, [data-theme="dark"] .ag-modal, [data-theme="dark"] .art-card,
  [data-theme="dark"] .conso-card, [data-theme="dark"] .dash-card, [data-theme="dark"] .di-modal .box,
  [data-theme="dark"] .di-back, [data-theme="dark"] .di-cstep, [data-theme="dark"] .di-plat,
  [data-theme="dark"] .ek, [data-theme="dark"] .import-card, [data-theme="dark"] .stat-card,
  [data-theme="dark"] .ld-box, [data-theme="dark"] .ses-k, [data-theme="dark"] .site-chip,
  [data-theme="dark"] .bk, [data-theme="dark"] .veh-card, [data-theme="dark"] .point-preview,
  [data-theme="dark"] .emuci-card, [data-theme="dark"] .profil-card, [data-theme="dark"] .rkpi,
  [data-theme="dark"] .r-card, [data-theme="dark"] .cout-kpi, [data-theme="dark"] .rg-filters,
  [data-theme="dark"] .rg-card, [data-theme="dark"] .rg-card-full, [data-theme="dark"] .rg-info,
  [data-theme="dark"] .rk, [data-theme="dark"] .kk, [data-theme="dark"] .vsm-kpi,
  [data-theme="dark"] .vsm-section, [data-theme="dark"] .card, [data-theme="dark"] .month-inp,
  [data-theme="dark"] .ch-box, [data-theme="dark"] .biz-m, [data-theme="dark"] .eq-sel,
  [data-theme="dark"] .di-btn-ghost, [data-theme="dark"] .btn-vsm-detail, [data-theme="dark"] .vsm-dec-btn,
  [data-theme="dark"] .detail-btn, [data-theme="dark"] .btn-csv, [data-theme="dark"] .btn-xlsx,
  [data-theme="dark"] .btn-pptx, [data-theme="dark"] .btn-pdf,
  [data-theme="dark"] .filter-bar, [data-theme="dark"] .filtre-bar, [data-theme="dark"] .vsm-filters,
  [data-theme="dark"] .add-form, [data-theme="dark"] .ag-drop, [data-theme="dark"] .ag-filters,
  [data-theme="dark"] .biz-cov-bar, [data-theme="dark"] .biz-mix, [data-theme="dark"] .biz-risk-r,
  [data-theme="dark"] .bstat-retiree, [data-theme="dark"] .btn-ghost, [data-theme="dark"] .btn-n1-off,
  [data-theme="dark"] .chip.gray, [data-theme="dark"] .kpi-m, [data-theme="dark"] .pfw-q,
  [data-theme="dark"] .pj-pill.veh, [data-theme="dark"] .point-badge.suivi, [data-theme="dark"] .prio-normale,
  [data-theme="dark"] .site-picker, [data-theme="dark"] .site-statut.non_commence,
  [data-theme="dark"] .statut-annulee, [data-theme="dark"] .statut-suivi {
    background: #1E293B !important;
    border-color: #334155 !important;
  }
  [data-theme="dark"] .btn-sm:hover, [data-theme="dark"] .dept-card:hover {
    background: #253349 !important;
  }
  /* .perm-tab.active (et motifs similaires) : fond var(--navy) + texte blanc
     fixe. --navy redevient clair plus haut (pensé pour le texte), donc ce
     fond-là devient clair aussi tout en gardant un texte blanc — illisible.
     On fige la combinaison plutôt que de suivre la variable. */
  [data-theme="dark"] .perm-tab.active {
    background: var(--primary-d) !important;
    color: #fff !important;
  }
  [data-theme="dark"] .perm-tab:hover { background: #253349 !important; }

  /* Même bug que .perm-tab.active ci-dessus, sur les autres éléments qui
     utilisent var(--navy) comme fond plein (badge, en-tête, avatar rond)
     avec du texte blanc en dur — recensés par script sur tout le dépôt. */
  [data-theme="dark"] .ag-btn-pri, [data-theme="dark"] .ag-table thead th,
  [data-theme="dark"] .bilan-site-hdr, [data-theme="dark"] .biz-dot-a,
  [data-theme="dark"] .btn-add, [data-theme="dark"] .btn-primary,
  [data-theme="dark"] .coord-bob-table th, [data-theme="dark"] .deleg-head,
  [data-theme="dark"] .dept-card.active .dept-ico, [data-theme="dark"] .ecarts-table thead th,
  [data-theme="dark"] .filtre-bar button, [data-theme="dark"] .form-section-num,
  [data-theme="dark"] .matrice-table th, [data-theme="dark"] .pdg-bar::after,
  [data-theme="dark"] .pmma-head, [data-theme="dark"] .resp-avatar,
  [data-theme="dark"] .seg-op, [data-theme="dark"] .sit-head, [data-theme="dark"] .sq-op,
  [data-theme="dark"] .stat-tile-total, [data-theme="dark"] .table-wrap thead th,
  [data-theme="dark"] .user-avatar-sm {
    background: #1E2B4A !important;
  }

  /* Champs de formulaire locaux (hors .form-control) : même traitement que
     les champs génériques, un ton plus sombre que les cartes ci-dessus. */
  [data-theme="dark"] .filter-bar input, [data-theme="dark"] .filter-bar select,
  [data-theme="dark"] .filtre-bar input, [data-theme="dark"] .filtre-bar select,
  [data-theme="dark"] .rg-field input, [data-theme="dark"] .rg-field select,
  [data-theme="dark"] .vsm-filters input, [data-theme="dark"] .vsm-filters select,
  [data-theme="dark"] .ag-filters input, [data-theme="dark"] .ag-filters select,
  [data-theme="dark"] .add-form select {
    background: #0F172A !important;
    color: #E2E8F0 !important;
    border-color: #334155 !important;
  }

  /* Lignes de tableau alternées/survolées declarées localement (hors table
     generique deja couverte plus haut). */
  [data-theme="dark"] .ag-table tbody tr:nth-child(even) td,
  [data-theme="dark"] .coord-bob-table tr:nth-child(even) td,
  [data-theme="dark"] .ecarts-table tbody tr:nth-child(even) td,
  [data-theme="dark"] .vue-table tbody tr:nth-child(even) td {
    background: #1A2848 !important;
  }
  [data-theme="dark"] .dv2-t tbody tr:hover td,
  [data-theme="dark"] .sites-table tr:hover td,
  [data-theme="dark"] .ag-table tbody tr:hover td,
  [data-theme="dark"] .ecarts-table tbody tr:hover td,
  [data-theme="dark"] .vsm-tbl tbody tr:hover td,
  [data-theme="dark"] .ptbl tr:hover td,
  [data-theme="dark"] .vue-table tbody tr:not(.total-row):hover td,
  [data-theme="dark"] .dash-lien:hover,
  [data-theme="dark"] .dept-card:hover,
  [data-theme="dark"] .di-back:hover,
  [data-theme="dark"] .di-btn-ghost:hover:not([disabled]),
  [data-theme="dark"] .notif-item:hover {
    background: #253349 !important;
  }

  /* Barres d'en-tête de section (mêmes classes de bandeau que .vsm-section-hdr,
     répétées sous d'autres noms page par page). */
  [data-theme="dark"] .vsm-section-hdr,
  [data-theme="dark"] .rg-card-hdr,
  [data-theme="dark"] .rg-info-hdr,
  [data-theme="dark"] .di-actions,
  [data-theme="dark"] .di-lbl,
  [data-theme="dark"] .vsm-dec-help,
  [data-theme="dark"] .kpi-cell,
  [data-theme="dark"] .btn-annul,
  [data-theme="dark"] .btn-sec {
    background: #162032 !important;
    border-color: #334155 !important;
  }
  /* Petits badges "fond clair + texte var(--navy)" restants (compteurs). */
  [data-theme="dark"] .dept-ico, [data-theme="dark"] .member-ava,
  [data-theme="dark"] .vsm-cnt, [data-theme="dark"] .vsm-tab-badge {
    background: #253349 !important;
    color: #E2E8F0 !important;
  }
  /* Pastilles neutres gris clair (.prio-normale et semblables) : fond assombri
     plus haut, mais texte reste #64748b/#475569 (gris clair d'origine, pense
     pour un fond pale) -> trop peu contraste sur le nouveau fond sombre. */
  [data-theme="dark"] .statut-annulee, [data-theme="dark"] .chip.gray,
  [data-theme="dark"] .site-statut.non_commence, [data-theme="dark"] .point-badge.suivi,
  [data-theme="dark"] .prio-normale, [data-theme="dark"] .statut-suivi,
  [data-theme="dark"] .s-lost, [data-theme="dark"] .st-annule {
    color: #94A3B8 !important;
  }
  /* Numeros/valeurs colores en ligne (style="color:#xxx"), calibres pour un
     fond blanc, desormais sur des cartes/tableaux assombris. */
  [data-theme="dark"] .main-wrap [style*="color:#1D4ED8"],
  [data-theme="dark"] .main-wrap [style*="color: #1D4ED8"],
  [data-theme="dark"] .main-wrap [style*="color:#1d4ed8"],
  [data-theme="dark"] .main-wrap [style*="color: #1d4ed8"] { color: #60A5FA !important; }
  [data-theme="dark"] .main-wrap [style*="color:#065f46"],
  [data-theme="dark"] .main-wrap [style*="color:#065F46"] { color: #34D399 !important; }
  [data-theme="dark"] .main-wrap [style*="color:#92400e"],
  [data-theme="dark"] .main-wrap [style*="color:#92400E"] { color: #FBBF24 !important; }
  [data-theme="dark"] .main-wrap [style*="color:#e65100"] { color: #FB923C !important; }
  [data-theme="dark"] .main-wrap [style*="color:#991b1b"],
  [data-theme="dark"] .main-wrap [style*="color:#991B1B"] { color: #FCA5A5 !important; }
  [data-theme="dark"] .main-wrap [style*="color:#c0392b"],
  [data-theme="dark"] .main-wrap [style*="color:#c62828"] { color: #FCA5A5 !important; }
  [data-theme="dark"] .step.s-valide .s-num  { color: #60A5FA !important; }
  [data-theme="dark"] .step.s-recu .s-num    { color: #34D399 !important; }
  [data-theme="dark"] .step.s-attente .s-num,
  [data-theme="dark"] .st-en_attente         { color: #FBBF24 !important; }
  [data-theme="dark"] .step.s-livraison .s-num { color: #FB923C !important; }
  [data-theme="dark"] .ac-type-badge, [data-theme="dark"] .count-cell,
  [data-theme="dark"] .cell-empty {
    background: #253349 !important;
    color: #E2E8F0 !important;
  }
  /* .sites-table th force color:#475569!important localement ; meme
     specificite que la regle generique th{} plus haut mais chargee apres
     (donc gagnante) -> on la re-surclasse avec un selecteur plus specifique. */
  [data-theme="dark"] .sites-table th {
    background: #162032 !important;
    color: #94A3B8 !important;
  }
  /* .kpi utilise --kpi-c (posee en inline style par tuile) pour son accent de
     texte/avant : les teintes choisies pour un fond blanc n'ont plus assez
     de contraste sur .kpi assombri. */
  [data-theme="dark"] .main-wrap [style*="--kpi-c:#1B75BC"] { --kpi-c: #60A5FA; }
  [data-theme="dark"] .main-wrap [style*="--kpi-c:#1565c0"] { --kpi-c: #60A5FA; }
  [data-theme="dark"] .main-wrap [style*="--kpi-c:#2e7d32"] { --kpi-c: #34D399; }
  [data-theme="dark"] .main-wrap [style*="--kpi-c:#7b1fa2"] { --kpi-c: #C084FC; }

  /* templates/dash_style.php (dashboard.php + pdg_overview.php) — meme
     famille de bugs que plus haut, propre a ce gabarit. */
  [data-theme="dark"] .biz-hero { background: #1E2B4A !important; }
  [data-theme="dark"] .biz-card, [data-theme="dark"] .kpi-m {
    background: #1E293B !important;
    border-color: #334155 !important;
  }
  [data-theme="dark"] .pdg-sub, [data-theme="dark"] .card-sub,
  [data-theme="dark"] .ch-sub, [data-theme="dark"] .kpi-m-lbl {
    color: #94A3B8 !important;
  }
  /* --biz-muted (local a .biz/.eq, ~18 classes) : meme redefinition que
     --muted plus haut, portee sur cette variable locale au gabarit. */
  [data-theme="dark"] .biz, [data-theme="dark"] .eq { --biz-muted: #94A3B8; }

  /* Garde-fou générique : tout fond blanc laissé en style="" inline sur une
     page non encore migrée individuellement (mêmes teintes que la carte). */
  [data-theme="dark"] .main-wrap [style*="background:#fff"]:not([style*="background:#fff5f5"]),
  [data-theme="dark"] .main-wrap [style*="background: #fff"]:not([style*="background: #fff5f5"]),
  [data-theme="dark"] .main-wrap [style*="background:#ffffff"],
  [data-theme="dark"] .main-wrap [style*="background: #ffffff"] {
    background: #1E293B !important;
    color: #E2E8F0 !important;
  }
  /* Fonds gris/bleu pale neutres (encarts d'info, badges de code) laisses en
     dur — pas les teintes semantiques rouge/vert/jaune des badges de statut,
     volontairement inchangees (texte sature deja lisible sur son propre
     fond quel que soit le theme). */
  [data-theme="dark"] .main-wrap [style*="background:#f8fafc"],
  [data-theme="dark"] .main-wrap [style*="background:#f1f5f9"],
  [data-theme="dark"] .main-wrap [style*="background:#f8f9fa"],
  [data-theme="dark"] .main-wrap [style*="background:#f8f9fb"],
  [data-theme="dark"] .main-wrap [style*="background:#e2e8f0"],
  [data-theme="dark"] .main-wrap [style*="background:#e8f4fd"],
  [data-theme="dark"] .main-wrap [style*="background:#e3f2fd"],
  [data-theme="dark"] .main-wrap [style*="background:#eff6ff"],
  [data-theme="dark"] .main-wrap [style*="background:#f0f4ff"],
  [data-theme="dark"] .main-wrap [style*="background:#e8f4f9"],
  [data-theme="dark"] .main-wrap [style*="background:#eaf2fb"],
  [data-theme="dark"] .main-wrap [style*="background:#f0f7ff"],
  [data-theme="dark"] .main-wrap [style*="background:#f0f9ff"],
  [data-theme="dark"] .main-wrap [style*="background:#eef2ff"],
  [data-theme="dark"] .main-wrap [style*="background:#e0f0ff"],
  [data-theme="dark"] .main-wrap [style*="background:#e8f0fe"],
  [data-theme="dark"] .main-wrap [style*="background:#eef0f8"] {
    background: #1E293B !important;
    color: #E2E8F0 !important;
  }
  [data-theme="dark"] .main-wrap [style*="background:var(--navy)"],
  [data-theme="dark"] .main-wrap [style*="background: var(--navy)"],
  [data-theme="dark"] .main-wrap [style*="background:var(--navy,"] {
    background: #1E2B4A !important;
    color: #fff !important;
  }

  /* Dégradés décoratifs qui utilisent var(--navy) comme couleur de FOND
     (bannières, en-têtes de rôle) plutôt que comme couleur de texte : la
     redéfinition de --navy plus haut (pensée pour le texte) les délave en
     clair. On les repointe sur la teinte navy d'origine, indépendamment du
     thème — ce sont des éléments de marque, pas du texte lisible. */
  [data-theme="dark"] .welcome-banner,
  [data-theme="dark"] .role-header,
  [data-theme="dark"] .capa-result,
  [data-theme="dark"] .nom-code,
  [data-theme="dark"] .profil-avatar-section {
    background: linear-gradient(135deg, #1E2B4A, #2D3E6E 60%, #3B5098) !important;
  }
  [data-theme="dark"] .main-wrap [style*="linear-gradient(90deg,var(--navy)"] {
    background: linear-gradient(90deg, #3B5098, transparent) !important;
  }
  [data-theme="dark"] .main-wrap [style*="linear-gradient(270deg,var(--navy)"] {
    background: linear-gradient(270deg, #3B5098, transparent) !important;
  }
  </style>

  <script>
  /* Appliquer le thème sauvegardé avant le rendu pour éviter le flash */
  (function () {
    var pref = localStorage.getItem('ds-theme-pref') || 'auto';
    var sys  = window.matchMedia('(prefers-color-scheme: dark)').matches;
    var t    = pref === 'dark' ? 'dark' : pref === 'light' ? 'light' : (sys ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', t);
  })();

  /* Appliquer la réduction du menu sauvegardée avant le rendu, même
     principe que ci-dessus : sinon le rail plein s'affiche une frame
     avant de se réduire. */
  (function () {
    if (localStorage.getItem('sidebar-collapsed') === '1') {
      document.documentElement.classList.add('sidebar-collapsed');
    }
  })();

  /* Bascule rapide depuis la topbar (clair <-> sombre). Réutilise la même
     clé localStorage que le sélecteur complet de Mon Profil — les deux
     restent synchronisés, aucune préférence "auto" ici (raccourci volontairement
     simple : cycler 3 états depuis une icône sans libellé serait peu clair). */
  function toggleThemeQuick() {
    var current = document.documentElement.getAttribute('data-theme') || 'light';
    var next = current === 'dark' ? 'light' : 'dark';
    localStorage.setItem('ds-theme-pref', next);
    document.documentElement.setAttribute('data-theme', next);
    syncThemeToggleIcon();
    document.querySelectorAll('.theme-opt').forEach(function (el) {
      el.classList.toggle('active', el.dataset.pref === next);
    });
  }
  function syncThemeToggleIcon() {
    var icon = document.getElementById('theme-toggle-icon');
    if (!icon) return;
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    icon.className = dark ? 'ph ph-sun' : 'ph ph-moon';
  }

  /* Réduction manuelle du menu latéral — mémorisée, indépendante de la
     largeur de fenêtre (cf. le rail forcé à 68px en dessous de 900px). */
  function toggleSidebar() {
    var collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
    localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0');
    syncSidebarToggleLabel();
  }
  function syncSidebarToggleLabel() {
    var btn = document.getElementById('sidebarToggleBtn');
    if (!btn) return;
    var collapsed = document.documentElement.classList.contains('sidebar-collapsed');
    var label = collapsed ? 'Agrandir le menu' : 'Réduire le menu';
    btn.setAttribute('title', label);
    btn.setAttribute('aria-label', label);
    btn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
  }
  </script>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<button type="button" class="sidebar-toggle" id="sidebarToggleBtn" onclick="toggleSidebar()"
        aria-pressed="false">
  <i class="ph ph-caret-left" aria-hidden="true"></i>
</button>
<script>syncSidebarToggleLabel();</script>
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-logo">
      <svg viewBox="0 0 26 26" width="26" height="26" fill="none">
        <rect x="1.5" y="1.5" width="10" height="10" rx="2.5" fill="white" opacity=".95"/>
        <rect x="14.5" y="1.5" width="10" height="10" rx="2.5" fill="white" opacity=".5"/>
        <rect x="1.5" y="14.5" width="10" height="10" rx="2.5" fill="white" opacity=".5"/>
        <rect x="14.5" y="14.5" width="10" height="10" rx="2.5" fill="white" opacity=".95"/>
      </svg>
    </div>
    <div class="brand-text">
      <p>ERP EMUCI</p>
      <span>by EMUCI</span>
    </div>
  </div>
  <nav class="sidebar-nav">
    <?php
    if (!function_exists('get_groupe_def')) {
        require_once __DIR__ . '/../includes/groupes_config.php';
    }
    $groupe_actif = $_SESSION['groupe_actif'] ?? null;
    ?>

    <!-- ── Bouton Accueil — toujours visible ── -->
    <a href="<?= APP_URL ?>/pages/accueil.php"
       class="nav-item nav-item-home <?= ($active_page??'')==='accueil'?'active':'' ?>">
      <span class="nav-icon"><i class="ph-duotone ph-house-simple"></i></span>
      <span>Accueil</span>
    </a>

    <?php if ($groupe_actif): ?>
      <?php
      $g_def   = get_groupe_def($groupe_actif);
      $g_items = get_groupe_nav_items($groupe_actif);
      if ($g_def && $g_items):
      ?>

      <!-- Label du groupe actif -->
      <div class="nav-group-label">
        <i class="ph-duotone <?= h($g_def['icon']) ?>"></i>
        <span><?= h($g_def['titre']) ?></span>
      </div>

      <?php foreach ($g_items as $item): ?>
      <a href="<?= APP_URL ?>/<?= h($item['url']) ?>"
         class="nav-item <?= in_array($active_page??'', $item['active_keys']) ? 'active' : '' ?>">
        <span class="nav-icon"><i class="ph-duotone <?= h($item['icon']) ?>"></i></span>
        <span><?= h($item['label']) ?></span>
      </a>
      <?php endforeach; ?>

      <?php endif; ?>
    <?php endif; ?>

  </nav>
  <div class="sidebar-footer">
    <div class="user-card">
      <a href="<?= APP_URL ?>/pages/mon_profil.php" class="user-card-link" title="Mon profil">
        <div class="user-avatar"><?= strtoupper(substr($user['prenom'],0,1) . substr($user['nom'],0,1)) ?></div>
        <div class="user-info">
          <div class="name"><?= h($user['prenom'] . ' ' . $user['nom']) ?></div>
          <div class="role"><?= h($user['role_nom']) ?></div>
        </div>
      </a>
      <a href="<?= APP_URL ?>/logout.php" class="logout-btn" title="Déconnexion">
        <i class="ph-duotone ph-sign-out"></i>
      </a>
    </div>
  </div>
</aside>

<div class="main-wrap">

  <!-- TOP BAR -->
  <header class="topbar">
    <h1 class="topbar-title">
      <?= h($page_title ?? 'Dashboard') ?>
      <?php if (!empty($page_subtitle)): ?>
        <small><?= h($page_subtitle) ?></small>
      <?php endif; ?>
    </h1>
    <div class="topbar-actions">

      <!-- Thème -->
      <button class="notif-btn" onclick="toggleThemeQuick()" title="Basculer le thème clair/sombre" aria-label="Basculer le thème clair/sombre">
        <i class="ph" id="theme-toggle-icon" style="font-size:19px;color:var(--muted)" aria-hidden="true"></i>
      </button>
      <script>syncThemeToggleIcon();</script>

      <!-- Notifications -->
      <button class="notif-btn" onclick="toggleNotifs()" title="Notifications">
        <i class="ph-duotone ph-bell" style="font-size:20px;color:var(--muted)"></i>
        <?php if ($unread > 0): ?>
          <span class="notif-count"><?= $unread > 9 ? '9+' : $unread ?></span>
        <?php endif; ?>
      </button>

      <!-- User menu -->
      <div style="position:relative" id="user-menu-wrap">
        <button type="button" onclick="toggleUserMenu(event)" id="user-chip"
                style="display:flex;align-items:center;gap:10px;padding:6px 12px 6px 6px;background:var(--tertiary);border-radius:40px;border:1.5px solid var(--border);cursor:pointer;transition: background-color .15s, border-color .15s, color .15s, box-shadow .15s, transform .15s, opacity .15s;font-family:inherit">
          <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;color:white;font-size:13px;font-weight:700;font-family:'Plus Jakarta Sans',sans-serif;flex-shrink:0">
            <?= strtoupper(substr($user['prenom']??'',0,1).substr($user['nom']??'',0,1)) ?>
          </div>
          <div style="text-align:left">
            <div class="uc-name" style="font-size:12.5px;font-weight:700;color:var(--navy);font-family:'Plus Jakarta Sans',sans-serif;line-height:1.2"><?= h(($user['prenom']??'').' '.($user['nom']??'')) ?></div>
            <div class="uc-role" style="font-size:10.5px;color:var(--muted);line-height:1.2"><?= h($user['role_nom']??'') ?></div>
          </div>
          <i class="ph-duotone ph-caret-down" style="font-size:12px;color:var(--muted);margin-left:2px;flex-shrink:0;transition:transform .2s" id="user-chip-caret"></i>
        </button>

        <div id="user-menu-dd"
             style="display:none;position:absolute;top:calc(100% + 8px);right:0;width:200px;background:white;border:1.5px solid var(--border);border-radius:14px;box-shadow:0 8px 28px rgba(30,43,74,.14);z-index:300;overflow:hidden">
          <a href="<?= APP_URL ?>/pages/mon_profil.php" class="um-item"
             style="display:flex;align-items:center;gap:10px;padding:12px 16px;text-decoration:none;font-size:13px;font-weight:500;transition:background .12s;color:var(--text)"
             onmouseover="this.style.background='var(--tertiary)'" onmouseout="this.style.background=''">
            <i class="ph-duotone ph-user-circle" style="font-size:18px;color:var(--primary-d)"></i>
            Mon profil
          </a>
          <div style="height:1px;background:var(--border)"></div>
          <a href="<?= APP_URL ?>/logout.php" class="um-item um-danger"
             style="display:flex;align-items:center;gap:10px;padding:12px 16px;text-decoration:none;font-size:13px;font-weight:500;transition:background .12s;color:#991B1B"
             onmouseover="this.style.background='#FEE2E2'" onmouseout="this.style.background=''">
            <i class="ph-duotone ph-sign-out" style="font-size:18px"></i>
            Déconnexion
          </a>
        </div>
      </div>

    </div>
  </header>

  <!-- NOTIFICATION DROPDOWN -->
  <div class="notif-dropdown" id="notif-dropdown">
    <div class="notif-header">
      <h4>Notifications <?php if ($unread): ?><span style="color:var(--danger-d)">(<?= $unread ?>)</span><?php endif; ?></h4>
      <a href="javascript:void(0)" onclick="markAllRead()">Tout marquer lu</a>
    </div>
    <div class="notif-list">
      <?php if (empty($notifs)): ?>
        <div class="notif-empty"><i class="ph ph-check-circle" aria-hidden="true"></i> Aucune notification</div>
      <?php else: ?>
        <?php foreach ($notifs as $n): ?>
          <div class="notif-item" onclick="readNotif(<?= $n['id'] ?>, '<?= h($n['lien']??'') ?>')">
            <div class="n-titre"><?= h($n['titre']??'') ?></div>
            <div class="n-date"><?= fmt_datetime($n['created_at']??'') ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- PAGE CONTENT starts here -->
  <main class="page-content">

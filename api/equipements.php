<?php
// ============================================================
//  api/equipements.php — retirée (revue des permissions, 17/11/2026)
// ============================================================
//  Cette API (liste, fiche, modification, transfert, réforme, suppression)
//  n'était appelée par aucun écran. Elle contrôlait le seul droit
//  « Équipements Informatique » quelle que soit la catégorie, sans
//  périmètre de site ni les garde-fous de pages/equipements.php (statut
//  conservé pendant un transfert, mouvement tracé, suppression refusée
//  pendant une affectation ou une intervention). Les équipements se gèrent
//  uniquement depuis pages/equipements.php et pages/mouvements_equipements.php.
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';

require_auth();
http_response_code(410);
json_response(false, 'Cette API a été retirée : utilisez la page Équipements.');

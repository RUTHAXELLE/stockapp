<?php
/* templates/dash_anim.php — moteur d'animation partagé des tableaux de bord.
 *
 * À inclure une seule fois, après le contenu de la page, sur toute vue dont
 * la racine porte la classe `.pdg`, `.dv2` ou `.kpi`. Le formulaire de
 * filtres porte l'id `pdg-filter-form` ; tout autre formulaire de filtre
 * doit porter `data-dash-filtre`, ou vivre dans un élément qui le porte.
 * À l'inverse, un champ du formulaire de filtres qui ne doit PAS
 * déclencher l'échange (ex. les checkboxes d'un sélecteur à choix
 * multiple avec confirmation) vit dans un élément portant
 * `data-dash-nofiltre` — voir pages/kpi_dashboard.php et
 * `window.dashFiltrer`, pour déclencher l'échange explicitement une fois
 * la confirmation donnée.
 *
 * Extrait de pages/pdg_overview.php pour que les tableaux de bord par profil
 * héritent du même mouvement sans en recopier une ligne.
 */
?>
<div class="pdg-bar" aria-hidden="true"></div>
<script>
/* ═══════════ Mise à jour animée d'un tableau de bord ═══════════
   Le filtre rechargeait la page : écran blanc, puis des chiffres déjà
   arrivés. On récupère désormais la page en arrière-plan, on échange son
   contenu sur place, et tout ce qui a changé se met en mouvement.

   Rien n'est balisé à la main : les valeurs et les barres sont trouvées
   en parcourant le contenu. Un bloc ajouté plus tard sera donc animé
   sans qu'il y ait quoi que ce soit à déclarer.

   Sans fetch, sans DOMParser, ou en cas d'erreur, la navigation
   classique reprend la main.                                          */
(function () {
  'use strict';

  // Racine échangée à chaque changement de filtre, et formulaires qui
  // déclenchent l'échange. Trois crochets, pour que ce module serve
  // n'importe quel tableau de bord construit sur dash_style.php.
  // `.pdg` couvre la vue PDG, `.dv2` le tableau de bord v2, `.kpi` le
  // dashboard KPI (pages/kpi_dashboard.php, qui garde sa propre feuille
  // de style). Une page ne porte jamais deux de ces racines à la fois :
  // le premier sélecteur qui répond gagne.
  var RACINE     = document.querySelector('.dv2') ? '.dv2'
                  : document.querySelector('.kpi') ? '.kpi' : '.pdg';
  var SEL_FILTRE = '#pdg-filter-form, [data-dash-filtre]';

  var SOBRE  = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var DUREE  = 1500;                        // décompte des chiffres
  var GLISSE = 1500;                        // remplissage des barres
  var adouci = function (t) { return 1 - Math.pow(1 - t, 3); };
  var COURBE = 'cubic-bezier(.22,1,.36,1)';

  // ── Lecture d'un nombre au format de la page ──────────────────────
  // « 96 », « 13 191 », « +2 », « 92 % », « 5,8 ». Refuse tout ce qui
  // contient une lettre, une date ou une fraction.
  function litNombre(t) {
    if (!t) return null;
    t = t.replace(/ | /g, ' ').trim();
    if (!t || t.length > 16) return null;
    var pct = /%$/.test(t);
    var c = t.replace(/%$/, '').trim().replace(/ /g, '').replace(',', '.');
    if (!/^[+-]?\d+(\.\d+)?$/.test(c)) return null;
    var d = c.split('.')[1];
    return { v: parseFloat(c), pct: pct, signe: c.charAt(0) === '+', dec: d ? d.length : 0 };
  }

  // Texte propre à l'élément, hors contenu de ses enfants : « 96,4 » dans
  // <div>96,4<span>%</span></div>.
  function texteDirect(el) {
    var t = '', n = el.firstChild;
    while (n) { if (n.nodeType === 3) t += n.textContent; n = n.nextSibling; }
    return t;
  }

  // Écrit sans détruire les enfants : l'unité reste en place.
  function ecrire(el, txt) {
    var n = el.firstChild, premier = null;
    while (n) {
      if (n.nodeType === 3) { if (premier) n.textContent = ''; else premier = n; }
      n = n.nextSibling;
    }
    if (premier) premier.textContent = txt;
    else el.insertBefore(document.createTextNode(txt), el.firstChild);
  }

  // Reproduit l'affichage du serveur, règle « < 1 » comprise.
  function fmt(n, d, signe, pct) {
    var txt;
    if (d === 0) {
      var r = Math.round(n);
      if (r === 0 && n > 0.0001) txt = '< 1';
      else if (r === 0 && n < -0.0001) txt = '> -1';
      else txt = String(r);
    } else txt = n.toFixed(d);
    if (txt.charAt(0) !== '<' && txt.charAt(0) !== '>') {
      var p = txt.split('.');
      p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
      txt = p.join(',');
      if (signe && n > 0 && txt.charAt(0) !== '+') txt = '+' + txt;
    }
    return txt + (pct ? ' %' : '');
  }

  // ── Identité d'un élément d'une version de page à l'autre ─────────
  // data-k quand il existe, sinon un chemin structurel. Une clé qui ne se
  // retrouve pas fait simplement partir la valeur de zéro.
  function cleDe(el, racine) {
    var k = el.getAttribute && el.getAttribute('data-k');
    if (k) return 'k:' + k;
    var p = [], n = el, garde = 0;
    while (n && n !== racine && garde++ < 7) {
      var i = 0, s = n;
      while ((s = s.previousElementSibling)) i++;
      var c = (typeof n.className === 'string' && n.className) ? n.className.split(' ')[0] : n.tagName;
      p.push(c + i);
      n = n.parentElement;
    }
    return p.join('/');
  }

  // ── Découverte des valeurs ────────────────────────────────────────
  function chaqueNombre(racine, cb) {
    var tous = racine.querySelectorAll('*');
    for (var i = 0; i < tous.length; i++) {
      var el = tous[i], t = el.tagName;
      if (t === 'SCRIPT' || t === 'STYLE' || t === 'OPTION' || t === 'SELECT') continue;
      var n = litNombre(texteDirect(el));
      if (n) cb(el, n);
    }
  }

  // ── Découverte des barres ─────────────────────────────────────────
  // Un élément dimensionné en pourcentage dans son attribut style, sans
  // contenu volumineux : c'est une barre, pas un conteneur de mise en page.
  function chaqueBarre(racine, cb) {
    var tous = racine.querySelectorAll('[style*="%"]');
    for (var i = 0; i < tous.length; i++) {
      var el = tous[i], axe = null, val = null;
      if (/%\s*$/.test(el.style.width)) { axe = 'width'; val = parseFloat(el.style.width); }
      else if (/%\s*$/.test(el.style.height)) { axe = 'height'; val = parseFloat(el.style.height); }
      if (axe === null || isNaN(val)) continue;
      if (el.textContent.trim().length > 14) continue;      // conteneur, pas barre
      cb(el, axe, val);
    }
  }

  function photo(racine) {
    var o = {};
    if (racine) chaqueNombre(racine, function (el, n) { o[cleDe(el, racine)] = n.v; });
    return o;
  }

  // ── Séries derrière les graphes dessinés ──────────────────────────
  // Les graphes dessines sont pilotes par pdgProg, un simple avancement de
  // 0 a 1 que leurs fonctions de trace appliquent a la geometrie. Interpoler
  // leurs donnees ne servait a rien : ces graphes sont relatifs a leur propre
  // echelle, mettre toute la serie a l'echelle laisse le trace identique.
  function progres(p) {
    window.pdgProg = p;
    redessiner();
  }

  // Les erreurs sont contenues pour ne pas casser la page, mais signalées :
  // un échec muet laisse un graphe sur ses anciennes données sans rien dire.
  function redessiner() {
    try { if (typeof render === 'function') render(); }
    catch (e) { if (window.console) console.warn('pdg : redessin des graphes', e); }
    try { if (typeof pfwUpdate === 'function' && window.pfwSites) pfwUpdate(); }
    catch (e) { if (window.console) console.warn('pdg : redessin performance par site', e); }
  }

  // ── Animation ─────────────────────────────────────────────────────
  var anim = null;

  function solder() {
    if (!anim) return;
    var a = anim; anim = null;
    if (a.id) cancelAnimationFrame(a.id);
    if (a.secours) clearTimeout(a.secours);
    a.poser();
  }

  // Préparation. Elle s'exécute pendant que le nouveau contenu est encore
  // détaché du document : l'appliquer après insertion faisait apparaître la
  // page, puis disparaître le temps d'une frame, avant de revenir.
  function preparer(avant, racine) {
    solder();

    // Chiffres : de leur valeur précédente vers la nouvelle, ou de zéro
    // s'ils n'existaient pas avant.
    var nombres = [];
    chaqueNombre(racine, function (el, n) {
      var k = cleDe(el, racine);
      var dep = (k in avant) ? avant[k] : 0;
      if (Math.abs(n.v - dep) < 0.0005) return;
      nombres.push({ el: el, dep: dep, arr: n.v, d: n.dec, s: n.signe, p: n.pct });
    });

    // Barres : toujours depuis zéro. Elles portent des proportions, qui
    // varient peu d'un site à l'autre ; partir de l'ancienne valeur les
    // laissait immobiles pendant que les chiffres changeaient.
    var barres = [];
    chaqueBarre(racine, function (el, axe, val) {
      barres.push({ el: el, axe: axe, arr: val });
    });

    // Les graphes dessinés participent toujours au mouvement : leur tracé
    // part de la ligne de base et se remplit, sans que leurs données bougent.
    // On interroge le contenu qui arrive, pas le document : à cet instant
    // l'ancien est encore en place et répondrait à sa place.
    var aGraphes = !!racine.querySelector('canvas');

    // Blocs : un simple glissement, sans variation d'opacité. Masquer le
    // contenu creusait un blanc entre l'ancien et le nouveau. Le test de
    // visibilité ne peut pas reposer sur la hauteur : à ce stade l'élément
    // n'est pas encore dans le document et la mesure vaudrait zéro.
    var blocs = [];
    for (var b = 0; b < racine.children.length && blocs.length < 8; b++) {
      var bl = racine.children[b];
      if (!bl.firstElementChild && !bl.textContent.trim()) continue;
      // La barre de filtres reste immobile : faire bouger le sélecteur qu'on
      // vient d'actionner donnerait l'impression d'avoir raté son clic.
      if (bl.querySelector(SEL_FILTRE)) continue;
      blocs.push(bl);
    }

    function poser() {
      for (var i = 0; i < nombres.length; i++) {
        ecrire(nombres[i].el, fmt(nombres[i].arr, nombres[i].d, nombres[i].s, nombres[i].p));
      }
      for (var j = 0; j < barres.length; j++) {
        barres[j].el.style.transition = '';
        barres[j].el.style[barres[j].axe] = barres[j].arr + '%';
      }
      for (var l = 0; l < blocs.length; l++) {
        blocs[l].style.transition = ''; blocs[l].style.transform = '';
      }
      progres(1);
    }

    // Motion réduite, onglet en arrière-plan où les frames ne viennent pas,
    // ou rien à animer : on pose l'état final et il n'y a rien à lancer.
    if (SOBRE || document.hidden ||
        (!nombres.length && !barres.length && !aGraphes && !blocs.length)) {
      poser();
      return null;
    }

    // État de départ, écrit tout de suite : sinon la valeur finale reste
    // affichée une frame avant de reculer, ce qui se voit.
    for (var i = 0; i < nombres.length; i++) {
      ecrire(nombres[i].el, fmt(nombres[i].dep, nombres[i].d, nombres[i].s, nombres[i].p));
    }
    for (var j = 0; j < barres.length; j++) {
      barres[j].el.style.transition = 'none';
      barres[j].el.style[barres[j].axe] = '0%';
    }
    for (var l = 0; l < blocs.length; l++) {
      blocs[l].style.transition = 'none';
      blocs[l].style.transform = 'translateY(9px)';
    }
    // Les canvas ne sont pas encore dans le document : on note seulement le
    // point de départ, le premier tracé aura lieu au lancement.
    window.pdgProg = 0;

    return { nombres: nombres, barres: barres, blocs: blocs,
             aGraphes: aGraphes, poser: poser };
  }

  // Lancement, une fois le contenu inséré : les canvas ont besoin d'être
  // dans le document pour se dimensionner.
  function lancer(plan) {
    if (!plan) { redessiner(); return; }
    var nombres = plan.nombres, barres = plan.barres, blocs = plan.blocs;
    var aGraphes = plan.aGraphes, poser = plan.poser;

    progres(0);                       // tracé initial, à la ligne de base

    anim = { id: 0, secours: 0, poser: poser };
    var mien = anim;

    // Filet indépendant des frames : si elles n'arrivent jamais, parce que
    // le navigateur a suspendu le rendu, l'état final est posé quand même.
    anim.secours = setTimeout(function () {
      if (anim === mien) { anim = null; poser(); }
    }, Math.max(DUREE, GLISSE) + 700);

    requestAnimationFrame(function () {
      if (anim !== mien) return;
      for (var j = 0; j < barres.length; j++) {
        barres[j].el.style.transition = barres[j].axe + ' ' + (GLISSE / 1000) + 's ' + COURBE;
      }
      for (var l = 0; l < blocs.length; l++) {
        blocs[l].style.transition = 'transform .5s ' + COURBE + ' ' + (l * 55) + 'ms';
      }
      requestAnimationFrame(function () {
        if (anim !== mien) return;
        for (var j = 0; j < barres.length; j++) {
          barres[j].el.style[barres[j].axe] = barres[j].arr + '%';
        }
        for (var l = 0; l < blocs.length; l++) { blocs[l].style.transform = ''; }
      });
    });

    var t0 = null, frame = 0;
    function pas(t) {
      if (anim !== mien) return;
      if (t0 === null) t0 = t;
      var k = Math.min(1, (t - t0) / DUREE), e = adouci(k);
      for (var i = 0; i < nombres.length; i++) {
        var x = nombres[i];
        ecrire(x.el, fmt(x.dep + (x.arr - x.dep) * e, x.d, x.s, x.p));
      }
      // Un redessin canvas coûte plus cher qu'une écriture de texte :
      // une frame sur deux suffit à donner le mouvement.
      if (aGraphes && (frame++ % 2) === 0) progres(e);
      if (k < 1) { mien.id = requestAnimationFrame(pas); }
      else { clearTimeout(mien.secours); anim = null; poser(); }
    }
    mien.id = requestAnimationFrame(pas);
  }

  // L'onglet part en arrière-plan pendant le mouvement : on solde, plutôt
  // que de laisser des valeurs figées à mi-course jusqu'au retour.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) solder();
  });

  // Le navigateur ne doit pas repositionner le défilement à sa guise : on
  // s'en charge nous-mêmes lors de l'échange de contenu.
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';

  // Ombre sous la barre de filtres dès qu'un contenu passe dessous. La
  // classe vit sur body, elle survit donc au remplacement du contenu.
  function majCollee() {
    var y = window.pageYOffset || document.documentElement.scrollTop || 0;
    document.body.classList.toggle('pdg-collee', y > 8);
  }
  window.addEventListener('scroll', majCollee, { passive: true });
  majCollee();

  // ── Changement de filtre sans rechargement visible ────────────────
  // Les clés portées par CE formulaire sont effacées avant d'écrire ses
  // valeurs actuelles : un champ répété (checkboxes de même nom, ex.
  // sites[] sur pages/kpi_dashboard.php) doit pouvoir apporter plusieurs
  // valeurs, pas seulement la dernière (.set() les aurait écrasées), et
  // une checkbox tout juste décochée doit faire disparaître sa clé plutôt
  // que de laisser traîner l'ancienne valeur de location.search — d'où la
  // liste des noms lue sur f.elements (tous les champs du formulaire),
  // pas sur FormData (qui omet les cases décochées).
  function urlDuFiltre(f) {
    var p = new URLSearchParams(location.search);
    var noms = {};
    Array.prototype.forEach.call(f.elements, function (el) { if (el.name) noms[el.name] = true; });
    Object.keys(noms).forEach(function (k) { p.delete(k); });
    new FormData(f).forEach(function (v, k) { p.append(k, v); });
    return location.pathname + '?' + p.toString();
  }

  function estFiltre(f) {
    if (!f) return false;
    if (f.matches && f.matches(SEL_FILTRE)) return true;
    // Les sélecteurs internes à un bloc (type d'équipement, trimestre) vivent
    // dans un formulaire propre au bloc : il porte le même attribut, ou bien
    // il est contenu dans un bloc qui se déclare filtrant.
    return !!(f.closest && f.closest('[data-dash-filtre]'));
  }

  // Capture sur le document : survit au remplacement du contenu, et passe
  // avant le onchange="this.form.submit()" qu'on neutralise ici. Cet appel
  // n'émet pas d'événement submit, d'où l'écoute de « change ».
  document.addEventListener('change', function (ev) {
    var f = ev.target && ev.target.form;
    if (!estFiltre(f)) return;
    // Champ sciemment exclu (checkboxes d'un choix multiple à confirmer,
    // par ex.) : son propre gestionnaire garde la main, rien à échanger
    // avant validation explicite via window.dashFiltrer.
    if (ev.target.closest && ev.target.closest('[data-dash-nofiltre]')) return;
    if (!window.fetch || !window.DOMParser || !history.pushState) return;
    ev.stopPropagation();
    charger(urlDuFiltre(f));
  }, true);

  var enVol = null;

  function charger(url) {
    if (enVol) { try { enVol.abort(); } catch (e) {} }
    var ctrl = window.AbortController ? new AbortController() : null;
    enVol = ctrl;

    var avant = photo(document.querySelector(RACINE));
    document.body.classList.add('pdg-busy');

    // Après une longue inactivité l'hébergement doit se réveiller et la
    // réponse peut tarder. Passé ce délai on rend la main au navigateur :
    // son indicateur de chargement vaut mieux qu'une page qui paraît figée.
    var minuterie = setTimeout(function () {
      if (enVol !== ctrl) return;
      enVol = null;
      if (ctrl) { try { ctrl.abort(); } catch (e) {} }
      location.href = url;
    }, 12000);

    function courante() { return enVol === ctrl; }
    function fini() {
      clearTimeout(minuterie);
      if (courante()) enVol = null;
      document.body.classList.remove('pdg-busy');
    }

    fetch(url, {
      credentials: 'same-origin',
      signal: ctrl ? ctrl.signal : undefined,
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        if (!courante()) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var neuf = doc.querySelector(RACINE);
        var ancien = document.querySelector(RACINE);
        // Pas de tableau de bord dans la réponse : session expirée, le
        // serveur renvoie la page de connexion. On y va vraiment, pour que
        // l'utilisateur le voie au lieu de rester sur des chiffres périmés.
        if (!neuf || !ancien) throw new Error('structure');

        // Données des graphes : ce sont des `var` de premier niveau, donc
        // des propriétés de window, réassignables. Un bloc illisible ne
        // doit pas faire échouer l'échange.
        var bloc = doc.getElementById('pdg-data');
        if (bloc) {
          try {
            var d = JSON.parse(bloc.textContent);
            Object.keys(d).forEach(function (k) {
              if (k !== 'pfwQuarterDef') window[k] = d[k];
            });
          } catch (e) {}
        }

        // L'état de départ est posé sur le nouveau contenu encore détaché :
        // inséré tel quel, il ne clignote pas.
        var plan = null;
        try { plan = preparer(avant, neuf); }
        catch (e) { if (window.console) console.warn('pdg : préparation', e); }

        // Remplacer le contenu fait momentanément varier la hauteur du
        // document, et le navigateur ramène alors le défilement vers le
        // haut. On le remet où il était : on consulte un bloc, on change
        // le filtre, on reste devant ce bloc.
        var y = window.pageYOffset || document.documentElement.scrollTop || 0;
        ancien.replaceWith(neuf);
        history.pushState({ pdg: 1 }, '', url);
        if (y && Math.abs((window.pageYOffset || 0) - y) > 1) window.scrollTo(0, y);
        fini();

        try { lancer(plan); }
        catch (e) {
          if (window.console) console.warn('pdg : animation', e);
          // La préparation a mis l'avancement à zéro : sans ce retour à 1,
          // les graphes resteraient vides. La page doit rester juste même
          // quand l'animation échoue.
          progres(1);
          if (plan && plan.poser) { try { plan.poser(); } catch (e2) {} }
        }
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') return;   // remplacée ou minuterie
        if (!courante()) return;
        fini();
        location.href = url;
      });
  }

  // Retour arrière : on recharge, plus simple et toujours juste.
  window.addEventListener('popstate', function (ev) {
    if (ev.state && ev.state.pdg) location.reload();
  });

  // Le navigateur peut restaurer la page telle quelle, état d'attente
  // compris. On le lève systématiquement à l'affichage.
  window.addEventListener('pageshow', function () {
    document.body.classList.remove('pdg-busy', 'pdg-move');
  });

  // ── Déclenchement explicite ────────────────────────────────────────
  // Pour un contrôle qui ne peut pas compter sur l'interception passive
  // du 'change' ci-dessus — ex. le bouton « Appliquer » d'un sélecteur à
  // choix multiple avec confirmation (pages/kpi_dashboard.php) : un clic
  // n'émet pas de 'change', et les cases du sélecteur vivent de toute
  // façon dans un élément [data-dash-nofiltre] pour ne rien déclencher
  // avant cette validation explicite.
  window.dashFiltrer = function (form) {
    if (window.fetch && window.DOMParser && history.pushState) charger(urlDuFiltre(form));
    else form.submit();
  };
})();
</script>

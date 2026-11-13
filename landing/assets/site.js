// ── Script partagé de la vitrine (les huit pages de `landing/`) ──────────────
// Chargé en fin de <body>, APRÈS `config.js` et AVANT le script de la page (`home.js`,
// `offres.js`, `faq.js`), avec le même `?v=` que `config.js` et les feuilles.
//
// Règles de ce fichier :
// - la page marche SANS lui : marque, titres, `href` des boutons, mentions légales et tout le texte
//   sont écrits en clair dans le HTML ; ce script n'apporte que du confort (logo, burger,
//   glissement, révélation, curseur de palier) et la mesure d'audience ;
// - un élément qui n'a de sens qu'avec JS porte `data-js-only hidden` : on le montre ici ;
// - chaque bloc vit dans son propre try/catch et teste la présence de ses éléments : un
//   `config.js` absent (404) ou périmé en cache n'emporte ni le burger, ni les ancres ;
// - la configuration se lit TOUJOURS avec un repli (`|| {}`) ;
// - aucun bloc ne réécrit la marque ni le titre (gardé par `LandingDiscoverabilityTest`) ;
// - tout ce qui bouge lit `prefers-reduced-motion` et reste immobile si le visiteur le demande.
(function () {
  "use strict";

  var cfg = {};
  try {
    cfg = window.LANDING_CONFIG || {};
  } catch (e) {
    cfg = {};
  }
  var reduced = function () {
    try { return window.matchMedia("(prefers-reduced-motion: reduce)").matches; } catch (e) { return false; }
  };

  // ── 0. JS présent : classe `js` sur <html>, éléments réservés au JS montrés ──
  try {
    document.documentElement.classList.add("js");
    document.querySelectorAll("[data-js-only]").forEach(function (el) { el.hidden = false; });
  } catch (e) { /* sans classe : la page reste dans sa forme sans JS */ }

  // ── 1. Logo, boutons d'appel (depuis config.js) ──────────────────────────────
  try {
    // Icône du logotype : si `logo` manque (ancien cache) ou si l'image échoue, on retire
    // l'icône — le MOT (texte) reste, la marque demeure lisible, jamais d'image cassée.
    document.querySelectorAll("[data-brand-logo]").forEach(function (img) {
      if (cfg.logo) {
        img.onerror = function () { img.onerror = null; img.remove(); };
        if (img.getAttribute("src") !== cfg.logo) img.src = cfg.logo;
      }
    });

    // Boutons d'appel : leurs `href` sont DÉJÀ écrits en clair (visiteur sans JS) ; on les
    // réécrit à l'identique depuis config.js — un changement de domaine y est rattrapé.
    // ⚠ Objet ET corps du mailto 100 % ASCII : certains clients mail cassent les accents.
    if (cfg.appUrl) {
      var app = cfg.appUrl;
      document.querySelectorAll("[data-cta=register]").forEach(function (a) { a.href = app + "/register"; });
      document.querySelectorAll("[data-cta=login]").forEach(function (a) { a.href = app + "/login"; });
      document.querySelectorAll("[data-cta=privacy]").forEach(function (a) { a.href = app + "/confidentialite"; });
    }
    if (cfg.contactEmail && cfg.brand) {
      var mailto = "mailto:" + cfg.contactEmail
        + "?subject=" + encodeURIComponent("Demande de demo - " + cfg.brand)
        + "&body=" + encodeURIComponent("Bonjour,\n\nJe souhaite voir " + cfg.brand + " en demo pour mon club.\n\nClub : \nNombre d'equipes (environ) : \nDisponibilites : ");
      document.querySelectorAll("[data-cta=demo],[data-cta=demo-inline]").forEach(function (a) { a.href = mailto; });
    }
  } catch (e) { /* config absent / ancien cache : les href écrits en clair restent */ }

  try {
    // Mesure d'audience Umami (sans cookie) — DOUBLE garde : (a) les deux clés `analytics` non
    // vides ; (b) hostname courant = l'hôte dérivé de `siteUrl` (aucun littéral de domaine ici).
    // En local, sur :5173 ou sur un autre domaine, RIEN n'est injecté.
    var analytics = cfg.analytics || {};
    var siteHost = "";
    try { siteHost = new URL(cfg.siteUrl).hostname; } catch (e) { siteHost = ""; }
    if (analytics.scriptUrl && analytics.websiteId && siteHost && location.hostname === siteHost) {
      var tag = document.createElement("script");
      tag.defer = true;
      tag.src = analytics.scriptUrl;
      tag.setAttribute("data-website-id", analytics.websiteId);
      document.head.appendChild(tag);
    }
  } catch (e) { /* pas de mesure, page intacte */ }

  // ── 2. Menu mobile (burger, toutes les pages) ───────────────────────────────
  // Confort au-dessus du <details> natif (qui marche sans JS) : miroir aria-expanded,
  // fermeture au clic sur un lien du panneau, Échap → focus le burger. No-op sans burger.
  try {
    var menu = document.querySelector("details.nav-menu");
    if (menu) {
      var summary = menu.querySelector("summary");
      var sync = function () { if (summary) summary.setAttribute("aria-expanded", String(menu.open)); };
      menu.addEventListener("toggle", sync);
      sync();
      menu.querySelectorAll(".nav-panel a").forEach(function (a) {
        a.addEventListener("click", function () { menu.open = false; });
      });
      menu.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && menu.open) { menu.open = false; if (summary) summary.focus(); }
      });
    }
  } catch (e) { /* burger : le <details> natif reste */ }

  // ── 3. Glissement des ancres internes ──────────────────────────────────────
  // Décélération maîtrisée (easeOutCubic) en requestAnimationFrame — pas de
  // `scroll-behavior: smooth`. Saut direct sous prefers-reduced-motion ; coupé net si
  // l'utilisateur reprend la main. La position de la cible est RECALCULÉE à chaque pas (une
  // police ou une image qui arrive pendant le glissement déplace la cible), puis un dernier
  // `scrollTo` pose la position exacte. Un lien `/#ancre` lu depuis l'accueil glisse aussi.
  try {
    var PAD = 76; // = scroll-padding-top de site.css (en-tête sticky 64px + marge)
    var easeOutCubic = function (t) { return 1 - Math.pow(1 - t, 3); };
    var find = function (hash) {
      try { return document.querySelector(hash); } catch (e) { return null; }
    };
    var targetY = function (hash) {
      if (hash === "#" || hash === "") return 0;
      var el = find(hash);
      if (!el) return null;
      var top = el.getBoundingClientRect().top + window.scrollY;
      return Math.max(0, Math.round(top - PAD));
    };
    var focusTarget = function (hash) {
      if (hash === "#" || hash === "") return;
      var el = find(hash);
      if (!el) return;
      if (!el.hasAttribute("tabindex")) el.setAttribute("tabindex", "-1"); // focusable sans piège tab
      el.focus({ preventScroll: true }); // le focus ne doit PAS re-scroller
    };

    // Police arrivée APRÈS la fin du glissement (réseau lent) : les titres au-dessus changent de
    // hauteur et la cible repasse sous l'en-tête. On la recale une fois, quand les polices sont
    // prêtes — sauf si le visiteur a repris la main entre-temps.
    var settle = function (hash) {
      try {
        if (!document.fonts || document.fonts.status === "loaded") return;
        var moved = false;
        var mark = function () { moved = true; };
        window.addEventListener("wheel", mark, { passive: true, once: true });
        window.addEventListener("touchstart", mark, { passive: true, once: true });
        window.addEventListener("keydown", mark, { once: true });
        document.fonts.ready.then(function () {
          if (moved) return;
          var y = targetY(hash);
          if (y !== null) window.scrollTo(0, y);
        });
      } catch (e) { /* sans recalage : la position du glissement reste */ }
    };

    var raf = null;
    var stop = function () {
      if (raf) { cancelAnimationFrame(raf); raf = null; }
      window.removeEventListener("wheel", stop, { passive: true });
      window.removeEventListener("touchstart", stop, { passive: true });
      window.removeEventListener("keydown", stop, true);
    };
    var animate = function (hash, done) {
      stop();
      var start = window.scrollY;
      var first = targetY(hash);
      if (first === null) return;
      var dist = first - start;
      if (Math.abs(dist) < 2) { window.scrollTo(0, first); if (done) done(); return; }
      // Durée proportionnelle à la distance (~0,6 ms/px), plafonnée à 600–900 ms.
      var dur = Math.min(900, Math.max(600, Math.abs(dist) * 0.6));
      var t0 = performance.now();
      window.addEventListener("wheel", stop, { passive: true });
      window.addEventListener("touchstart", stop, { passive: true });
      window.addEventListener("keydown", stop, true);
      var step = function (now) {
        var p = Math.min(1, (now - t0) / dur);
        var y = targetY(hash);
        if (y === null) y = first;
        if (p < 1) {
          window.scrollTo(0, Math.round(start + (y - start) * easeOutCubic(p)));
          raf = requestAnimationFrame(step);
        } else {
          window.scrollTo(0, y);
          stop();
          if (done) done();
        }
      };
      raf = requestAnimationFrame(step);
    };

    // Le hash d'un lien qui mène à une ancre de LA page courante (`#x`, ou `/#x` lu depuis `/`).
    var samePageHash = function (a) {
      var href = a.getAttribute("href");
      if (href === null) return null;
      if (href.charAt(0) === "#") return href;
      if (a.hash && a.pathname === location.pathname && a.host === location.host) return a.hash;
      return null;
    };

    document.addEventListener("click", function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var t = e.target;
      var a = t && t.closest ? t.closest("a[href]") : null;
      if (!a) return;
      var hash = samePageHash(a);
      if (hash === null) return;
      var y = targetY(hash);
      if (y === null) return; // cible absente : comportement par défaut
      e.preventDefault();
      try {
        if (hash === "#") history.pushState(null, "", location.pathname + location.search);
        else history.pushState(null, "", hash);
      } catch (err) { /* file:// ou restrictions : sans blocage */ }
      if (reduced()) { window.scrollTo(0, y); focusTarget(hash); settle(hash); return; }
      animate(hash, function () { focusTarget(hash); settle(hash); });
    });
  } catch (e) { /* glissement : le saut natif (scroll-padding-top) reste */ }

  // ── 4. Révélation au défilement (`[data-rv]`) ──────────────────────────────
  // Un bloc arrive en douceur quand il entre à l'écran. Rien ne se cache sans JS ni en
  // mouvement réduit : la classe `wait` n'est posée qu'ici, et seulement hors de l'écran.
  try {
    if (!reduced() && "IntersectionObserver" in window) {
      var watch = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) { entry.target.classList.remove("wait"); watch.unobserve(entry.target); }
        });
      }, { threshold: 0.2 });
      document.querySelectorAll("[data-rv]").forEach(function (el) {
        if (el.getBoundingClientRect().top < window.innerHeight) return; // déjà visible : rien à révéler
        el.classList.add("wait");
        watch.observe(el);
      });
    }
  } catch (e) { /* révélation : les blocs restent affichés */ }

  // ── 5. Curseur de palier (`[data-tiers]`) ──────────────────────────────────
  // Le visiteur règle son nombre d'équipes, son palier s'allume (`li[data-max]`, bornes de la
  // grille du produit). L'événement `tiers` (detail = nombre) sert au bouton final de l'accueil.
  try {
    document.querySelectorAll("[data-tiers]").forEach(function (box) {
      var range = box.querySelector("input[type=range]");
      var out = box.querySelector("output");
      var tiers = box.querySelectorAll("li[data-max]");
      if (!range) return;
      var paint = function () {
        var n = +range.value;
        var lit = false;
        if (out) out.textContent = String(n);
        tiers.forEach(function (li) {
          var on = !lit && n <= +li.getAttribute("data-max");
          if (on) lit = true;
          li.classList.toggle("on", on);
        });
        try { box.dispatchEvent(new CustomEvent("tiers", { detail: n, bubbles: true })); } catch (e) { /* vieux navigateur */ }
      };
      range.addEventListener("input", paint);
      paint();
    });
  } catch (e) { /* curseur : la liste des paliers reste lisible */ }
})();

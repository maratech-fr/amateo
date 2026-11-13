// ── Accueil (`index.html`) : compte à rebours, scène de la saison pilotée par le défilement,
// en-tête qui se resserre, barre de lecture, panier final, bouton final qui suit le curseur.
// Chargé après `assets/site.js` (qui montre les `[data-js-only]` et anime le curseur de palier).
// Sans ce script, tout le texte de la page reste lisible ; il n'ajoute que du mouvement.
// Mouvement réduit (`prefers-reduced-motion`) : compte à rebours figé en jours, scène sans
// transition (site.css coupe animations et transitions), aucun glissement de texte.
(function () {
  "use strict";

  var reduced = false;
  try { reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches; } catch (e) { reduced = false; }
  var tick = function (el) {
    if (reduced || !el) return;
    el.classList.remove("tk");
    void el.offsetWidth;
    el.classList.add("tk");
  };

  // ── 0. Un ancien lien `/#faq` mène à la page des questions ──────────────────
  try {
    if (location.hash === "#faq") location.replace("faq.html");
  } catch (e) { /* lien ancien : on reste sur l'accueil */ }

  // ── 1. Compte à rebours vers la prochaine échéance de la saison ─────────────
  try {
    var box = document.querySelector("[data-countdown]");
    if (box) {
      var next = null;
      var now = Date.now();
      box.querySelectorAll("[data-deadlines] li[data-date]").forEach(function (li) {
        if (next) return;
        var p = (li.getAttribute("data-date") || "").split("-");
        var at = new Date(+p[0], +p[1] - 1, +p[2]).getTime(); // minuit, heure du visiteur
        if (at > now) next = { at: at, label: li.textContent.trim(), question: li.getAttribute("data-question") || "" };
      });
      if (!next) {
        box.hidden = true; // liste épuisée : le héros s'affiche sans compte à rebours
      } else {
        var label = box.querySelector("[data-cd-label]");
        var digits = box.querySelector("[data-cd-digits]");
        var question = box.querySelector("[data-cd-question]");
        var cells = digits ? digits.querySelectorAll("b") : [];
        var units = digits ? digits.querySelectorAll("span") : [];
        if (question) question.textContent = next.question;
        var days = function (s) { return Math.floor(s / 86400); };
        if (reduced) {
          // Une ligne fixe, en jours : rien ne défile.
          var left = Math.max(0, Math.floor((next.at - Date.now()) / 1000));
          var d = days(left);
          var when = d >= 2 ? d + " jours" : (d === 1 ? "1 jour" : "moins d’un jour");
          box.classList.add("still");
          if (label) label.textContent = next.label + " " + when;
          if (digits) digits.hidden = true;
        } else {
          if (label) label.textContent = next.label;
          var last = [];
          var count = function () {
            var s = Math.max(0, Math.floor((next.at - Date.now()) / 1000));
            var v = [days(s), Math.floor(s % 86400 / 3600), Math.floor(s % 3600 / 60), s % 60];
            v.forEach(function (n, i) {
              var t = (n < 10 ? "0" : "") + n;
              if (last[i] === t || !cells[i]) return;
              last[i] = t;
              cells[i].textContent = t;
              tick(cells[i]);
            });
            // « jour » / « jours » sous le premier chiffre.
            if (units[0] && units[0].lastChild) units[0].lastChild.textContent = v[0] > 1 ? "jours" : "jour";
          };
          count();
          setInterval(count, 1000);
        }
      }
    }
  } catch (e) { /* compte à rebours : le héros reste sans lui */ }

  // ── 2. La scène de la saison ───────────────────────────────────────────────
  // Dix moments, un par étape de `[data-scene-steps]` : la grille, le bandeau d'état, les mois
  // et le score suivent l'étape qui passe au milieu de l'écran (et reviennent quand on remonte).
  var scene = null;
  try {
    var board = document.querySelector("[data-scene-board]");
    var stepsBox = document.querySelector("[data-scene-steps]");
    if (board && stepsBox) {
      var status = document.querySelector("[data-scene-status]");
      var stTxt = status ? status.querySelector("span") : null;
      var chips = document.querySelector("[data-scene-chips]");
      var chipItems = chips ? chips.children : [];
      var steps = stepsBox.children;
      var cap = document.querySelector("[data-scene-cap]");
      var score = document.querySelector("[data-scene-score]");
      var TEAMS = [["U7", "y"], ["U9", "y"], ["U11", "y"], ["U13F", "m"], ["U13M", "m"], ["U15M", "m"], ["U18F", "m"], ["SF1", "s"], ["SM1", "s"], ["Loisirs", "l"], ["SF2", "s"]];
      var BASE = { U7: [4, 0], U9: [0, 0], U11: [2, 0], U13F: [1, 1], U13M: [0, 4], U15M: [0, 1], U18F: [3, 1], SF1: [4, 1], SM1: [1, 4], Loisirs: [2, 2], SF2: null };
      var frame = function (over, o) {
        var p = {}, k;
        for (k in BASE) p[k] = BASE[k];
        for (k in over) p[k] = over[k];
        o.pos = p;
        o.m = o.m || [];
        return o;
      };
      var NONE = { U7: null, U9: null, U11: null, U13F: null, U13M: null, U15M: null, U18F: null, SF1: null, SM1: null, Loisirs: null };
      var F = [
        frame({}, { chips: 1, txt: "10 équipes placées · 0 conflit" }),
        frame({ U7: null, U9: null, U11: null, U13F: null, U13M: null, Loisirs: null }, { chips: 2, txt: "Reprise · 4 équipes sur 10 cette semaine" }),
        frame({}, { chips: 3, bad: "U15M", txt: "Import FBI · 1 conflit repéré" }),
        frame({ U15M: [4, 4] }, { chips: 3, m: [[0, 1, "Match U18F"]], txt: "Conflit réglé · 1 séance déplacée" }),
        frame({ U13F: null, U13M: [1, 0], SM1: [1, 2] }, { chips: 4, annexe: 1, txt: "Toussaint · 9 séances · 2 déplacées · 1 annulée" }),
        frame({}, { chips: 5, m: [[2, 1, "Match U18F"]], txt: "Réimport FBI · 1 match déplacé · 0 conflit" }),
        frame({}, { chips: 6, m: [[2, 1, "Match U18F"], [3, 2, "Match SM1"]], txt: "Phase 3 · 2 matchs en semaine · 0 conflit" }),
        frame({ SF1: [4, 2] }, { chips: 7, m: [[4, 1, "Playoff U18F"], [3, 2, "Match SM1"]], txt: "Playoffs · 1 séance déplacée · 0 conflit" }),
        frame({ SF2: [3, 2] }, { chips: 8, txt: "Saison prochaine · 11 équipes placées · planning prêt" }),
        frame(NONE, { chips: 9, beach: 1, txt: "Aucune séance · bonnes vacances" })
      ];
      var cells = [], els = {}, cur = -1, d, r, c;
      for (d = 0; d < 5; d++) {
        for (r = 0; r < 5; r++) {
          c = document.createElement("i");
          c.className = "a-cellx";
          c.style.setProperty("--d", d);
          c.style.setProperty("--r", r);
          board.appendChild(c);
          cells[d * 5 + r] = c;
        }
      }
      TEAMS.forEach(function (t, i) {
        var el = document.createElement("div");
        el.className = "a-blk " + t[1];
        el.style.setProperty("--tilt", ((i * 37) % 7 - 3) + "deg");
        var b = document.createElement("b");
        b.textContent = t[0];
        el.appendChild(b);
        board.appendChild(el);
        els[t[0]] = el;
      });
      var beach = document.createElement("div");
      beach.className = "p-beach";
      beach.innerHTML = '<svg viewBox="0 0 500 350" preserveAspectRatio="none"><rect width="500" height="350" fill="#fff3d6"/><circle cx="405" cy="78" r="44" fill="#d47800"/><circle cx="405" cy="78" r="62" fill="none" stroke="#d47800" stroke-width="5" stroke-dasharray="4 16" stroke-linecap="round"/><rect y="188" width="500" height="70" fill="#46afac"/><path d="M0 200q31-14 62 0t62 0 62 0 62 0 62 0 62 0 62 0 62 0" fill="none" stroke="#f4f7f5" stroke-width="5" stroke-linecap="round"/><path d="M0 226q31-12 62 0t62 0 62 0 62 0 62 0 62 0 62 0 62 0" fill="none" stroke="#f4f7f5" stroke-width="4" stroke-linecap="round" opacity=".7"/><path d="M0 250q250-22 500 0v100H0z" fill="#f3dfb5"/><path d="M150 150v150" stroke="#11302f" stroke-width="6" stroke-linecap="round"/><path d="M60 158a90 62 0 0 1 180 0z" fill="#b51c8a"/><path d="M105 158a45 62 0 0 1 90 0z" fill="#f4f7f5"/><path d="M215 300l95-52" stroke="#11302f" stroke-width="7" stroke-linecap="round"/><path d="M228 262l70 44" stroke="#11302f" stroke-width="7" stroke-linecap="round"/><path d="M226 294l84-46" stroke="#2e7876" stroke-width="16" stroke-linecap="round"/><circle cx="352" cy="298" r="18" fill="#d47800"/><path d="M334 298h36M352 280v36" stroke="#1a0d06" stroke-width="2.5"/></svg>';
      board.appendChild(beach);

      var apply = function (n) {
        if (n === cur) return;
        var f = F[n], prev = cur < 0 ? null : F[cur], k;
        cells.forEach(function (el, idx) {
          var dd = Math.floor(idx / 5), rr = idx % 5, mt = null;
          f.m.forEach(function (x) { if (x[0] === dd && x[1] === rr) mt = x[2]; });
          el.classList.toggle("mt", !!mt);
          el.textContent = mt || "";
          el.classList.toggle("x", !mt && !!(f.annexe && rr > 2));
        });
        for (k in els) {
          var s = f.pos[k], p = prev ? prev.pos[k] : null, el = els[k];
          el.classList.toggle("bad", f.bad === k);
          if (!s) { el.classList.add("off"); el.classList.remove("moved"); continue; }
          el.style.setProperty("--d", s[0]);
          el.style.setProperty("--r", s[1]);
          el.classList.remove("off");
          el.classList.toggle("moved", !!(prev && n > cur && p && (p[0] !== s[0] || p[1] !== s[1])));
        }
        beach.classList.toggle("on", !!f.beach);
        Array.prototype.forEach.call(chipItems, function (li, i) {
          li.classList.toggle("on", i < f.chips);
          li.classList.toggle("new", i === f.chips - 1 && n > 0);
        });
        if (stTxt) stTxt.textContent = f.txt;
        if (status) status.classList.toggle("busy", !!f.bad);
        Array.prototype.forEach.call(steps, function (li, i) { li.classList.toggle("on", i === n); });
        if (cap && steps[n]) {
          cap.innerHTML = steps[n].innerHTML; // copie du HTML de la page, aucune donnée extérieure
          if (cur >= 0) tick(cap);
        }
        if (score) { score.textContent = String(2 * (n + 1)); if (cur >= 0) tick(score); }
        cur = n;
      };
      apply(0);
      scene = function (vh) {
        var n = 0, i;
        for (i = 0; i < steps.length; i++) if (steps[i].getBoundingClientRect().top < vh * 0.6) n = i;
        apply(n);
      };
    }
  } catch (e) { scene = null; /* scène : les étapes restent lisibles */ }

  // ── 3. Le défilement : en-tête, barre de lecture, scène, « Vous gardez la main », quart-temps, panier ──
  try {
    var head = document.querySelector("header.site");
    var bar = document.querySelector(".p-prog");
    var say = document.querySelector("[data-scene-say]");
    var quarts = document.querySelector("[data-scene-quarts]");
    var hoop = document.querySelector("[data-scene-hoop]");
    var points = document.querySelector("[data-scene-points]");
    var steps10 = document.querySelectorAll("[data-scene-steps] > li").length;
    var scored = false;
    var prog = function (el, vh) {
      var rc = el.getBoundingClientRect();
      return Math.max(0, Math.min(1, (vh - rc.top) / (vh + rc.height)));
    };
    var onScroll = function () {
      var vh = window.innerHeight || 1;
      var doc = document.documentElement;
      var max = Math.max(1, doc.scrollHeight - vh);
      var y = window.scrollY || 0;
      if (bar) bar.style.setProperty("--sp", Math.max(0, Math.min(1, y / max)).toFixed(4));
      if (head) head.classList.toggle("small", y > 40);
      if (scene) scene(vh);
      if (!reduced) {
        if (say) say.style.setProperty("--st", prog(say, vh).toFixed(3));
        if (quarts) quarts.style.setProperty("--qp", prog(quarts, vh).toFixed(3));
      }
      if (hoop && points) {
        var hr = hoop.getBoundingClientRect();
        if (hr.top < vh * 0.85 && hr.bottom > 0) {
          if (!scored) {
            scored = true;
            points.textContent = String(2 * steps10);
            if (!reduced) { hoop.classList.remove("in"); void hoop.offsetWidth; hoop.classList.add("in"); }
          }
        } else if (hr.top > vh) {
          scored = false;
          points.textContent = "0";
        }
      }
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);
    onScroll();
    // Le « avec » se barre une fois, à l'arrivée sur la page.
    var hero = document.querySelector(".p-hero");
    if (hero && !reduced && "requestAnimationFrame" in window) requestAnimationFrame(function () { hero.classList.add("go"); });
  } catch (e) { /* défilement : la page reste immobile */ }

  // ── 4. Le bouton final suit le curseur des offres ───────────────────────────
  try {
    var finalCta = document.querySelector("[data-final-cta]");
    var tiersBox = document.querySelector("#offres [data-tiers]");
    if (finalCta && tiersBox) {
      var label = function (n) { return "Essayer avec mon club de " + n + " équipe" + (n > 1 ? "s" : ""); };
      var range = tiersBox.querySelector("input[type=range]");
      if (range) finalCta.textContent = label(+range.value);
      tiersBox.addEventListener("tiers", function (e) { finalCta.textContent = label(+e.detail); });
    }
  } catch (e) { /* le bouton garde son libellé sans JS */ }
})();

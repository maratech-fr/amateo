// ── Fonctionnalités (`fonctionnalites.html`) : les onglets des deux modules, la glissière « du
// tableur à Amateo » et le tableau de marque du récapitulatif. Sans ce script, les treize modules se
// lisent l'un sous l'autre et les chiffres sont écrits à leur valeur ; onglets, glissière et bouton
// « Rejouer » restent cachés (`data-js-only hidden`, montrés par `assets/site.js`, chargé avant).
// Mouvement réduit (`prefers-reduced-motion`) : les chiffres ne comptent pas, ils restent à leur valeur.
(function () {
  "use strict";

  var reduced = false;
  try { reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches; } catch (e) { reduced = false; }

  // ── 1. Deux modules, un onglet chacun (flèches gauche/droite au clavier) ─────
  try {
    var tabs = Array.prototype.slice.call(document.querySelectorAll(".fn-seg [role=tab]"));
    var select = function (tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        var pane = document.getElementById(t.getAttribute("aria-controls"));
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
        if (pane) {
          pane.hidden = !on;
          pane.setAttribute("role", "tabpanel");
          pane.setAttribute("aria-labelledby", t.id);
        }
      });
      if (focus) tab.focus();
    };
    if (tabs.length) {
      tabs.forEach(function (t, i) {
        t.addEventListener("click", function () { select(t, false); });
        t.addEventListener("keydown", function (e) {
          var step = e.key === "ArrowRight" ? 1 : e.key === "ArrowLeft" ? -1 : 0;
          if (!step) return;
          e.preventDefault();
          select(tabs[(i + step + tabs.length) % tabs.length], true);
        });
      });
      select(tabs[0], false);
    }
  } catch (e) { /* onglets absents : les deux modules restent l'un sous l'autre */ }

  // ── 2. Glissière : tout à gauche le tableur, tout à droite Amateo ─────────────
  try {
    var cmp = document.querySelector("[data-compare]");
    if (cmp) {
      var range = cmp.querySelector("input[type=range]");
      var paint = function () {
        cmp.style.setProperty("--x", range.value + "%");
        range.setAttribute("aria-valuetext", range.value + " % Amateo");
      };
      range.addEventListener("input", paint);
      paint();
    }
  } catch (e) { /* glissière : reste cachée */ }

  // ── 3. Le tableau de marque compte jusqu'à sa valeur, à l'arrivée et sur « Rejouer » ──
  try {
    var board = document.querySelector("[data-count]");
    var again = document.querySelector("[data-count-replay]");
    if (board) {
      var timers = [];
      var count = function () {
        if (reduced) return;
        timers.forEach(clearInterval);
        timers = [];
        board.querySelectorAll("b[data-n]").forEach(function (b) {
          var end = Number(b.getAttribute("data-n"));
          var from = end === 0 ? 9 : 0;
          var steps = 12;
          var i = 0;
          b.textContent = String(from);
          var id = setInterval(function () {
            i++;
            b.textContent = String(Math.round(from + (end - from) * i / steps));
            if (i >= steps) { b.textContent = String(end); clearInterval(id); }
          }, 70);
          timers.push(id);
        });
      };
      if (again) {
        if (reduced) again.hidden = true;
        again.addEventListener("click", count);
      }
      if (!reduced && "IntersectionObserver" in window) {
        var seen = new IntersectionObserver(function (entries) {
          if (entries.some(function (en) { return en.isIntersecting; })) { seen.disconnect(); count(); }
        }, { threshold: 0.6 });
        seen.observe(board);
      }
    }
  } catch (e) { /* chiffres : restent à leur valeur */ }
})();

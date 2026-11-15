// ── Questions fréquentes (`faq.html`) : le compteur de questions lues et les trois démonstrations
// (import d'un planning en photo, réponse du coach, export PDF). Les questions s'ouvrent seules,
// sans ce script (`<details>` natifs) ; il est chargé après `assets/site.js`.
// Mouvement réduit (`prefers-reduced-motion`) : aucune animation n'est relancée, les lignes de
// l'aperçu arrivent d'un coup.
(function () {
  "use strict";

  var reduced = false;
  try { reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches; } catch (e) { reduced = false; }
  var replay = function (el, cls) {
    if (reduced || !el) return;
    el.classList.remove(cls);
    void el.offsetWidth;
    el.classList.add(cls);
  };

  // ── 1. Questions lues : chaque question ouverte compte une fois ──────────────
  try {
    var out = document.querySelector("[data-faq-read]");
    var seen = {};
    var items = document.querySelectorAll("details.fq-item");
    var paint = function () {
      var n = 0, k;
      for (k in seen) n++;
      if (out) out.textContent = String(n);
    };
    items.forEach(function (d) {
      if (d.open) seen[d.id] = 1;
      d.addEventListener("toggle", function () {
        if (d.open) { seen[d.id] = 1; paint(); }
      });
    });
    paint();
  } catch (e) { /* compteur : les questions restent ouvrables */ }

  // ── 2. On ne repart pas de zéro : l'aperçu d'un planning lu depuis une photo ──
  try {
    var box = document.querySelector("[data-import]");
    if (box) {
      var LINES = [["g", "Gymnase municipal"], ["g", "Salle annexe"], ["e", "U9"], ["e", "U11"], ["e", "U13F"], ["e", "U15M"], ["e", "SF1"], ["e", "SM1"], ["e", "Baby basket", 1],
        ["c", "Lundi 17 h 30 · U9 · Municipal"], ["c", "Mardi 19 h · U13F · Municipal"], ["c", "Mercredi 17 h 30 · U11 · Annexe"], ["c", "Jeudi 19 h · U15M · Municipal"], ["c", "Jeudi 20 h 30 · SM1 · Municipal"], ["c", "Vendredi 20 h 30 · SF1 · Municipal"]];
      var KIND = { g: "Gymnase", e: "Équipe", c: "Créneau" };
      var list = box.querySelector("[data-import-lines]");
      var count = box.querySelector("[data-import-count]");
      var photo = box.querySelector("[data-import-photo]");
      var timers = [];
      var plural = function (n, w) { return n + " " + w + (n > 1 ? "s" : ""); };
      var tally = function () {
        var n = list.querySelectorAll("input:checked").length;
        count.textContent = plural(n, "ligne") + " reconnue" + (n > 1 ? "s" : "") + " sur " + list.children.length + ". Relisez, décochez ce qui ne va pas.";
      };
      var line = function (l, i) {
        var li = document.createElement("li");
        li.className = "k" + l[0];
        var label = document.createElement("label");
        var input = document.createElement("input");
        input.type = "checkbox";
        input.id = "import-line-" + i;
        input.checked = !l[2];
        var text = document.createElement("span");
        text.textContent = l[1];
        label.appendChild(input);
        label.appendChild(text);
        if (l[2]) { var em = document.createElement("em"); em.textContent = "à classer"; label.appendChild(em); }
        var kind = document.createElement("small");
        kind.textContent = KIND[l[0]];
        label.appendChild(kind);
        li.appendChild(label);
        list.appendChild(li);
      };
      var build = function (animated) {
        timers.forEach(clearTimeout);
        timers = [];
        list.textContent = "";
        if (!animated || reduced) { LINES.forEach(line); tally(); return; }
        count.textContent = "Lecture de la photo…";
        replay(photo, "scan");
        LINES.forEach(function (l, i) {
          timers.push(setTimeout(function () { line(l, i); if (i === LINES.length - 1) tally(); }, 500 + i * 120));
        });
      };
      box.querySelector("[data-import-read]").addEventListener("click", function () { build(true); });
      list.addEventListener("change", tally);
      box.querySelector("[data-import-go]").addEventListener("click", function () {
        var n = list.querySelectorAll("input:checked").length;
        count.textContent = plural(n, "élément") + " importé" + (n > 1 ? "s" : "") + " dans votre club. Il reste à ajouter vos coachs et vos règles.";
      });
      build(false);
    }
  } catch (e) { /* démonstration : la réponse écrite reste */ }

  // ── 3. Le coach répond par son lien personnel ───────────────────────────────
  try {
    var coach = document.querySelector("[data-coach]");
    if (coach) {
      var days = coach.querySelector("[data-coach-days]");
      var plan = coach.querySelector("[data-coach-plan]");
      var answer = coach.querySelector("[data-coach-answer]");
      var arrow = coach.querySelector("[data-coach-arrow]");
      days.addEventListener("click", function (e) {
        var b = e.target.closest("button");
        if (b) b.setAttribute("aria-pressed", b.getAttribute("aria-pressed") === "true" ? "false" : "true");
      });
      coach.querySelector("[data-coach-send]").addEventListener("click", function () {
        var picked = [];
        days.querySelectorAll("button[aria-pressed=true]").forEach(function (b) { picked.push(b.textContent.toLowerCase() + "."); });
        if (!picked.length) { answer.textContent = "Choisissez au moins un jour."; return; }
        replay(arrow, "go");
        setTimeout(function () {
          answer.textContent = "Coach des U13 : libre " + picked.join(", ") + " Réponse reçue.";
          plan.classList.add("got");
          replay(plan, "got");
        }, reduced ? 0 : 450);
      });
    }
  } catch (e) { /* démonstration : la réponse écrite reste */ }

  // ── 4. La feuille des matchs sort de l'écran ────────────────────────────────
  try {
    var sheet = document.querySelector("[data-export]");
    var go = document.querySelector("[data-export-go]");
    if (sheet && go) {
      go.addEventListener("click", function () { replay(sheet, "go"); });
      var holder = sheet.closest("details");
      if (holder) holder.addEventListener("toggle", function () { if (holder.open) replay(sheet, "go"); });
    }
  } catch (e) { /* démonstration : la réponse écrite reste */ }
})();

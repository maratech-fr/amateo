// ── Offres (`offres.html`) : la démonstration des dix crédits de la Découverte.
// Chargé après `assets/site.js` (qui montre les `[data-js-only]` et anime le curseur de palier).
// Mouvement réduit : les crédits s'éteignent sans glisser (site.css coupe les transitions sous
// `prefers-reduced-motion`) ; ce script ne lance aucune animation de lui-même.
(function () {
  "use strict";

  try {
    var box = document.querySelector("[data-credits]");
    if (!box) return;
    var row = box.querySelector("[data-credits-row]");
    var left = box.querySelector("[data-credits-left]");
    var line = box.querySelector("[data-credits-line]");
    var actions = box.querySelector("[data-credits-actions]");
    if (!row || !left || !line || !actions) return;
    var START = 10;
    var DEFAULT = "Chaque planning généré, placement de matchs ou export utilise un crédit.";
    var credits = START;
    for (var i = 0; i < START; i++) row.appendChild(document.createElement("i"));

    actions.addEventListener("click", function (e) {
      var b = e.target.closest("button");
      if (!b) return;
      if (b.hasAttribute("data-reset")) {
        credits = START;
        row.querySelectorAll("i").forEach(function (x) { x.classList.remove("used"); });
        left.textContent = String(START);
        line.textContent = DEFAULT;
        return;
      }
      if (b.hasAttribute("data-free")) {
        line.textContent = "Séance déplacée à la main : aucun crédit utilisé. Les ajustements manuels sont illimités.";
        return;
      }
      if (credits <= 0) {
        line.textContent = "Plus de crédit. Les ajustements à la main restent illimités, sans limite de durée.";
        return;
      }
      credits--;
      row.children[credits].classList.add("used");
      left.textContent = String(credits);
      line.textContent = b.getAttribute("data-c") + " : 1 crédit utilisé. Il en reste " + credits + ".";
    });
  } catch (e) { /* démonstration : le texte de la page reste */ }
})();

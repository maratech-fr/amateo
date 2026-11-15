// ── Journal des évolutions (`evolutions.html`) : le filtre par thème et le compteur de versions.
// Toutes les versions sont dans le HTML : sans ce script, le journal se lit en entier et le filtre
// reste caché (`data-js-only hidden`, montré par `assets/site.js`, chargé avant celui-ci).
// Rien ne s'anime ici ; la feuille coupe toute transition sous mouvement réduit.
(function () {
  "use strict";

  try {
    var bar = document.querySelector(".jr-f");
    var list = document.querySelector("[data-jr]");
    if (!bar || !list) return;
    var count = bar.querySelector("[data-jr-count]");
    var items = list.querySelectorAll("article.release");

    // Grand écran : les deux files se resserrent, et une date ne doit jamais toucher la précédente.
    // On descend d'autant la version qui la ferait déborder (les flottants ne remontent jamais plus haut
    // que les précédents : un seul passage dans l'ordre suffit).
    var wide = window.matchMedia("(min-width: 821px)");
    var stagger = function () {
      var tops = [];
      items.forEach(function (a) { a.style.marginTop = ""; });
      if (!wide.matches) return;
      items.forEach(function (a) {
        if (a.hidden) return;
        var time = a.querySelector("time");
        var room = time.getBoundingClientRect().height + 8;
        var top = time.getBoundingClientRect().top;
        var need = 0;
        tops.forEach(function (t) { if (top - t < room) need = Math.max(need, room - (top - t)); });
        if (need > 0) {
          a.style.marginTop = Math.ceil(need) + "px";
          top = time.getBoundingClientRect().top;
        }
        tops.push(top);
      });
    };
    if (wide.addEventListener) wide.addEventListener("change", stagger);

    // Montre les versions du thème choisi (toutes si vide), une sur deux de l'autre côté de la frise
    var show = function (topic) {
      var n = 0;
      items.forEach(function (article) {
        var on = !topic || article.getAttribute("data-topic") === topic;
        article.hidden = !on;
        article.classList.toggle("r", on && n % 2 === 1);
        if (on) n++;
      });
      stagger();
      if (count) count.textContent = n + " version" + (n > 1 ? "s" : "");
    };

    bar.addEventListener("click", function (e) {
      var button = e.target.closest("button[data-f]");
      if (!button) return;
      bar.querySelectorAll("button[data-f]").forEach(function (b) {
        b.setAttribute("aria-pressed", b === button ? "true" : "false");
      });
      show(button.getAttribute("data-f"));
    });
    show("");
  } catch (e) { /* filtre absent : le journal entier reste lisible */ }
})();

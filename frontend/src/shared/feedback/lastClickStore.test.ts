import { afterEach, describe, expect, it } from "vitest";

import { clearLastClick, readRecentLastClick, recordLastClick } from "./lastClickStore";

/**
 * Chantier F PR2 — mémoire du DERNIER contrôle cliqué, pour situer le geste de
 * l'utilisateur au moment où il signale un problème. Patron `lastIncidentStore` :
 * présentation pure, fenêtre de fraîcheur, et UNE promesse de confidentialité centrale —
 * on retient le NOM ACCESSIBLE et le RÔLE du contrôle, JAMAIS la valeur d'un champ.
 */
const TEN_MIN = 10 * 60 * 1000;

afterEach(() => {
  clearLastClick();
});

describe("lastClickStore", () => {
  it("retient le nom accessible et le rôle du dernier contrôle cliqué", () => {
    const btn = document.createElement("button");
    btn.setAttribute("aria-label", "Ajouter un gymnase");
    recordLastClick(btn, 1_000);

    const last = readRecentLastClick(1_000);
    expect(last?.label).toBe("Ajouter un gymnase");
    expect(last?.role).toBe("button");
  });

  it("prend le texte visible quand il n'y a pas d'aria-label (bouton)", () => {
    const btn = document.createElement("button");
    btn.textContent = "Enregistrer";
    recordLastClick(btn, 1_000);

    expect(readRecentLastClick(1_000)?.label).toBe("Enregistrer");
  });

  // ⚠ Fraîcheur : un clic vieux de plus de 10 min ne colle plus au geste. Falsification
  // prévue : retirer le garde de fraîcheur rend la 2ᵉ assertion rouge.
  it("le rend tant qu'il date de moins de 10 minutes, l'ignore au-delà", () => {
    const btn = document.createElement("button");
    btn.setAttribute("aria-label", "Signaler");
    recordLastClick(btn, 1_000);

    expect(readRecentLastClick(1_000 + TEN_MIN - 1)?.label).toBe("Signaler");
    expect(readRecentLastClick(1_000 + TEN_MIN + 1)).toBeNull();
  });

  it("renvoie null sans aucun clic enregistré", () => {
    expect(readRecentLastClick(5_000)).toBeNull();
  });

  it("ne garde que le dernier clic", () => {
    const first = document.createElement("button");
    first.setAttribute("aria-label", "Premier");
    const second = document.createElement("button");
    second.setAttribute("aria-label", "Deuxième");
    recordLastClick(first, 1_000);
    recordLastClick(second, 2_000);

    expect(readRecentLastClick(2_000)?.label).toBe("Deuxième");
  });

  // ⚠ ABUS / PII — promesse centrale : le dernier clic ne retient JAMAIS la valeur d'un
  // champ ni un texte saisi, seulement le nom accessible du contrôle. Falsification :
  // lire `element.value` ou le `textContent` d'un champ rend ce test rouge.
  it("ne retient jamais la valeur d'un input ni un texte saisi", () => {
    const input = document.createElement("input");
    input.setAttribute("aria-label", "Nom d'équipe");
    input.value = "Benjamines SECRETES";
    recordLastClick(input, 1_000);

    const afterInput = readRecentLastClick(1_000);
    expect(afterInput?.label).toBe("Nom d'équipe");
    expect(JSON.stringify(afterInput)).not.toContain("SECRET");

    clearLastClick();

    const textarea = document.createElement("textarea");
    textarea.setAttribute("aria-label", "Message");
    textarea.value = "texte confidentiel saisi";
    textarea.textContent = "texte confidentiel saisi";
    recordLastClick(textarea, 2_000);

    const afterTextarea = readRecentLastClick(2_000);
    expect(afterTextarea?.label).toBe("Message");
    expect(JSON.stringify(afterTextarea)).not.toContain("confidentiel");
  });
});

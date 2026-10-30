/**
 * Chantier F PR2 — mémoire du DERNIER contrôle cliqué, pour situer le geste de
 * l'utilisateur au moment où il ouvre un signalement contextuel. Patron
 * `shared/api/lastIncidentStore` : présentation pure, fenêtre de fraîcheur (10 min),
 * et UNE promesse de confidentialité CENTRALE — on ne retient que le NOM ACCESSIBLE
 * et le RÔLE du contrôle, JAMAIS la valeur d'un champ ni un texte saisi.
 *
 * La valeur d'un `<input>`/`<textarea>`/`<select>` (ou le `textContent` d'un champ)
 * peut porter un nom d'équipe, de coach, un message — de la donnée personnelle. Le
 * libellé, lui, est posé par le développeur : il décrit le contrôle, pas la saisie.
 */
export interface LastClick {
  /** Nom accessible du contrôle (aria-label, libellé associé, ou texte visible). */
  label: string;
  /** Rôle ARIA (explicite ou implicite) du contrôle. */
  role: string;
  at: number;
}

const FRESHNESS_WINDOW_MS = 10 * 60 * 1000;
const MAX_LABEL_LENGTH = 160;

/** Les contrôles de SAISIE : leur valeur / contenu est de la donnée, jamais un libellé. */
function isFieldControl(element: Element): boolean {
  const tag = element.tagName.toLowerCase();
  return "input" === tag || "textarea" === tag || "select" === tag;
}

/** Rôle ARIA explicite, sinon le rôle implicite du tag (suffisant pour situer le geste). */
function roleOf(element: Element): string {
  const explicit = element.getAttribute("role");
  if (explicit) {
    return explicit;
  }
  const tag = element.tagName.toLowerCase();
  switch (tag) {
    case "button":
      return "button";
    case "a":
      return element.hasAttribute("href") ? "link" : "generic";
    case "select":
      return "combobox";
    case "textarea":
      return "textbox";
    case "summary":
      return "button";
    default:
      return tag;
  }
}

/** Libellé du contrôle associé (`<label for>` / `<label>` englobant), jamais sa valeur. */
function associatedLabel(element: Element): string {
  const labels = (element as Partial<HTMLInputElement>).labels;
  if (labels && labels.length > 0) {
    return labels[0]?.textContent?.trim() ?? "";
  }
  return "";
}

/**
 * Nom accessible, par ordre de préférence : aria-label → libellé associé → texte
 * visible (UNIQUEMENT pour un contrôle qui n'est pas un champ) → title. Un champ ne
 * livre JAMAIS son `textContent` ni sa valeur.
 */
function accessibleLabel(element: Element): string {
  const aria = element.getAttribute("aria-label")?.trim();
  if (aria) {
    return aria;
  }
  const associated = associatedLabel(element);
  if (associated) {
    return associated;
  }
  if (!isFieldControl(element)) {
    const text = element.textContent?.trim();
    if (text) {
      return text;
    }
  }
  return element.getAttribute("title")?.trim() ?? "";
}

// D17 — l'anneau des 5 derniers clics (noms accessibles), joint au signalement comme `recentClicks`.
const RECENT_CLICKS_SIZE = 5;

let lastClick: LastClick | null = null;
let recentClicks: LastClick[] = [];

export function recordLastClick(element: Element, at: number = Date.now()): void {
  const click = { label: accessibleLabel(element).slice(0, MAX_LABEL_LENGTH), role: roleOf(element), at };
  lastClick = click;
  // La plus récente en tête, plafonné à 5 — même promesse de confidentialité (jamais une valeur de champ).
  recentClicks = [click, ...recentClicks].slice(0, RECENT_CLICKS_SIZE);
}

/** Le dernier clic s'il date de moins de 10 min, sinon null. */
export function readRecentLastClick(now: number = Date.now()): LastClick | null {
  if (null === lastClick) {
    return null;
  }
  if (now - lastClick.at >= FRESHNESS_WINDOW_MS) {
    return null;
  }
  return lastClick;
}

/**
 * D17 — les noms accessibles des 5 derniers clics frais (< 10 min), le plus RÉCENT en tête. Alimente
 * le champ `recentClicks` du signalement contextuel.
 */
export function readRecentClicks(now: number = Date.now()): string[] {
  return recentClicks.filter((click) => now - click.at < FRESHNESS_WINDOW_MS).map((click) => click.label);
}

export function clearLastClick(): void {
  lastClick = null;
  recentClicks = [];
}

let installed = false;

/**
 * Pose UN écouteur de clic GLOBAL en phase de capture (idempotent) : à chaque clic,
 * on remonte au contrôle actionnable le plus proche et on retient son nom accessible.
 * Appelé une fois au bootstrap (`main.tsx`), à côté du câblage de l'incident serveur.
 */
export function installLastClickCapture(): void {
  if (installed || "undefined" === typeof document) {
    return;
  }
  installed = true;
  document.addEventListener(
    "click",
    (event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      const control = target.closest("button, a, [role], input, select, textarea, label, summary") ?? target;
      recordLastClick(control);
    },
    true,
  );
}

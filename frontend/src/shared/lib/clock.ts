/**
 * « Aujourd'hui », pour tout le front — une seule source, pilotable en dev.
 *
 * Pourquoi : la moitié des écrans du cockpit décide de ce qu'elle affiche à partir de la
 * date du jour (radar, calendrier, semaines offertes à l'ajustement). Tant que cette date
 * vient d'un `new Date()` disséminé, **une situation datée ne peut pas être rejouée** :
 * on obtient la bonne saison mais jamais le bon jour, donc ni démo ni recette d'un cas
 * « à trois semaines des vacances » (P4-16). Ce module est le point de passage unique.
 *
 * ⚠ L'override est **strictement DEV** : la lecture de l'URL est derrière
 * `import.meta.env.DEV`, donc le code de production ne contient aucun chemin capable de
 * décaler l'horloge. Un utilisateur ne peut pas se fabriquer un « aujourd'hui ».
 *
 * Usage en dev : `http://localhost:5173/cockpit?today=2026-12-20`.
 *
 * P4-16 reste ouverte pour le SERVEUR (les cartes radar qu'il calcule lisent l'heure
 * réelle) : décaler le front seul suffit à rejouer un écran, pas un aller-retour complet.
 */

import { useMemo, useSyncExternalStore } from "react";

/** Local Y-m-d (évite le décalage UTC de toISOString). */
export function toISODate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");

  return `${y}-${m}-${d}`;
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * La date existe-t-elle vraiment ? La FORME ne suffit pas (revue #344) : `2026-13-01` et
 * `2026-02-31` passent la regex, et comme ces chaînes trient APRÈS toute date réelle, plus
 * aucune vacance ni semaine ne franchissait les filtres — le radar affichait « Rien à
 * l'horizon. Tout roule. » sur un club qui avait trois périodes à ajuster. On construit
 * donc la date et on vérifie qu'elle se relit à l'identique (le constructeur reporte :
 * le 31 février devient le 3 mars).
 */
function isRealDate(iso: string): boolean {
  if (!ISO_DATE.test(iso)) {
    return false;
  }
  const [y, m, d] = iso.split("-").map(Number);

  return toISODate(new Date(y, m - 1, d)) === iso;
}

let override: string | null = null;
// Le `?today=` de dev PRIME sur la date serveur : celui qui rejoue un écran à la main
// doit gagner sur l'automatisme (et en prod ce drapeau ne peut jamais être vrai — le
// bloc d'amorçage est éliminé du bundle).
let devParamActive = false;

// ── Réactivité (revue captures prod) ──────────────────────────────────────────
// `useApplySimulatedClock` pose l'override SERVEUR dans un effet, APRÈS le premier rendu.
// Sans abonnement, les consommateurs qui lisent `todayISO()`/`todayDate()` en rendu ne se
// recalculent jamais (l'invalidation react-query ne re-rend pas si `/api/me` est identique) :
// en build prod, la bannière « saison suivante » restait à la date réelle jusqu'à un re-rendu
// fortuit (toggle de thème). Un store externe minimal règle ça à la racine : `setTodayOverride`
// notifie, et `useTodayISO`/`useTodayDate` (via `useSyncExternalStore`) re-rendent leurs abonnés.
const listeners = new Set<() => void>();

function subscribeToday(listener: () => void): () => void {
  listeners.add(listener);

  return () => void listeners.delete(listener);
}

/**
 * Fixe le « aujourd'hui » du front, ou le relâche avec `null`.
 *
 * Une valeur invalide est IGNORÉE plutôt que propagée : un `?today=hier` qui traverserait
 * les comparaisons de chaînes ISO donnerait des filtres silencieusement faux (`"hier" > "2026-…"`
 * est vrai lexicographiquement) — un écran qui ment est pire qu'un paramètre sans effet.
 *
 * Ne notifie les abonnés que lorsque la valeur EFFECTIVE change (évite un re-rendu inutile et
 * garde `getSnapshot` stable).
 */
export function setTodayOverride(iso: string | null): void {
  const next = null !== iso && isRealDate(iso) ? iso : null;
  if (next === override) {
    return;
  }
  override = next;
  for (const listener of listeners) {
    listener();
  }
}

/** La date du jour, ISO Y-m-d — l'override de dev s'il est posé, l'horloge sinon. */
export function todayISO(): string {
  return override ?? toISODate(new Date());
}

/**
 * P4-16/P2-4 — l'« aujourd'hui » SERVEUR d'un club démo (`/api/me` → `club.simulatedToday`).
 *
 * Ouvre l'horloge simulée EN PROD, mais jamais à la main de l'utilisateur : la seule
 * source est la réponse authentifiée de `/api/me`, posée côté serveur par le support
 * (commande `app:club:clock`) — un vrai club a `simulatedToday` null et cet appel relâche
 * l'override. Le `?today=` de dev garde la priorité (rejouer à la main doit gagner).
 */
export function applyServerToday(iso: string | null): void {
  if (devParamActive) {
    return;
  }
  setTodayOverride(iso);
}

// Amorçage : lu UNE fois au chargement du module. En prod, `import.meta.env.DEV` est faux
// et le bundler élimine tout ce bloc — l'override n'est alors atteignable que par un appel
// explicite à `setTodayOverride` (ce que seuls les tests font).
if (import.meta.env.DEV && "undefined" !== typeof window) {
  setTodayOverride(new URLSearchParams(window.location.search).get("today"));
  devParamActive = null !== override;
}

/**
 * L'« aujourd'hui » de l'application, en `Date` — même source que {@link todayISO}, donc
 * l'horloge simulée d'un club démo comprise (D-29).
 *
 * ⚠ À préférer à `new Date()` dans tout composant qui DÉCIDE quelque chose sur la date :
 * la bannière de bascule de saison et le sélecteur lisaient l'horloge réelle pendant que le
 * cockpit vivait à la date simulée — le nudge « préparer la saison suivante » apparaissait
 * ou disparaissait au mauvais moment, sans rien pour l'expliquer.
 */
export function todayDate(): Date {
  return new Date(`${todayISO()}T00:00:00`);
}

/**
 * Version RÉACTIVE de {@link todayISO} : un composant qui lit la date du jour EN RENDU doit
 * passer par ce hook (ou {@link useTodayDate}) plutôt que d'appeler `todayISO()` directement,
 * sinon il ne se recale pas quand l'horloge simulée serveur arrive après le premier rendu
 * (`useApplySimulatedClock`). `getSnapshot` = `todayISO` (chaîne stable tant que rien ne bouge).
 */
export function useTodayISO(): string {
  return useSyncExternalStore(subscribeToday, todayISO, todayISO);
}

/** Version RÉACTIVE de {@link todayDate} — mémoïsée sur l'ISO pour une `Date` stable. */
export function useTodayDate(): Date {
  const iso = useTodayISO();

  return useMemo(() => new Date(`${iso}T00:00:00`), [iso]);
}

import { dayLabelLong } from "@/shared/lib/days";
import { excludeTagNames, targetTagNames } from "@/shared/lib/tagTeamIds";

import type { Constraint } from "../api";

/**
 * Ce qu'une contrainte FAIT, dérivé de `family` + `config` — jamais de son `name`, qui est un
 * texte LIBRE saisi par le gestionnaire (périmé, faux, ou copié d'une autre règle). Le panneau
 * de créneau (`SlotDetail`) l'affiche pour que la vérification soit POSSIBLE sans confiance : le
 * fondateur lisait « SM2 au moins 1 seance a Mateo » sur un créneau U11 (une règle DAY/samedi
 * interdit mal nommée) et concluait, à raison, que l'app mentait.
 *
 * 🔴 PRÉSENTATION, PAS RÈGLE MÉTIER (`.claude/rules/frontend.md`) : décrire ce qu'une règle fait
 * est autorisé ; décider SI elle s'applique est interdit au front (c'est `applicableConstraints`,
 * miroir déclaré du backend). Ce module ne fait que traduire une config en français.
 *
 * ⚠ Le vocabulaire est le MÊME que celui de l'auto-nommage du wizard
 * (`features/wizard/steps/ConstraintsStep.tsx` `build()`) — verbes gymnase (préfère/évite/impose),
 * « au moins N », « uniquement », bornes horaires, et le « qui » (équipe / coach / `Groupe X` /
 * « Toutes les équipes »). Les jours viennent du foyer unique `@/shared/lib/days`. Deux
 * vocabulaires divergents seraient une nouvelle duplication de vérité.
 *
 * Le wizard compose « qui · prédicat » (jour court, « pas Sam ») à la SAISIE. Ici on rend la
 * MÊME forme « <cible> · <prédicat> » (jour long, « samedi interdit ») : la cible NOMME l'objet
 * corrigeable (l'équipe / le coach visé) au lieu de le reléguer au nom libre en seconde ligne —
 * c'est ce qui rend la boucle de correction cliquable jusqu'au bon objet.
 *
 * 🔴 Le branchement sur `scope` (CLUB / TEAM / COACH) ci-dessous CHOISIT un LIBELLÉ de cible,
 * il ne DÉCIDE PAS si la règle s'applique (ça, c'est `applicableConstraints`, miroir déclaré).
 * De la présentation, donc — exempté à ce titre dans le garde de redérivation front (`.claude/rules/frontend.md`).
 *
 * PORTÉE : on ne décrit QUE ce qu'on sait rendre FIDÈLEMENT. Cible introuvable (équipe/coach
 * supprimé) → on rend le prédicat SEUL, jamais « ? · … ». Prédicat non couvert (clé inconnue,
 * gymnase introuvable) → `null` : l'appelant retombe alors sur le `name`. Une description
 * approximative serait le même mensonge sous une autre forme.
 */
type VenueNameFn = (venueId: string) => string | undefined;

/** Résolveurs de NOM pour la cible (`scopeTargetId`) — venant des lookups du planning. */
export interface ConstraintTargetLookups {
  venueName: VenueNameFn;
  teamName: (teamId: string) => string | undefined;
  coachName: (coachId: string) => string | undefined;
  /** Libellé AFFICHÉ d'un tag (« Adulte (+ de 18) » pour `ADULTE`). Optionnel : absent, on
   *  retombe sur le nom brut — jamais un « ? ». Injecté comme `venueName`, donc ce module ne
   *  connaît toujours aucune table de libellés : il reçoit le résolveur, il ne le fabrique pas. */
  tagLabel?: (tagName: string) => string;
}

const capitalize = (s: string): string => ("" === s ? s : s.charAt(0).toUpperCase() + s.slice(1));

const asTime = (v: unknown): string | null => ("string" === typeof v && "" !== v ? v : null);

const asVenueId = (v: unknown): string | null => ("string" === typeof v && "" !== v ? v : null);

/** Jours ISO valides (1-7), triés — on ignore tout ce qui n'est pas un jour de semaine. */
const asDayNums = (v: unknown): number[] =>
  Array.isArray(v) ? [...new Set(v.map(Number).filter((n) => Number.isInteger(n) && n >= 1 && n <= 7))].sort((a, b) => a - b) : [];

const daysPhrase = (nums: number[]): string => nums.map(dayLabelLong).filter((label) => "" !== label).join(", ");

/**
 * Une règle décomposée : le VERBE et sa VALEUR, séparés — « pas après » / « 19:00 ».
 *
 * ⚠ **Pourquoi cette forme existe à côté de la phrase.** Le panneau de créneau du planning lit
 * une phrase ; le tableau des contraintes du wizard (P4-107) a besoin des deux moitiés dans deux
 * colonnes. Les mots sont les MÊMES et ne vivent qu'ICI : la phrase est désormais COMPOSÉE à
 * partir de ces parties, elle ne les redit pas. Deux vocabulaires divergents seraient exactement
 * la duplication de vérité que ce module a été écrit pour éviter.
 *
 * `verbFirst` porte le seul écart de forme du français : on dit « impose Matéo » (verbe d'abord)
 * mais « lundi interdit » (verbe après). Sans lui, composer la phrase inventerait un ordre.
 */
export interface ConstraintPredicatePart {
  verb: string;
  value: string;
  verbFirst: boolean;
}

/**
 * Les règles d'une contrainte, décomposées — vide quand rien n'est descriptible fidèlement
 * (même portée que `describeConstraint` : une clé inconnue ou un gymnase introuvable ne produit
 * RIEN plutôt qu'une approximation).
 *
 * ⚠ Une contrainte TIME en porte jusqu'à TROIS (« pas après », « pas avant », « fini avant ») :
 * l'appelant qui n'en rend qu'une MENT par omission.
 */
export function constraintPredicateParts(constraint: Constraint, venueName: VenueNameFn): ConstraintPredicatePart[] {
  const cfg = constraint.config ?? {};
  switch (constraint.family) {
    case "TIME":
      return timeParts(cfg);
    case "DAY":
      return dayParts(cfg);
    case "FACILITY":
      return facilityParts(cfg, venueName);
    case "COACH_AVAILABILITY":
      return coachParts(cfg);
    default:
      return [];
  }
}

/** Le « qui » d'une contrainte — exposé pour la colonne « Cible » du tableau du wizard. */
export function constraintTarget(constraint: Constraint, lookups: ConstraintTargetLookups): string | null {
  return resolveTarget(constraint, lookups);
}

/** Une partie rendue en français : « impose Matéo », « lundi, mardi interdits ». */
const phrase = (part: ConstraintPredicatePart): string => (part.verbFirst ? `${part.verb} ${part.value}` : `${part.value} ${part.verb}`);

export function describeConstraint(constraint: Constraint, lookups: ConstraintTargetLookups): string | null {
  const predicate = buildPredicate(constraint, lookups.venueName);
  if (null === predicate) {
    return null;
  }
  const target = resolveTarget(constraint, lookups);

  // Cible connue → « <cible> · <prédicat> » (prédicat en clair, minuscule, comme le wizard).
  // Cible introuvable (équipe/coach supprimé) → prédicat SEUL, capitalisé — jamais « ? · … ».
  return null === target ? capitalize(predicate) : `${target} · ${predicate}`;
}

/**
 * Le « qui » d'une contrainte, vocabulaire du wizard (`ConstraintsStep.build()`) : équipe → son
 * nom ; coach → son nom ; CLUB ciblant un/des tag(s) → `Groupe <A + B sauf C>` ; CLUB nu →
 * « Toutes les équipes ». Équipe/coach dont le nom ne se résout pas (cible supprimée) → `null` :
 * le prédicat ira seul.
 *
 * ⚠ Les tags sont rendus avec le libellé AFFICHÉ quand l'appelant fournit `tagLabel` (même
 * patron d'INJECTION que `venueName` — ce module ne fabrique aucune table de libellés). Sans
 * résolveur : nom brut, dégradation douce. Sans cette injection, l'écran mentait à deux voix —
 * le panneau de créneau décrivait « Groupe ADULTE » pendant que le NOM de la même contrainte,
 * juste en dessous, disait « Groupe Adulte (+ de 18) » (le wizard nomme en libellés depuis le
 * lot tags).
 */
function resolveTarget(constraint: Constraint, lookups: ConstraintTargetLookups): string | null {
  if ("COACH" === constraint.scope) {
    return null !== constraint.scopeTargetId ? (lookups.coachName(constraint.scopeTargetId) ?? null) : null;
  }
  if ("TEAM" === constraint.scope) {
    return null !== constraint.scopeTargetId ? (lookups.teamName(constraint.scopeTargetId) ?? null) : null;
  }
  // CLUB : un groupe (un ou plusieurs tags, avec exclusions) OU tout le club.
  const targets = targetTagNames(constraint.config);
  const excludes = excludeTagNames(constraint.config);
  if (0 === targets.length && 0 === excludes.length) {
    return "Toutes les équipes";
  }
  const label = (name: string): string => lookups.tagLabel?.(name) ?? name;
  const base = targets.length > 0 ? targets.map(label).join(" + ") : "toutes les équipes";

  return `Groupe ${base}${excludes.length > 0 ? ` sauf ${excludes.map(label).join(", ")}` : ""}`;
}

function buildPredicate(constraint: Constraint, venueName: VenueNameFn): string | null {
  // La phrase est COMPOSÉE des parties — un seul endroit porte les mots (cf.
  // `ConstraintPredicatePart`). Plusieurs parties (TIME) se joignent par une virgule, dans
  // l'ordre du wizard : « pas après » d'abord (la borne la plus lue), puis « pas avant »,
  // puis « fini avant ».
  const parts = constraintPredicateParts(constraint, venueName);

  return 0 === parts.length ? null : parts.map(phrase).join(", ");
}

const timeVerb = (verb: string, value: string | null): ConstraintPredicatePart | null => (null === value ? null : { verb, value, verbFirst: true });

function timeParts(cfg: Record<string, unknown>): ConstraintPredicatePart[] {
  return [
    timeVerb("pas après", asTime(cfg.maxStartTime)),
    timeVerb("pas avant", asTime(cfg.minStartTime)),
    // maxEndTime = fin de séance, toujours dure côté engine (le chemin soft ne lit que
    // min/maxStartTime).
    timeVerb("fini avant", asTime(cfg.maxEndTime)),
  ].filter((p): p is ConstraintPredicatePart => null !== p);
}

function dayParts(cfg: Record<string, unknown>): ConstraintPredicatePart[] {
  const forbidden = asDayNums(cfg.forbiddenDays);
  if (forbidden.length > 0) {
    // Verbe APRÈS la valeur : « lundi, mardi interdits ». C'est le seul cas de la famille.
    return [{ verb: `interdit${forbidden.length > 1 ? "s" : ""}`, value: daysPhrase(forbidden), verbFirst: false }];
  }
  // whitelist (`allowedDays`) : seuls ces jours sont permis.
  const allowed = asDayNums(cfg.allowedDays);
  if (allowed.length > 0) {
    return [{ verb: "uniquement", value: daysPhrase(allowed), verbFirst: true }];
  }
  // `forcedDays` = « au moins une séance l'un de ces jours » (agrégat sur l'union côté engine),
  // le mode « au moins une » du wizard — distinct de `allowedDays`.
  const forced = asDayNums(cfg.forcedDays);

  return forced.length > 0 ? [{ verb: "au moins une séance", value: daysPhrase(forced), verbFirst: true }] : [];
}

function facilityParts(cfg: Record<string, unknown>, venueName: VenueNameFn): ConstraintPredicatePart[] {
  const named = (id: string | null, verb: string): ConstraintPredicatePart[] => {
    if (null === id) {
      return [];
    }
    const name = venueName(id);

    // Gymnase introuvable (désactivé, données antérieures) → rien : jamais « préfère undefined ».
    return undefined === name ? [] : [{ verb, value: name, verbFirst: true }];
  };

  const forced = asVenueId(cfg.forcedVenueId);
  if (null !== forced) {
    return named(forced, "impose");
  }
  const minAt = asVenueId(cfg.minAtVenueId);
  if (null !== minAt) {
    const count = Number.isInteger(cfg.minAtVenueCount) && (cfg.minAtVenueCount as number) > 0 ? (cfg.minAtVenueCount as number) : 1;

    return named(minAt, `au moins ${count} séance${count > 1 ? "s" : ""} à`);
  }
  const forbidden = asVenueId(cfg.forbiddenVenueId);
  if (null !== forbidden) {
    return named(forbidden, "évite");
  }
  const preferred = asVenueId(cfg.preferredVenueId);

  return null !== preferred ? named(preferred, "préfère") : [];
}

function coachParts(cfg: Record<string, unknown>): ConstraintPredicatePart[] {
  const from = asTime(cfg.fromTime);
  const until = asTime(cfg.untilTime);
  const window = from && until ? ` de ${from} à ${until}` : from ? ` à partir de ${from}` : until ? ` jusqu'à ${until}` : "";

  const available = asDayNums(cfg.availableDays);
  if (available.length > 0) {
    return [{ verb: "disponible uniquement", value: `${daysPhrase(available)}${window}`, verbFirst: true }];
  }
  const unavailable = asDayNums(cfg.unavailableDays);

  return unavailable.length > 0 ? [{ verb: "indisponible", value: `${daysPhrase(unavailable)}${window}`, verbFirst: true }] : [];
}

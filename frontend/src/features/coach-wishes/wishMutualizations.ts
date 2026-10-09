import type { PublicMutualizationSubmission, PublicTeamLink, PublicWishContext } from "./publicApi";

/**
 * État éditable d'une mutualisation (une par équipe, POUR LA PÉRIODE — pas par semaine,
 * contrairement aux doléances). Le coach choisit des équipes partenaires et un nombre de
 * séances à partager. Informatif : aucun effet solveur, le gestionnaire arbitre.
 */
export interface MutualizationState {
  partnerTeamIds: Set<string>;
  sharedSlots: number;
}

/** Valeur par défaut d'une mutualisation vide (aucun partenaire, une séance pressentie). */
const DEFAULT_SHARED_SLOTS = 1;

/** Snapshot initial (mutualisations déjà déclarées) — une entrée par équipe DU COACH. */
export function buildInitialMutualizations(context: PublicWishContext): Map<string, MutualizationState> {
  const map = new Map<string, MutualizationState>();
  for (const team of context.teams) {
    const existing = context.mutualizations.find((m) => m.teamId === team.id);
    map.set(team.id, {
      partnerTeamIds: new Set(existing?.partnerTeamIds ?? []),
      sharedSlots: existing?.sharedSlots ?? DEFAULT_SHARED_SLOTS,
    });
  }
  return map;
}

/** Copie profonde (les `Set` sont clonés). */
export function cloneMutualizations(source: Map<string, MutualizationState>): Map<string, MutualizationState> {
  return new Map([...source].map(([k, v]) => [k, { partnerTeamIds: new Set(v.partnerTeamIds), sharedSlots: v.sharedSlots }]));
}

/** Une mutualisation est MODIFIÉE si elle diffère de l'état initial — seules celles-là partent. */
export function isMutualizationDirty(a: MutualizationState | undefined, b: MutualizationState | undefined): boolean {
  if (undefined === a || undefined === b) {
    return false;
  }
  // Le nombre de séances ne compte QUE s'il y a (ou avait) des partenaires : une équipe à zéro
  // partenaire reste « non mutualisée » quel que soit le compteur (qui ne part pas).
  if (a.partnerTeamIds.size !== b.partnerTeamIds.size) {
    return true;
  }
  for (const id of a.partnerTeamIds) {
    if (!b.partnerTeamIds.has(id)) {
      return true;
    }
  }
  // Même ensemble de partenaires : seule une variation de séances (quand il y a des partenaires)
  // est une modification réelle.
  return a.partnerTeamIds.size > 0 && a.sharedSlots !== b.sharedSlots;
}

/** Sérialise une mutualisation modifiée en payload (partenaires triés). */
export function toMutualizationSubmission(teamId: string, s: MutualizationState): PublicMutualizationSubmission {
  return {
    teamId,
    partnerTeamIds: [...s.partnerTeamIds].sort(),
    sharedSlots: s.sharedSlots,
  };
}

/** Un partenaire proposé : l'équipe + un drapeau « passerelle » (couple déclaré avec l'équipe courante). */
export interface PartnerOption {
  id: string;
  name: string;
  isBridge: boolean;
}

/**
 * Les partenaires proposables pour une équipe : les équipes de la campagne SAUF elle-même,
 * passerelles en TÊTE (couple TeamLink dont une extrémité est l'équipe courante). Ordre stable
 * (passerelles d'abord, puis l'ordre du serveur).
 */
export function partnerOptionsFor(teamId: string, partnerTeams: { id: string; name: string }[], teamLinks: PublicTeamLink[]): PartnerOption[] {
  const bridges = new Set<string>();
  for (const link of teamLinks) {
    if (link.teamAId === teamId) {
      bridges.add(link.teamBId);
    } else if (link.teamBId === teamId) {
      bridges.add(link.teamAId);
    }
  }
  const options = partnerTeams
    .filter((t) => t.id !== teamId)
    .map((t) => ({ id: t.id, name: t.name, isBridge: bridges.has(t.id) }));
  // Passerelles d'abord ; au sein de chaque groupe, l'ordre du serveur est préservé (tri stable).
  return [...options].sort((a, b) => Number(b.isBridge) - Number(a.isBridge));
}

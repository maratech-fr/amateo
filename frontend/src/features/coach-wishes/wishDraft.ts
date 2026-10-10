import { cloneMutualizations, type MutualizationState } from "./wishMutualizations";
import { cloneSections, type SectionState } from "./wishSections";

/**
 * Filet LOCAL (sessionStorage) du brouillon de doléances — jamais serveur. La décision
 * « pas de sauvegarde partielle » reste intacte : sessionStorage meurt avec l'onglet.
 * Restauré au montage, PURGÉ au succès de l'envoi. Clé par token.
 */
interface SerializedDraft {
  sections: Record<string, { slotsWanted: number; days: number[]; wishedDays?: number[]; comment: string; keepSeasonSlots?: boolean }>;
  /** Mutualisations par équipe (optionnel — rétro-compatible avec un brouillon C2/C3). */
  mutualizations?: Record<string, { partnerTeamIds: string[]; sharedSlots: number }>;
  stepIndex: number;
}

export interface WishDraft {
  sections: Map<string, SectionState>;
  mutualizations: Map<string, MutualizationState>;
  stepIndex: number;
}

const key = (token: string): string => `amateo:wish-draft:${token}`;

export function loadDraft(token: string): WishDraft | null {
  try {
    const raw = sessionStorage.getItem(key(token));
    if (null === raw) {
      return null;
    }
    const parsed = JSON.parse(raw) as SerializedDraft;
    const sections = new Map<string, SectionState>();
    for (const [k, v] of Object.entries(parsed.sections)) {
      sections.set(k, { slotsWanted: v.slotsWanted, days: new Set(v.days), wishedDays: new Set(v.wishedDays ?? []), comment: v.comment, keepSeasonSlots: v.keepSeasonSlots ?? false });
    }
    const mutualizations = new Map<string, MutualizationState>();
    for (const [k, v] of Object.entries(parsed.mutualizations ?? {})) {
      mutualizations.set(k, { partnerTeamIds: new Set(v.partnerTeamIds), sharedSlots: v.sharedSlots });
    }
    return { sections, mutualizations, stepIndex: parsed.stepIndex };
  } catch {
    return null;
  }
}

export function saveDraft(
  token: string,
  sections: Map<string, SectionState>,
  mutualizations: Map<string, MutualizationState>,
  stepIndex: number,
): void {
  try {
    const snapshot = cloneSections(sections);
    const serialized: SerializedDraft = { sections: {}, mutualizations: {}, stepIndex };
    for (const [k, v] of snapshot) {
      serialized.sections[k] = { slotsWanted: v.slotsWanted, days: [...v.days], wishedDays: [...v.wishedDays], comment: v.comment, keepSeasonSlots: v.keepSeasonSlots };
    }
    for (const [k, v] of cloneMutualizations(mutualizations)) {
      (serialized.mutualizations ??= {})[k] = { partnerTeamIds: [...v.partnerTeamIds], sharedSlots: v.sharedSlots };
    }
    sessionStorage.setItem(key(token), JSON.stringify(serialized));
  } catch {
    // sessionStorage indisponible (mode privé strict) : le filet est optionnel.
  }
}

export function clearDraft(token: string): void {
  try {
    sessionStorage.removeItem(key(token));
  } catch {
    // Idem : best-effort.
  }
}

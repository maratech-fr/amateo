import { Check, Hourglass, type LucideIcon, Send } from "lucide-react";

import type { Conflict, ConflictResolutionStatus } from "../api";

/**
 * P4-207 — la MAISON UNIQUE du traitement d'un conflit : la table de PRÉSENTATION des
 * trois statuts (libellé + variante de pastille + glyphe), et les deux dérivations
 * « ce conflit est-il à traiter ? » / « combien à traiter ? ». Une seule maison,
 * consommée par le layout (badge nav), le rail (« Conflits (n) »), le radar (badge),
 * la page (compteurs, tri) et `countByFamily` : partout, un COMPTEUR ne compte que
 * l'À TRAITER, jamais les conflits déjà annotés.
 *
 * PRÉSENTATION seule (🔴 `.claude/rules/frontend.md`) : la table est un `Record` sur
 * l'enum, JAMAIS un `switch` décideur — elle choisit un libellé/glyphe, elle ne
 * décide d'aucun comportement métier (le backend reste souverain sur ce qui est
 * permis, 403 côté serveur).
 */

export const RESOLUTION_STATUSES: ConflictResolutionStatus[] = ["DEROGATION_REQUESTED", "RESOLVED_INTERNALLY", "NO_SOLUTION_YET"];

/** Un glyphe DISTINCT par statut (jamais la couleur seule) + un libellé FR + la variante `StatusPill`. */
export const RESOLUTION_LABEL: Record<ConflictResolutionStatus, { label: string; variant: "warning" | "accent" | "neutral"; icon: LucideIcon }> = {
  DEROGATION_REQUESTED: { label: "Dérogation demandée", variant: "warning", icon: Send },
  RESOLVED_INTERNALLY: { label: "Réglé en interne", variant: "accent", icon: Check },
  NO_SOLUTION_YET: { label: "Sans solution pour l'instant", variant: "neutral", icon: Hourglass },
};

/**
 * Un conflit est « à traiter » quand il n'a AUCUNE résolution. Le backend sert
 * toujours le champ (`null` ou objet) ; on tolère aussi l'absence (`undefined`) pour
 * ne jamais compter à tort un conflit sans donnée servie comme « traité ».
 */
export const isOpenConflict = (conflict: Conflict): boolean => null == conflict.resolution;

/** Le nombre de conflits À TRAITER dans un lot (les données absentes ⇒ 0, jamais « · 0 » fabriqué). */
export const openConflictCount = (conflicts: Conflict[] | undefined): number => (conflicts ?? []).filter(isOpenConflict).length;

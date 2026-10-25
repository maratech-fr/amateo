import { useMutation, useQueryClient } from "@tanstack/react-query";

import { api } from "@/shared/api/client";
import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

import { invalidateEverywhere } from "../queries";

/**
 * Chantier G PR3 — les deux hooks de données de l'import du parc. Le front n'invente aucune règle :
 * `analyze` est un dry-run (le serveur rend les verdicts ligne par ligne, qu'on AFFICHE), `import`
 * bascule en écriture les lignes cochées. Le toast d'erreur vit AU NIVEAU HOOK (FRT-38 /
 * `mutateOnErrorToastGuard`) : la modale ne passe JAMAIS d'`onError` à `mutate()`.
 */

/** Statut servi par l'analyzer pour une ligne (`App\Service\ParcImport\ParcImportAnalyzer`). */
export type ParcLineStatus = "apparie" | "suggere" | "aCreer" | "decoche" | "alerte";

/** Une ligne d'aperçu — tout vient du serveur, affiché verbatim (`libelle`, `raison`). */
export interface ParcAnalysisLine {
  index: number;
  libelle: string;
  statut: ParcLineStatus;
  coche: boolean;
  cibleId: string | null;
  raison: string | null;
  /** Équipes : une mise à jour de séances PROPOSÉE (pas appliquée). */
  miseAJour?: { champ: "seances"; de: number; a: number };
  /** Mutualisations : le libellé du bloc DÉRIVÉ par le serveur (ex. « CEC »), affiché et corrigeable (D8). */
  groupe?: string | null;
}

/** Le récap chiffré d'une section de l'aperçu. */
export interface ParcSectionRecap {
  total: number;
  apparie: number;
  suggere: number;
  aCreer: number;
  decoche: number;
  alerte: number;
}

/** La fiabilité globale de l'aperçu (D10) — `bandeau` vrai affiche l'avertissement, jamais bloquant. */
export interface ParcAnalysisReliability {
  taux: number;
  anomalies: number;
  bandeau: boolean;
}

/** Une alerte transverse (D9) — `message` est une DONNÉE serveur, affichée verbatim. */
export interface ParcAnalysisAlert {
  type: string;
  message: string;
}

/** La réponse du dry-run `POST /api/clubs/{id}/parc-import/analyze`. */
export interface ParcAnalysis {
  format: string;
  fiabilite: ParcAnalysisReliability;
  recap: Record<string, ParcSectionRecap>;
  sections: Record<string, ParcAnalysisLine[]>;
  alertes: ParcAnalysisAlert[];
  /**
   * Le parc NORMALISÉ relu côté serveur par `ParcPayloadValidator::parse()` (G2 D4) : le front
   * l'amorce pour les brouillons ET le reposte à l'import, au lieu de re-parser le texte collé — qui
   * peut n'être lisible QUE par la passe de récupération serveur (guillemets typographiques, bloc
   * ```json). Forme loose (parc-v2, décidée côté serveur), narrowée par la modale.
   */
  payload?: unknown;
}

/** Une décision ligne par ligne, envoyée à l'import (contrat PR2). */
export interface ParcImportDecision {
  section: string;
  index: number;
  coche: boolean;
  cibleId?: string | null;
  corrections?: Record<string, unknown>;
}

/** Le récap servi par l'import réel (contrat PR2, provisoire). */
export interface ParcImportResult {
  message?: string;
  recap?: Record<string, { crees: number; apparies: number; misAJour: number; ignores: number }>;
}

/** La sélection envoyée à l'import : le parc collé + les décisions. */
export interface ParcImportSelection {
  payload: unknown;
  decisions: ParcImportDecision[];
}

/**
 * Dry-run : poste le texte BRUT rendu par l'assistant (`application/json`, lu côté serveur via
 * `$request->getContent()`, pas de désérialisation API Platform). Aucune écriture, aucune
 * invalidation de cache. La saison de travail voyage dans l'en-tête `X-Season-Id` (client).
 */
export function useAnalyzeParc(clubId: string) {
  return useMutation({
    mutationFn: (text: string) =>
      api.post(`clubs/${clubId}/parc-import/analyze`, { body: text, headers: { "Content-Type": "application/json" } }).json<ParcAnalysis>(),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/**
 * Import réel des lignes cochées. Au succès on invalide équipes/gymnases/coachs PARTOUT (D-25) :
 * l'import fait naître/rattacher ces entités, les écrans voisins doivent les voir sans attendre
 * le `staleTime`.
 */
export function useImportParc(clubId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (selection: ParcImportSelection) => api.post(`clubs/${clubId}/parc-import`, { json: selection }).json<ParcImportResult>(),
    onSuccess: () => {
      void invalidateEverywhere(queryClient, "teams");
      void invalidateEverywhere(queryClient, "venues");
      void invalidateEverywhere(queryClient, "coaches");
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

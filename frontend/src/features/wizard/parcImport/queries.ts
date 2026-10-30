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

/**
 * Statut servi par l'analyzer pour une ligne (`App\Service\ParcImport\ParcImportAnalyzer`). PR1 (D5) :
 * `ambigu` — ≥ 2 candidats dans le seuil, décoché, cibleId null, PORTE ses `candidats` à trancher.
 */
export type ParcLineStatus = "apparie" | "suggere" | "aCreer" | "ambigu" | "decoche" | "alerte";

/** Une ligne d'aperçu — tout vient du serveur, affiché verbatim (`libelle`, `raison`). */
export interface ParcAnalysisLine {
  index: number;
  libelle: string;
  statut: ParcLineStatus;
  coche: boolean;
  cibleId: string | null;
  raison: string | null;
  /** PR1 (D5) : les candidats (`{id, nom}`) d'un appariement suggéré/ambigu — alimentent le groupe
   *  « Candidats possibles » du sélecteur de la ligne. Absent/vide pour une ligne « à créer ». */
  candidats?: Array<{ id: string; nom: string }>;
  /**
   * Équipes (retours fondateur 2026-10-26) : le nombre de séances LU dans le fichier (nombre de cases
   * dérivé), servi sur CHAQUE ligne équipe — amorce le champ « Séances » éditable (ce qui SERA importé).
   */
  seances?: number;
  /**
   * Équipe APPARIÉE (retours fondateur 2026-10-26) : les séances ACTUELLES de l'équipe visée —
   * l'indication « actuellement N » et la base de comparaison du champ (valeur = actuelle → pas de
   * `corrections.seances` ; valeur ≠ actuelle → la correction part).
   */
  seancesActuelles?: number;
  /** Mutualisations : le libellé du bloc DÉRIVÉ par le serveur (ex. « CEC »), affiché et corrigeable (D8). */
  groupe?: string | null;
  /** Mutualisations (P4-363) : le gymnase RÉSOLU du nom de la case (null si à créer) — amorce le sélecteur de gymnase de la réservation de groupe, distinct du `cibleId` (qui désigne le bloc apparié). */
  gymnaseId?: string | null;
  /**
   * Réservations / mutualisations (refonte PR1) : la POSITION du créneau de SA case DÉRIVÉE
   * (gymnase · jour · heure · durée, + le gymnase résolu). Le front place la case de la grille et
   * amorce l'éditeur EXCLUSIVEMENT d'après elle — JAMAIS `payload.cases[index]`, car l'index d'une
   * ligne de ces deux sections est son rang dans la liste dérivée, décalé du rang des cases du parc
   * (une case à 2 équipes sur terrains séparés donne 2 réservations et décale tout le reste).
   */
  position?: { gymnase: string; jour: number; debut: string; duree: number; gymnaseId?: string | null };
  /**
   * Équipe (refonte PR2, D9) : les SUGGESTIONS de la règle FBI unique
   * (`App\Service\Basketball\TeamCreationSuggester`), servies sur TOUTE ligne équipe quel que soit
   * son statut (apparié / suggéré / ambigu / à créer) — le gestionnaire peut « Créer » depuis
   * n'importe quel statut. Elles pré-remplissent les Select Rang + Genre de la ligne et la case
   * Entente de l'étape « Ententes » dès que « Créer » est choisi sur cette ligne. `suggestedGender`
   * vaut `null` quand aucun motif clair ne le donne (« plus MIXTE », jamais deviné) — le genre est
   * alors à trancher (obligatoire).
   */
  suggestedTierId?: number;
  suggestedGender?: "M" | "F" | null;
  suggestedCtc?: boolean;
  /**
   * PR A (incident ALAP, D1/D13) — séance commune / réservation en alerte « équipe ignorée ou
   * introuvable » : le code MACHINE qui accompagne la `raison` humaine. Le front le lit pour BLOQUER
   * l'import tant que la ligne est gardée (jamais une re-dérivation depuis la phrase FR serveur).
   */
  alerteCode?: string;
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
  /** Création EXPLICITE (3a) : « Créer « X » » sur une ligne AMBIGUË — lève le refus d'ambiguïté serveur. */
  creer?: boolean;
  cibleId?: string | null;
  /** Mutualisations (P4-363) : le gymnase CHOISI de la réservation de groupe (sélecteur de la ligne), distinct du `cibleId` (bloc apparié). Absent → le serveur résout par le nom de la case. */
  venueId?: string | null;
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
 * L'entrée de l'analyse : le texte BRUT collé (première analyse), OU l'enveloppe `{payload, decisions}`
 * de la REVALIDATION (PR1) — le serveur rejoue l'analyse en appliquant les corrections de l'aperçu.
 */
export type ParcAnalyzeInput = string | { payload: unknown; decisions: ParcImportDecision[] };

/**
 * Dry-run : poste soit le texte BRUT rendu par l'assistant (première analyse), soit l'enveloppe
 * `{payload, decisions}` de la revalidation (`application/json`, lu côté serveur via
 * `$request->getContent()`, pas de désérialisation API Platform). Aucune écriture, aucune
 * invalidation de cache. La saison de travail voyage dans l'en-tête `X-Season-Id` (client).
 */
export function useAnalyzeParc(clubId: string) {
  return useMutation({
    mutationFn: (input: ParcAnalyzeInput) =>
      api
        .post(
          `clubs/${clubId}/parc-import/analyze`,
          "string" === typeof input
            ? { body: input, headers: { "Content-Type": "application/json" } }
            : { json: input },
        )
        .json<ParcAnalysis>(),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

/**
 * Import réel des lignes cochées. Au succès on invalide équipes/gymnases/coachs PARTOUT (D-25) :
 * l'import fait naître/rattacher ces entités, les écrans voisins doivent les voir sans attendre
 * le `staleTime`.
 *
 * PR1 — l'erreur d'import est surfacée DANS la modale (`NoticeBanner role="alert"`, qui lit le state
 * `error`/`isError` du hook), PAS en toast : le `onError` de niveau HOOK existe pour DÉSARMER le filet
 * global `MutationCache.onError` (qui, sinon, doublerait la bannière d'un toast) — il ne toaste rien.
 * Le toast d'erreur reste la conduite de l'ANALYSE (`useAnalyzeParc`).
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
    onError: (error) => void error,
  });
}

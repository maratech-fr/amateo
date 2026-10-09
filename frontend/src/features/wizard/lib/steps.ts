export type WizardStepId = "teams" | "venues" | "coaches" | "constraints" | "recap" | "generate";

export interface StepDef {
  id: WizardStepId;
  label: string;
}

/** Wizard flow. Teams first; ranking (tierOrder) lives inside the Teams step. */
export const WIZARD_STEPS: StepDef[] = [
  { id: "teams", label: "Équipes" },
  { id: "venues", label: "Gymnases" },
  { id: "coaches", label: "Coachs" },
  { id: "constraints", label: "Contraintes" },
  { id: "recap", label: "Récapitulatif" },
  { id: "generate", label: "Génération" },
];

/**
 * D2 (lot 2) — une alerte TYPÉE du récap (créneau partagé). `warning` (orange) = une place se
 * PERD (réservation partielle) ; `muted` (gris) = information neutre (le système choisira). Ne
 * concerne QUE le verdict du récap : la fusion des `sharedSlotNotices` dans le verdict en fait la
 * source unique, lue par le rail, l'accordéon du récap et le gate. `place`/`message` restent du
 * TEXTE (pas de JSX) pour que ce module reste pur ; le récap compose leur rendu.
 */
export interface RecapNotice {
  key: string;
  tone: "warning" | "muted";
  place: string;
  message: string;
}

export interface StepValidation {
  errors: string[];
  warnings: string[];
  /** True while the data behind the verdict is still loading. Consumers that
   * gate an action (e.g. the generate launch) must treat pending as blocked so
   * the gate is fail-closed during the load window, not briefly open. */
  pending?: boolean;
  /** Récap SEULEMENT (D2) : les créneaux partagés à signaler, typés (ton + texte). Absent
   *  ailleurs — les autres étapes n'en produisent pas. */
  notices?: RecapNotice[];
}

export const okValidation = (): StepValidation => ({ errors: [], warnings: [] });

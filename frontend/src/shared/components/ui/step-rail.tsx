import { Check, Lock } from "lucide-react";

import { cn } from "@/shared/lib/utils";

/** Une étape du rail. Les états VIVENT dans l'entrée (pas de map parallèle : un `id`
 *  fantôme est ainsi impossible). Le numéro affiché est dérivé de la position, jamais porté. */
export interface StepRailStep<Id extends string = string> {
  id: Id;
  label: string;
  /** Étape complète → pastille ✓ + nom accessible enrichi. */
  done?: boolean;
  /** Étape hors d'atteinte → bouton `disabled` + cadenas. */
  locked?: boolean;
}

interface StepRailProps<Id extends string> {
  steps: StepRailStep<Id>[];
  currentId: Id;
  onSelect: (id: Id) => void;
}

/**
 * **Le rail d'étapes commun** (RMM-2, extrait à RENDU IDENTIQUE de `WizardLayout`). Une colonne
 * de boutons numérotés : l'étape courante surlignée, les terminées cochées, les verrouillées
 * barrées et inertes. Le `<nav className="shrink-0 md:w-44">` complet est ICI — c'est la primitive,
 * pas un fragment que l'appelant emballe.
 *
 * ⚠ **Présentation PURE.** Les états arrivent CALCULÉS dans `steps` : le rail ne sait rien d'une
 * porte de validation, d'un mode guidé, d'un verrou métier ou d'un voile de navigation. Il ne lit
 * aucun store et n'appelle aucun hook applicatif — `onSelect` REMONTE le clic, l'appelant décide
 * de ses effets (le wizard y arme son voile ; un autre consommateur peut n'en avoir aucun). C'est
 * le pendant du contrat de `system-scene` : « la forme et rien d'autre ».
 *
 * ⚠ Le ✓ (done) et le 🔒 (locked) SONT la sémantique portée : cette cohérence visuelle chez tous
 * les consommateurs est la raison d'être de l'extraction, d'où des icônes importées ici et non des
 * slots. Et **pas de prop `className`** — l'échappatoire ferait la dérive par consommateur (même
 * arbitrage que `modal.tsx` : le seul geste offert est le tableau d'étapes).
 */
export function StepRail<Id extends string>({ steps, currentId, onSelect }: StepRailProps<Id>) {
  return (
    <nav className="shrink-0 md:w-44">
      <ol className="flex flex-col gap-1">
        {steps.map((step, i) => {
          const done = true === step.done;
          const locked = true === step.locked;
          return (
            <li key={step.id}>
              <button
                type="button"
                disabled={locked}
                onClick={() => onSelect(step.id)}
                aria-current={step.id === currentId ? "step" : undefined}
                // WCAG 2.5.3 : le nom accessible CONTIENT le texte visible, l'état s'ajoute.
                aria-label={done ? `${step.label} — étape terminée` : undefined}
                className={cn(
                  "flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition",
                  step.id === currentId ? "bg-muted font-medium text-foreground" : "text-muted-foreground hover:bg-muted/60",
                  locked ? "cursor-not-allowed opacity-40 hover:bg-transparent" : "",
                )}
              >
                {/* La pastille dit l'ÉTAT : ✓ = étape complète, numéro (dérivé de la position) sinon. */}
                <span
                  className={cn(
                    "flex size-5 shrink-0 items-center justify-center rounded-full border text-xs",
                    done ? "border-success text-success" : "border-border",
                  )}
                >
                  {done ? <Check className="size-3" aria-hidden="true" /> : i + 1}
                </span>
                <span className="flex-1">{step.label}</span>
                {locked ? <Lock className="size-3" /> : null}
              </button>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

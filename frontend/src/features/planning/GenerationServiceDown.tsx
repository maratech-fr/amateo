import { Rocket } from "lucide-react";
import { useState } from "react";

import { FeedbackDialog } from "@/features/feedback/FeedbackDialog";
import { readRecentIncidentRequestId } from "@/shared/api/lastIncidentStore";
import { Button } from "@/shared/components/ui/button";
import { DevIncidentDetails } from "@/shared/components/ui/dev-incident-details";

import { GenerationScene } from "./GenerationScene";

interface GenerationServiceDownProps {
  /** Relance la génération — c'est le `start` de `GenerateStep`, injecté (crédits/portée gérés là). */
  onRetry: () => void;
  /** Découverte bridée à 0 crédit : « Réessayer » grisé (miroir d'affichage, le serveur reste juge). */
  creditsBlocked: boolean;
  /** Suffixe de solde affiché sur le bouton (ex. « (3) ») — vide en payant/bêta/démo. */
  creditSuffix: string;
  /** Le run échoué : joint AUTOMATIQUEMENT au signalement contextuel (corrélation front↔logs). */
  scheduleId: string | null;
}

/**
 * P5-14 PR-2 — l'écran « le service de calcul ne répond pas » (écran B). PAS un consommateur de
 * `SystemScreen` (qui sert les écrans système de ROUTE, hors providers) : ici c'est un ÉTAT du rail
 * de génération, et la scène de génération À L'ARRÊT (`GenerationScene halted`) EST son identité.
 *
 * ⚑ La phrase « il n'y a rien à corriger de votre côté » est le point : c'est le SEUL écran du jeu
 * qui se disculpe explicitement, parce que c'est le seul qu'on peut confondre avec une faute de
 * saisie (un planning infaisable). La vérité vient du CODE (P5-14, règle du fondateur) : cet écran
 * n'est routé que quand le rail de génération a échoué AVANT/PENDANT l'appel moteur
 * (`serviceFailure.ts` / l'aiguillage de `GenerateStep`), jamais sur un verdict d'infaisabilité.
 *
 * A11y (passe de design P5-14) : cet écran REMPLACE un écran d'attente PASSIF — c'est un état, pas
 * une navigation de route. On annonce donc le titre STABLE dans une région `role="status"` polite
 * et on NE VOLE PAS le focus (contrairement à `SystemScreen`, qui EST une navigation).
 */
export function GenerationServiceDown({ onRetry, creditsBlocked, creditSuffix, scheduleId }: GenerationServiceDownProps) {
  const [supportOpen, setSupportOpen] = useState(false);
  // ⚠ Le rail existant ne servira quasi jamais ici : `lastIncidentStore` ne retient un `X-Request-Id`
  // que sur une réponse ≥ 500 (`shared/api/client.ts`), or l'échec de génération est ASYNCHRONE — le
  // POST rend 202 et l'échec arrive en `status: FAILED` dans des réponses 200. On n'INVENTE donc
  // jamais un « SOLVER-503 » : on n'affiche la ligne QUE si une valeur fraîche existe réellement. La
  // corrélation honnête et déjà câblée est le `scheduleId`, joint au signalement contextuel ci-dessous.
  const incidentId = readRecentIncidentRequestId();

  return (
    <>
      <GenerationScene halted>
        {/* Seul le titre STABLE vit dans la région live (polite) — pas de vol de focus. */}
        <div role="status" aria-live="polite" className="w-full max-w-md">
          <p className="text-lg font-medium text-foreground">La table de marque ne répond plus.</p>
        </div>
        <p className="w-full max-w-md text-sm text-muted-foreground">
          Le service qui calcule les plannings est momentanément indisponible. Le problème vient de chez nous : vos équipes, vos gymnases et vos contraintes sont intacts.
        </p>
        {/* Réassurance en `text-foreground` (pas `muted`) : c'est le cœur du message. */}
        <p className="w-full max-w-md text-sm text-foreground">Il n'y a rien à corriger de votre côté — réessayez dans quelques minutes.</p>

        <div className="flex flex-col items-center gap-3 sm:flex-row">
          <Button size="lg" onClick={onRetry} disabled={creditsBlocked}>
            <Rocket className="size-4" />
            Réessayer{creditSuffix}
          </Button>
          <Button size="lg" variant="outline" onClick={() => setSupportOpen(true)}>
            Contacter le support
          </Button>
        </div>

        {incidentId ? (
          // Code de corrélation (X-Request-Id) — discret, jamais un identifiant fabriqué.
          // `select-all` pour le copier d'un geste ; texte visible suffisant (pas d'aria-label
          // sur un <p>, qu'axe refuse — aria-prohibited-attr).
          <p className="select-all text-xs tabular-nums text-muted-foreground">Code incident : {incidentId}</p>
        ) : null}

        {/* P4-129 — détails techniques repliables, DEV uniquement. FRÈRE de la région
            `role="status"` (jamais dedans : un details qui bascule y serait ré-annoncé).
            Le scheduleId de ce run va dans le groupe « Cet écran ». */}
        <DevIncidentDetails scheduleId={scheduleId} />
      </GenerationScene>

      {/* ⚠ Impératif `contextual` : `FeedbackDialog` ne joint le contexte (dont le scheduleId) QU'en
          mode contextuel (FeedbackDialog.tsx:62-69) — en `free`, la corrélation reste à quai. */}
      {supportOpen ? <FeedbackDialog variant="contextual" scheduleId={scheduleId} onClose={() => setSupportOpen(false)} /> : null}
    </>
  );
}

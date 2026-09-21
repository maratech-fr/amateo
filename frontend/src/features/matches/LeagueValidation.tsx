import { ArrowRight, BadgeCheck, CalendarClock } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { toast } from "@/shared/stores/toastStore";

import { ENTRY_DEADLINES_PATH, LEAGUE_VALIDATION_CONFIRM_LABEL, leagueValidationBody } from "./lib/leagueValidation";
import { useConfirmLeagueValidatedFixtures, useLeagueValidationCount } from "./queries";

/**
 * Lot L — « validé ligue » en lot. Un club qui démarre EN COURS de saison importe des
 * domiciles déjà datés côté fédération (date + heure + gymnase). Un geste chiffré et
 * confirmé les bascule d'un coup en « validé ligue » (statut VALIDATED + ancre MANUAL,
 * côté serveur). Deux points d'ancrage — un bandeau de rattrapage sur l'écran Importer
 * et une section du rapport de fin d'import — ouvrent la MÊME confirmation.
 *
 * 🔴 Le front n'a AUCUN prédicat d'éligibilité : il affiche le compte servi par le
 * backend (`useLeagueValidationCount`), maison unique de la règle
 * (`.claude/rules/frontend.md`). La pastille de statut, elle, ne change pas — seul le
 * VOCABULAIRE de la confirmation dit « validé ligue » (mots du fondateur). Le
 * vocabulaire et le corps chiffré, purs, vivent dans `./lib/leagueValidation`.
 */

/**
 * La confirmation chiffrée, PARTAGÉE par le bandeau et le rapport. Refuser n'écrit
 * rien ; confirmer bascule et affiche le nombre RÉELLEMENT basculé (renvoyé par le
 * serveur, source de vérité), puis se ferme.
 */
export function LeagueValidationConfirmDialog({ open, count, onClose }: { open: boolean; count: number; onClose: () => void }) {
  const confirm = useConfirmLeagueValidatedFixtures();
  const handleConfirm = (): void => {
    confirm.mutate(undefined, {
      onSuccess: ({ confirmed }) => {
        toast.success(`${confirmed} rencontre${confirmed > 1 ? "s" : ""} marquée${confirmed > 1 ? "s" : ""} « validé ligue ».`);
        onClose();
      },
    });
  };
  return (
    <ConfirmDialog
      open={open}
      title="Marquer « validé ligue »"
      description={leagueValidationBody(count)}
      confirmLabel={LEAGUE_VALIDATION_CONFIRM_LABEL}
      destructive={false}
      confirmDisabled={confirm.isPending}
      onConfirm={handleConfirm}
      onCancel={onClose}
    />
  );
}

/**
 * Le bandeau de RATTRAPAGE sur l'écran Importer (sous la carte des données de match).
 * Visible dès que le compte est non nul — donc les rencontres DÉJÀ en base le voient
 * sans re-déposer de fichier. `undefined` (chargement/échec) ⇒ 0 ⇒ rendu NUL (jamais
 * un faux calme fabriqué, ni de bandeau avant de savoir). Ton NEUTRE (opportunité, pas
 * défaut) : texte `text-foreground` (AA), teinte dans la bordure/le fond et l'icône.
 */
export function LeagueValidationBanner() {
  const count = useLeagueValidationCount();
  const [open, setOpen] = useState(false);
  const n = count.data?.count ?? 0;
  if (n <= 0) {
    return null;
  }
  return (
    <>
      <div role="status" aria-live="polite" className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/50 px-3 py-2 text-sm text-foreground">
        <BadgeCheck className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        <span className="grow tabular-nums">
          {n} rencontre{n > 1 ? "s" : ""} importée{n > 1 ? "s" : ""} porte{n > 1 ? "nt" : ""} déjà date, heure et gymnase — à confirmer « validé ligue ».
        </span>
        <Button size="sm" className="shrink-0" onClick={() => setOpen(true)}>
          {LEAGUE_VALIDATION_CONFIRM_LABEL}
        </Button>
      </div>
      <LeagueValidationConfirmDialog open={open} count={n} onClose={() => setOpen(false)} />
    </>
  );
}

/**
 * La section du RAPPORT de fin d'import, à côté des actions existantes (« Ouvrir la
 * file », « Apparier les salles »). Même confirmation que le bandeau. Muette tant que
 * rien n'est validable (le compte, invalidé par l'import, se recalcule).
 */
export function LeagueValidationReportEntry() {
  const count = useLeagueValidationCount();
  const [open, setOpen] = useState(false);
  const n = count.data?.count ?? 0;
  if (n <= 0) {
    return null;
  }
  return (
    <div className="mt-1 flex flex-col items-start gap-1 border-t border-border pt-2">
      <p className="text-xs text-muted-foreground">
        {n} rencontre{n > 1 ? "s" : ""} porte{n > 1 ? "nt" : ""} déjà date, heure et gymnase dans FBI — à confirmer « validé ligue ».
      </p>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        <BadgeCheck className="size-4" />
        {LEAGUE_VALIDATION_CONFIRM_LABEL}
      </Button>
      <LeagueValidationConfirmDialog open={open} count={n} onClose={() => setOpen(false)} />
    </div>
  );
}

/**
 * Le renvoi vers les « Échéances de saisie » : une phrase et un bouton, pour que le
 * gestionnaire les renseigne tout de suite si elles manquent (ajout fondateur lot L).
 */
export function EntryDeadlinesLink() {
  const navigate = useNavigate();
  return (
    <div className="mt-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
      <CalendarClock className="size-4 shrink-0" aria-hidden="true" />
      <span className="grow">Des échéances de saisie à renseigner ? Complétez-les pour ne rien manquer.</span>
      <Button variant="ghost" size="sm" className="shrink-0" onClick={() => void navigate(ENTRY_DEADLINES_PATH)}>
        Échéances de saisie
        <ArrowRight className="size-4" aria-hidden="true" />
      </Button>
    </div>
  );
}

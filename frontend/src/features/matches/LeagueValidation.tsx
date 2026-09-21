import { ArrowRight, BadgeCheck, CalendarClock, ListTodo } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { toast } from "@/shared/stores/toastStore";

import type { LeagueToTreatFixture, LeagueValidationOutlook, MissingDeadlineCompetition } from "./api";
import {
  ENTRY_DEADLINES_PATH,
  LEAGUE_VALIDATION_CONFIRM_LABEL,
  REVIEW_QUEUE_ANCHOR,
  leagueValidationIntro,
  maturedLabel,
  toTreatLabel,
} from "./lib/leagueValidation";
import { useConfirmLeagueValidatedFixtures, useLeagueValidationOutlook } from "./queries";

/**
 * Lot O — « validé ligue » en lot, PILOTÉ PAR L'ÉCHÉANCE du championnat. Un club qui
 * démarre EN COURS de saison confirme d'un geste les domiciles des championnats DONT
 * L'ÉCHÉANCE EST PASSÉE (jour inclus). Un championnat dont l'échéance n'est pas passée
 * (nouvelle vague d'octobre, dates provisoires) n'est proposé nulle part.
 *
 * 🔴 Le front n'a AUCUNE règle : le backend décide QUOI est proposé (échéance passée), le
 * front AFFICHE la lecture (`useLeagueValidationOutlook`), maison unique
 * (`.claude/rules/frontend.md`). Trois signaux, tous servis par le backend : des
 * championnats échus PRÊTS (à confirmer), des rencontres échues À TRAITER (nommées, avec
 * renvoi vers la file), et des championnats SANS échéance (à renseigner).
 */

/** Combien d'items nommés avant de replier en « + N autres » (garde le bandeau lisible). */
const NAMED_LIMIT = 6;

function plural(count: number): string {
  return `rencontre${count > 1 ? "s" : ""}`;
}

/**
 * La confirmation : liste CHAQUE championnat échu avec son compte (décision fondateur),
 * puis UN SEUL oui. Elle nomme aussi les rencontres qui ne seront PAS validées (à traiter),
 * jamais écartées en silence. Refuser n'écrit rien ; confirmer bascule et affiche le nombre
 * RÉELLEMENT basculé (renvoyé par le serveur, source de vérité), puis se ferme.
 */
export function LeagueValidationConfirmDialog({ open, outlook, onClose }: { open: boolean; outlook: LeagueValidationOutlook; onClose: () => void }) {
  const confirm = useConfirmLeagueValidatedFixtures();
  const handleConfirm = (): void => {
    confirm.mutate(undefined, {
      onSuccess: ({ confirmed }) => {
        toast.success(`${confirmed} ${plural(confirmed)} marquée${confirmed > 1 ? "s" : ""} « validé ligue ».`);
        onClose();
      },
    });
  };
  return (
    <ConfirmDialog
      open={open}
      title="Marquer « validé ligue »"
      description={
        <div className="flex flex-col gap-2 text-left">
          <p>{leagueValidationIntro(outlook.totalValidatable)}</p>
          <ul className="flex flex-col gap-0.5 text-sm tabular-nums">
            {outlook.matured.map((competition) => (
              <li key={competition.competitionId}>{maturedLabel(competition.name, competition.deadline, competition.validatableCount)}</li>
            ))}
          </ul>
          {outlook.toTreat.length > 0 ? (
            <p className="text-sm text-muted-foreground">
              {outlook.toTreat.length} {plural(outlook.toTreat.length)} échue{outlook.toTreat.length > 1 ? "s" : ""} ne {outlook.toTreat.length > 1 ? "seront" : "sera"} pas validée{outlook.toTreat.length > 1 ? "s" : ""} (ni heure ni gymnase, ou écart) — à traiter dans la file.
            </p>
          ) : null}
        </div>
      }
      confirmLabel={LEAGUE_VALIDATION_CONFIRM_LABEL}
      destructive={false}
      confirmDisabled={confirm.isPending || outlook.totalValidatable <= 0}
      onConfirm={handleConfirm}
      onCancel={onClose}
    />
  );
}

/**
 * Les rencontres échues À TRAITER, NOMMÉES (décision fondateur : plus écartées en silence).
 * Ton NEUTRE-warning : teinte dans la bordure/l'icône, texte `text-foreground` (AA). Le
 * renvoi « Traiter dans la file » scrolle vers la file de traitement de la MÊME page (elle
 * les montre par défaut : ce sont des rencontres NON traitées, jamais masquées).
 */
function LeagueToTreatNotice({ fixtures }: { fixtures: LeagueToTreatFixture[] }) {
  const scrollToQueue = (): void => {
    document.getElementById(REVIEW_QUEUE_ANCHOR)?.scrollIntoView({ behavior: "smooth", block: "start" });
  };
  const named = fixtures.slice(0, NAMED_LIMIT);
  const overflow = fixtures.length - named.length;
  return (
    <div role="status" aria-live="polite" className="flex flex-wrap items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
      <ListTodo className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
      <div className="grow">
        <p className="tabular-nums">
          {fixtures.length} {plural(fixtures.length)} de championnats échus {fixtures.length > 1 ? "restent" : "reste"} à traiter (ni heure ni gymnase, ou écart en attente) :
        </p>
        <ul className="mt-1 flex flex-col gap-0.5 text-muted-foreground">
          {named.map((fixture) => (
            <li key={fixture.fixtureId}>{toTreatLabel(fixture)}</li>
          ))}
          {overflow > 0 ? <li>+ {overflow} autre{overflow > 1 ? "s" : ""}</li> : null}
        </ul>
      </div>
      <Button variant="outline" size="sm" className="shrink-0" onClick={scrollToQueue}>
        Traiter dans la file
        <ArrowRight className="size-4" aria-hidden="true" />
      </Button>
    </div>
  );
}

/**
 * Les championnats SANS échéance qui ont pourtant des rencontres prêtes : sur la base
 * réelle toutes les compétitions ont une échéance, donc une absence est une anomalie à
 * corriger. Renvoi vers l'écran des échéances pour la renseigner (déclenche « validé ligue »).
 */
function MissingDeadlineNotice({ competitions }: { competitions: MissingDeadlineCompetition[] }) {
  const navigate = useNavigate();
  const names = competitions.map((competition) => competition.name).join(", ");
  return (
    <div role="status" aria-live="polite" className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/50 px-3 py-2 text-sm text-foreground">
      <CalendarClock className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
      <span className="grow">
        {competitions.length} championnat{competitions.length > 1 ? "s" : ""} sans échéance {competitions.length > 1 ? "ont" : "a"} des rencontres prêtes ({names}) — renseignez leur échéance de saisie pour les valider.
      </span>
      <Button variant="outline" size="sm" className="shrink-0" onClick={() => void navigate(ENTRY_DEADLINES_PATH)}>
        Échéances de saisie
        <ArrowRight className="size-4" aria-hidden="true" />
      </Button>
    </div>
  );
}

/**
 * Le bandeau de RATTRAPAGE sur l'écran Importer (sous la carte des données de match).
 * `undefined` (chargement/échec) ⇒ rendu NUL (jamais un faux calme fabriqué). Trois blocs
 * indépendants selon ce que le backend sert ; muet si rien.
 */
export function LeagueValidationBanner() {
  const outlook = useLeagueValidationOutlook();
  const [open, setOpen] = useState(false);
  const data = outlook.data;
  if (undefined === data) {
    return null;
  }
  const { matured, toTreat, missingDeadline, totalValidatable } = data;
  if (totalValidatable <= 0 && 0 === toTreat.length && 0 === missingDeadline.length) {
    return null;
  }
  return (
    <div className="flex flex-col gap-2">
      {totalValidatable > 0 ? (
        <div role="status" aria-live="polite" className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/50 px-3 py-2 text-sm text-foreground">
          <BadgeCheck className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          <span className="grow tabular-nums">
            {totalValidatable} {plural(totalValidatable)} de {matured.length} championnat{matured.length > 1 ? "s" : ""} échu{matured.length > 1 ? "s" : ""} {totalValidatable > 1 ? "sont prêtes" : "est prête"} — à confirmer « validé ligue ».
          </span>
          <Button size="sm" className="shrink-0" onClick={() => setOpen(true)}>
            {LEAGUE_VALIDATION_CONFIRM_LABEL}
          </Button>
        </div>
      ) : null}
      {toTreat.length > 0 ? <LeagueToTreatNotice fixtures={toTreat} /> : null}
      {missingDeadline.length > 0 ? <MissingDeadlineNotice competitions={missingDeadline} /> : null}
      <LeagueValidationConfirmDialog open={open} outlook={data} onClose={() => setOpen(false)} />
    </div>
  );
}

/**
 * La section du RAPPORT de fin d'import : muette tant que rien n'est validable (le compte,
 * invalidé par l'import, se recalcule). Ouvre la MÊME confirmation que le bandeau.
 */
export function LeagueValidationReportEntry() {
  const outlook = useLeagueValidationOutlook();
  const [open, setOpen] = useState(false);
  const data = outlook.data;
  const total = data?.totalValidatable ?? 0;
  if (undefined === data || total <= 0) {
    return null;
  }
  return (
    <div className="mt-1 flex flex-col items-start gap-1 border-t border-border pt-2">
      <p className="text-xs text-muted-foreground">
        {total} {plural(total)} de championnats échus {total > 1 ? "portent" : "porte"} déjà date, heure et gymnase dans FBI — à confirmer « validé ligue ».
      </p>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        <BadgeCheck className="size-4" />
        {LEAGUE_VALIDATION_CONFIRM_LABEL}
      </Button>
      <LeagueValidationConfirmDialog open={open} outlook={data} onClose={() => setOpen(false)} />
    </div>
  );
}

/**
 * Le renvoi vers les « Échéances de saisie » : une phrase et un bouton, pour que le
 * gestionnaire les renseigne tout de suite si elles manquent.
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

import { ArrowRight, CalendarClock, ListTodo } from "lucide-react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { useCanManage } from "@/shared/session/queries";

import type { LeagueToTreatFixture, MissingDeadlineCompetition } from "./api";
import { ENTRY_DEADLINES_PATH, REVIEW_QUEUE_ANCHOR, toTreatLabel } from "./lib/leagueValidation";
import { useLeagueValidationOutlook } from "./queries";
import { MATCHES_TEXTS } from "./texts";

/**
 * Lot O — « Déjà dans FBI », PILOTÉ PAR LE DÉMARRAGE du championnat. Un championnat COMMENCÉ
 * (échéance passée, jour inclus, ou premier match joué) n'a plus rien à confirmer : la lecture
 * d'un gestionnaire fait passer seuls ses domiciles datés « Déjà dans FBI » côté serveur. Un
 * championnat qui n'a pas commencé (nouvelle vague d'octobre, dates provisoires) n'est proposé
 * nulle part.
 *
 * 🔴 Le front n'a AUCUNE règle : le backend décide, le front AFFICHE la lecture
 * (`useLeagueValidationOutlook`), maison unique (`.claude/rules/frontend.md`). Deux signaux,
 * servis par le backend : des rencontres de championnats commencés À TRAITER (nommées, avec
 * renvoi vers la file), et des championnats SANS échéance (à renseigner).
 */

/** Combien d'items nommés avant de replier en « + N autres » (garde le bandeau lisible). */
const NAMED_LIMIT = 6;

function plural(count: number): string {
  return `rencontre${count > 1 ? "s" : ""}`;
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
    <NoticeBanner tone="warning" role="status">
      <div className="flex flex-wrap items-start gap-2">
        <ListTodo className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
        <div className="grow">
          <p className="tabular-nums">
            {fixtures.length} {plural(fixtures.length)} de championnats commencés {fixtures.length > 1 ? "restent" : "reste"} à traiter (ni heure ni gymnase, ou écart en attente) :
          </p>
          <ul className="mt-1 flex flex-col gap-0.5 text-muted-foreground">
            {named.map((fixture) => (
              <li key={fixture.fixtureId}>{toTreatLabel(fixture)}</li>
            ))}
            {overflow > 0 ? <li>+ {overflow} autre{overflow > 1 ? "s" : ""}</li> : null}
          </ul>
        </div>
        <Button variant="outline" size="sm" className="shrink-0" onClick={scrollToQueue}>
          {MATCHES_TEXTS.leagueTreatInQueue}
          <ArrowRight className="size-4" aria-hidden="true" />
        </Button>
      </div>
    </NoticeBanner>
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
    <NoticeBanner tone="muted" role="status">
      <div className="flex flex-wrap items-center gap-2">
        <CalendarClock className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        <span className="grow">
          {competitions.length} championnat{competitions.length > 1 ? "s" : ""} sans échéance {competitions.length > 1 ? "ont" : "a"} des rencontres prêtes ({names}) — renseignez leur échéance de saisie pour les valider.
        </span>
        <Button variant="outline" size="sm" className="shrink-0" onClick={() => void navigate(ENTRY_DEADLINES_PATH)}>
          {MATCHES_TEXTS.entryDeadlinesLink}
          <ArrowRight className="size-4" aria-hidden="true" />
        </Button>
      </div>
    </NoticeBanner>
  );
}

/**
 * Le bandeau sur l'écran Importer et le Calendrier (sous la carte des données de match) :
 * ce qui reste À TRAITER et les championnats SANS échéance. `undefined` (chargement/échec) ⇒
 * rendu NUL (jamais un faux calme fabriqué). Muet si rien. Des validables encore servis (lecture
 * dont le balayage a perdu une course) ne font naître ni bandeau ni geste : la lecture suivante
 * les bascule.
 */
export function LeagueValidationBanner() {
  // La lecture est réservée aux gestionnaires (403 sinon) : un Membre ne la déclenche jamais et ne
  // voit pas le bandeau.
  const canManage = useCanManage();
  const outlook = useLeagueValidationOutlook(canManage);
  const data = outlook.data;
  if (!canManage || undefined === data) {
    return null;
  }
  const { toTreat, missingDeadline } = data;
  if (0 === toTreat.length && 0 === missingDeadline.length) {
    return null;
  }
  return (
    <div className="flex flex-col gap-2">
      {toTreat.length > 0 ? <LeagueToTreatNotice fixtures={toTreat} /> : null}
      {missingDeadline.length > 0 ? <MissingDeadlineNotice competitions={missingDeadline} /> : null}
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
      <span className="grow">{MATCHES_TEXTS.entryDeadlinesPrompt}</span>
      <Button variant="ghost" size="sm" className="shrink-0" onClick={() => void navigate(ENTRY_DEADLINES_PATH)}>
        {MATCHES_TEXTS.entryDeadlinesLink}
        <ArrowRight className="size-4" aria-hidden="true" />
      </Button>
    </div>
  );
}

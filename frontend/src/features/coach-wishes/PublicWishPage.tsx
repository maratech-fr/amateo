import { useEffect, useMemo, useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { useParams } from "react-router";

import { AuthLayout } from "@/features/auth/AuthLayout";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Spinner } from "@/shared/components/ui/spinner";

import { getPublicWishContext, isPublicWishError, submitPublicWishes, type PublicWishContext, type PublicWishSubmission } from "./publicApi";
import { useWishStepper } from "./useWishStepper";
import { WishRecap } from "./WishRecap";
import { WishTeamStep } from "./WishTeamStep";
import { clearDraft, loadDraft, saveDraft } from "./wishDraft";
import { buildInitialSections, cloneSections, frDate, isSectionDirty, toSubmission, type SectionState } from "./wishSections";

/**
 * Page publique de collecte (feature #10) — SANS LOGIN, en ÉTAPES (lot E, P2-24). Le coach
 * ouvre son lien `/doleances/:token` : intro (le pourquoi) → une étape PAR équipe (ses
 * semaines) → récapitulatif → validation. L'envoi est UNIQUE, à la fin ; il n'envoie que
 * les sections MODIFIÉES (dirty-tracking) — une section non touchée n'écrit rien. Un filet
 * sessionStorage LOCAL survit à un rechargement d'onglet (purgé au succès).
 */
export function PublicWishPage() {
  const { token = "" } = useParams();

  const query = useQuery({
    queryKey: ["public-wish", token],
    queryFn: () => getPublicWishContext(token),
    retry: false,
    staleTime: 0,
  });

  if (query.isLoading) {
    return (
      <AuthLayout title="Vos disponibilités" description="Chargement…">
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner className="size-4" />
          Un instant…
        </p>
      </AuthLayout>
    );
  }

  if (query.isError) {
    const status = isPublicWishError(query.error) ? query.error.response.status : 0;
    if (410 === status) {
      return (
        <AuthLayout title="Lien expiré" description="La collecte est close.">
          <p className="text-sm text-muted-foreground">La période de collecte est terminée. Rapprochez-vous de votre club si vous souhaitez encore transmettre vos disponibilités.</p>
        </AuthLayout>
      );
    }
    return (
      <AuthLayout title="Lien invalide" description="Ce lien n'est pas reconnu.">
        <p className="text-sm text-muted-foreground">Ce lien est invalide. Vérifiez qu'il est complet, ou demandez-en un nouveau à votre club.</p>
      </AuthLayout>
    );
  }

  if (undefined === query.data) {
    return null;
  }
  return <PublicWishForm token={token} context={query.data} />;
}

function PublicWishForm({ token, context }: { token: string; context: PublicWishContext }) {
  // Snapshot initial (état courant pré-rempli côté serveur) — référence du dirty-tracking.
  const initial = useMemo(() => buildInitialSections(context), [context]);

  // Brouillon LOCAL éventuel (rechargement d'onglet) — lu une seule fois au montage.
  const draft = useMemo(() => loadDraft(token), [token]);

  const [sections, setSections] = useState<Map<string, SectionState>>(() => {
    const base = cloneSections(initial);
    if (null !== draft) {
      for (const [key, value] of draft.sections) {
        if (base.has(key)) {
          base.set(key, { slotsWanted: value.slotsWanted, days: new Set(value.days), comment: value.comment });
        }
      }
    }
    return base;
  });
  const [done, setDone] = useState(false);

  const teamIds = useMemo(() => context.teams.map((t) => t.id), [context.teams]);
  const stepper = useWishStepper(teamIds, draft?.stepIndex ?? 0);

  const mutation = useMutation({
    mutationFn: (submissions: PublicWishSubmission[]) => submitPublicWishes(token, submissions),
    onSuccess: () => {
      clearDraft(token);
      setDone(true);
    },
  });

  // Filet LOCAL : sauvegarde à chaque changement de section ou d'étape (jamais serveur).
  useEffect(() => {
    if (!done) {
      saveDraft(token, sections, stepper.index);
    }
  }, [done, sections, stepper.index, token]);

  const patch = (key: string, next: Partial<SectionState>) =>
    setSections((prev) => {
      const map = new Map(prev);
      const cur = map.get(key);
      if (undefined === cur) {
        return prev;
      }
      map.set(key, { ...cur, ...next });
      return map;
    });

  const toggleDay = (key: string, day: number) =>
    setSections((prev) => {
      const map = new Map(prev);
      const cur = map.get(key);
      if (undefined === cur) {
        return prev;
      }
      const days = new Set(cur.days);
      if (days.has(day)) {
        days.delete(day);
      } else {
        days.add(day);
      }
      map.set(key, { ...cur, days });
      return map;
    });

  const dirtyKeys = [...sections.keys()].filter((key) => isSectionDirty(sections.get(key), initial.get(key)));

  const submit = () => {
    const submissions: PublicWishSubmission[] = dirtyKeys.map((key) => toSubmission(key, sections.get(key) as SectionState));
    mutation.mutate(submissions);
  };

  if (0 === context.teams.length) {
    return (
      <AuthLayout title="Aucune équipe concernée" description={context.periodTitle}>
        <EmptyHint>Aucune de vos équipes n'est concernée par cette collecte pour le moment. Rapprochez-vous de votre club.</EmptyHint>
      </AuthLayout>
    );
  }

  if (done) {
    return (
      <AuthLayout title="Merci !" description="Vos disponibilités sont enregistrées.">
        <p className="text-sm text-muted-foreground">C'est transmis à votre club. Vous pouvez revenir modifier ce formulaire jusqu'au {frDate(context.deadline)}.</p>
      </AuthLayout>
    );
  }

  const description = `${context.periodTitle} · à renvoyer avant le ${frDate(context.deadline)}`;
  const { current } = stepper;

  const title = "intro" === current.kind ? `Bonjour ${context.coachFirstName}` : "team" === current.kind ? (context.teams[current.teamIndex ?? 0]?.name ?? "Votre équipe") : "Récapitulatif";

  return (
    <AuthLayout title={title} description={description}>
      <WishProgress stepper={stepper} teams={context.teams} />

      {"intro" === current.kind ? (
        <div className="space-y-4">
          {null !== context.respondedAt ? <p className="rounded-md border border-accent/40 bg-accent/10 p-3 text-sm text-foreground">Vous avez déjà répondu le {frDate(context.respondedAt.slice(0, 10))}. Révisable jusqu'au {frDate(context.deadline)}.</p> : null}
          <p className="text-sm text-muted-foreground">
            Bonjour {context.coachFirstName} — votre club prépare le planning de {context.periodTitle}. Pour chaque équipe, indiquez combien de séances vous souhaitez et vos jours d'indisponibilité, semaine par semaine. C'est un souhait, pas un engagement&nbsp;: le club arbitre selon les
            gymnases disponibles. Comptez 5&nbsp;minutes — vos réponses partent en une seule fois, à la fin. À renvoyer avant le {frDate(context.deadline)}.
          </p>
          <Button className="w-full" onClick={() => stepper.next()}>
            {null !== context.respondedAt ? "Réviser mes réponses" : "Commencer"}
          </Button>
        </div>
      ) : null}

      {"team" === current.kind ? (
        <div className="space-y-4">
          <WishTeamStep team={context.teams[current.teamIndex ?? 0]} weeks={context.weeks} sections={sections} onPatch={patch} onToggleDay={toggleDay} />
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="ghost" onClick={() => stepper.prev()}>
              Précédent
            </Button>
            <div className="flex-1" />
            {stepper.returningToRecap ? (
              <Button onClick={() => stepper.next()}>Revenir au récapitulatif</Button>
            ) : (
              <>
                <Button variant="ghost" onClick={() => stepper.next()}>
                  Rien à signaler
                </Button>
                <Button onClick={() => stepper.next()}>Suivant</Button>
              </>
            )}
          </div>
        </div>
      ) : null}

      {"recap" === current.kind ? (
        <div className="space-y-4">
          <WishRecap teams={context.teams} weeks={context.weeks} sections={sections} initial={initial} onEditTeam={(id) => stepper.editTeam(id)} />
          {mutation.isError ? <p className="text-sm text-destructive">Envoi impossible pour le moment. Réessayez dans un instant.</p> : null}
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="ghost" onClick={() => stepper.prev()}>
              Précédent
            </Button>
            <div className="flex-1" />
            <Button disabled={mutation.isPending} onClick={submit}>
              {mutation.isPending ? <Spinner className="size-4" /> : null}
              {0 === dirtyKeys.length ? "Confirmer sans modification" : `Valider et envoyer${dirtyKeys.length > 1 ? ` (${dirtyKeys.length})` : ""}`}
            </Button>
          </div>
        </div>
      ) : null}
    </AuthLayout>
  );
}

/** Fil d'Ariane des étapes : visitées cliquables (✓), courante `aria-current`, à venir inertes. */
function WishProgress({ stepper, teams }: { stepper: ReturnType<typeof useWishStepper>; teams: { id: string; name: string }[] }) {
  const label = (index: number): string => {
    const step = stepper.steps[index];
    if ("intro" === step.kind) {
      return "Début";
    }
    if ("recap" === step.kind) {
      return "Récap";
    }
    return teams[step.teamIndex ?? 0]?.name ?? "Équipe";
  };

  return (
    <nav aria-label="Progression" className="mb-4">
      <ol className="flex flex-wrap items-center gap-1.5 text-xs">
        {stepper.steps.map((step, i) => {
          const isCurrent = i === stepper.index;
          const visited = stepper.isVisited(i);
          const content = `${visited && !isCurrent ? "✓ " : ""}${label(i)}`;
          return (
            <li key={`${step.kind}-${step.teamId ?? i}`}>
              {isCurrent ? (
                <span aria-current="step" className="rounded-full bg-accent px-2.5 py-1.5 font-medium text-accent-foreground">
                  {label(i)}
                </span>
              ) : stepper.canGoTo(i) ? (
                <button type="button" onClick={() => stepper.goTo(i)} className="rounded-full border border-border px-2.5 py-1.5 text-muted-foreground hover:text-foreground">
                  {content}
                </button>
              ) : (
                <span className="rounded-full px-2.5 py-1.5 text-muted-foreground/70">{label(i)}</span>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

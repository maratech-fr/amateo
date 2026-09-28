import { Plus, Trash2 } from "lucide-react";
import { type ReactNode, useState } from "react";
import { Link, useSearchParams } from "react-router";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Select } from "@/shared/components/ui/select";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { DAYS, dayLabelLong } from "@/shared/lib/days";
import { readFailed } from "@/shared/lib/readState";

import type { ClubLeagueWindow, ClubLeagueWindowInput, LeagueWindowLevel } from "./api";
import { useClubLeagueWindows, useCreateClubLeagueWindow, useDeleteClubLeagueWindow, useUpdateClubLeagueWindow } from "./queries";

/**
 * P4-272 ① — l'écran UNIQUE des contraintes de match, en accordéon (patron
 * `ConfigurationPage`, section ouverte ancrée `?section=`). La section **Ligue**
 * est le CRUD gestionnaire de la copie club de l'enveloppe fédérale (le placement,
 * le radar et le calendrier lisent la même copie) ; les sections **Club**,
 * **Équipes** et **Coachs** arrivent dans les PR suivantes — en attendant, elles
 * pointent vers les écrans qui portent déjà ces réglages (liens croisés).
 *
 * Le badge « modifié »/« ajouté » est calculé SERVEUR (`badge`) : le front
 * l'AFFICHE, il ne le redérive pas (.claude/rules/frontend.md).
 */
type ConstraintsSection = "ligue" | "club" | "equipes" | "coachs";

const SECTIONS: ConstraintsSection[] = ["ligue", "club", "equipes", "coachs"];

function isSection(value: string | null): value is ConstraintsSection {
  return null !== value && (SECTIONS as string[]).includes(value);
}

export function ConstraintsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const openSection = isSection(searchParams.get("section")) ? (searchParams.get("section") as ConstraintsSection) : null;

  const setOpenSection = (section: ConstraintsSection | null): void => {
    const next = new URLSearchParams(searchParams);
    if (null === section) {
      next.delete("section");
    } else {
      next.set("section", section);
    }
    setSearchParams(next, { replace: true });
  };
  const sectionProps = (key: ConstraintsSection) => ({
    open: openSection === key,
    onToggle: (next: boolean): void => setOpenSection(next ? key : null),
  });

  return (
    <div className="flex flex-col gap-3">
      <AccordionSection {...sectionProps("ligue")} title="Ligue">
        <LeagueSection />
      </AccordionSection>

      <AccordionSection {...sectionProps("club")} title="Club">
        <ComingSoon>
          Les contraintes de club arriveront ici. En attendant, l'accès match des gymnases et la durée des matchs se règlent dans la{" "}
          <Link className="text-accent underline" to="/matchs/configuration?section=reglages">
            Configuration
          </Link>
          .
        </ComingSoon>
      </AccordionSection>

      <AccordionSection {...sectionProps("equipes")} title="Équipes">
        <ComingSoon>
          Les contraintes d'équipe arriveront ici. En attendant, la préférence de gymnase et l'habitude de match se règlent dans la{" "}
          <Link className="text-accent underline" to="/matchs/semaine-type">
            Semaine type
          </Link>
          .
        </ComingSoon>
      </AccordionSection>

      <AccordionSection {...sectionProps("coachs")} title="Coachs">
        <ComingSoon>Les contraintes de coach arriveront ici.</ComingSoon>
      </AccordionSection>
    </div>
  );
}

function ComingSoon({ children }: { children: ReactNode }) {
  return (
    <div className="flex flex-col gap-2">
      <StatusPill>Bientôt</StatusPill>
      <p className="text-sm text-muted-foreground">{children}</p>
    </div>
  );
}

const LEVELS: { value: LeagueWindowLevel; label: string }[] = [
  { value: "DEPARTEMENTAL", label: "Départemental" },
  { value: "REGIONAL", label: "Régional" },
];

const GENDERS: { value: string; label: string }[] = [
  { value: "", label: "Tous" },
  { value: "M", label: "Masculin" },
  { value: "F", label: "Féminin" },
  { value: "MIXTE", label: "Mixte" },
];

/**
 * La section Ligue : le tableau éditable de la copie club. Bandeau si la copie est
 * VIDE (le placement n'applique alors plus de règle fédérale). Le badge vient du
 * serveur ; l'écran l'affiche seulement.
 */
function LeagueSection() {
  const windows = useClubLeagueWindows();

  if (readFailed(windows)) {
    return <LoadErrorHint onRetry={() => void windows.refetch()} />;
  }
  if (undefined === windows.data) {
    return <FullPageSpinner />;
  }

  const rows = windows.data;

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Les fenêtres de coup d'envoi imposées par la ligue, copiées pour votre club — vous pouvez les corriger, en ajouter ou en retirer. Le
        placement des matchs et le radar suivent cette copie.
      </p>

      {0 === rows.length ? (
        <p className="rounded-md border border-warning/50 bg-surface-warning px-3 py-2 text-sm text-foreground" role="status">
          Aucune fenêtre ligue — le placement n'applique plus de règle fédérale.
        </p>
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map((window) => (
            <LeagueWindowRow key={window.id} window={window} />
          ))}
        </div>
      )}

      <AddLeagueWindowRow />
    </div>
  );
}

function badgePill(badge: ClubLeagueWindow["badge"]): ReactNode {
  if ("modified" === badge) {
    return <StatusPill variant="warning">Modifié</StatusPill>;
  }
  if ("added" === badge) {
    return <StatusPill variant="accent">Ajouté</StatusPill>;
  }
  return null;
}

const emptyDraft = (window: ClubLeagueWindow | null): ClubLeagueWindowInput => ({
  category: window?.category ?? "",
  level: window?.level ?? "DEPARTEMENTAL",
  gender: window?.gender ?? "",
  dayOfWeek: window?.dayOfWeek ?? 6,
  kickoffMin: window?.kickoffMin ?? "",
  kickoffMax: window?.kickoffMax ?? "",
});

const isComplete = (draft: ClubLeagueWindowInput): boolean =>
  "" !== draft.category.trim() && "" !== draft.kickoffMin && "" !== draft.kickoffMax;

function DraftFields({ draft, set }: { draft: ClubLeagueWindowInput; set: (patch: Partial<ClubLeagueWindowInput>) => void }) {
  return (
    <>
      <Input aria-label="Catégorie" placeholder="Catégorie" value={draft.category} onChange={(e) => set({ category: e.target.value })} className="min-w-32" />
      <Select aria-label="Niveau" value={draft.level} onChange={(e) => set({ level: e.target.value as LeagueWindowLevel })}>
        {LEVELS.map((l) => (
          <option key={l.value} value={l.value}>
            {l.label}
          </option>
        ))}
      </Select>
      <Select aria-label="Genre" value={draft.gender ?? ""} onChange={(e) => set({ gender: e.target.value })}>
        {GENDERS.map((g) => (
          <option key={g.value} value={g.value}>
            {g.label}
          </option>
        ))}
      </Select>
      <Select aria-label="Jour" value={String(draft.dayOfWeek)} onChange={(e) => set({ dayOfWeek: Number(e.target.value) })}>
        {DAYS.map((d) => (
          <option key={d.n} value={d.n}>
            {dayLabelLong(d.n)}
          </option>
        ))}
      </Select>
      <Input aria-label="De" type="time" value={draft.kickoffMin} onChange={(e) => set({ kickoffMin: e.target.value })} />
      <Input aria-label="À" type="time" value={draft.kickoffMax} onChange={(e) => set({ kickoffMax: e.target.value })} />
    </>
  );
}

/** Une ligne éditable de la copie (PUT au « Enregistrer », DELETE au « Supprimer »). */
function LeagueWindowRow({ window }: { window: ClubLeagueWindow }) {
  const [draft, setDraft] = useState<ClubLeagueWindowInput>(() => emptyDraft(window));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const update = useUpdateClubLeagueWindow();
  const remove = useDeleteClubLeagueWindow();
  const set = (patch: Partial<ClubLeagueWindowInput>): void => setDraft((d) => ({ ...d, ...patch }));

  const dirty =
    draft.category !== window.category ||
    draft.level !== window.level ||
    (draft.gender ?? "") !== (window.gender ?? "") ||
    draft.dayOfWeek !== window.dayOfWeek ||
    draft.kickoffMin !== window.kickoffMin ||
    draft.kickoffMax !== window.kickoffMax;

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
      <DraftFields draft={draft} set={set} />
      <div className="ml-auto flex items-center gap-2">
        {badgePill(window.badge)}
        <Button
          size="sm"
          disabled={!dirty || !isComplete(draft) || update.isPending}
          onClick={() => update.mutate({ id: window.id, input: { ...draft, gender: "" === draft.gender ? null : draft.gender } })}
        >
          Enregistrer
        </Button>
        <Button variant="outline" size="sm" aria-label="Supprimer" disabled={remove.isPending} onClick={() => setConfirmDelete(true)}>
          <Trash2 className="size-3.5" />
        </Button>
      </div>
      <ConfirmDialog
        open={confirmDelete}
        title="Supprimer cette fenêtre de ligue ?"
        description="Le placement et le radar cesseront d'appliquer cette fenêtre."
        confirmLabel="Supprimer"
        destructive
        onConfirm={() => {
          setConfirmDelete(false);
          remove.mutate(window.id);
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </div>
  );
}

/** La ligne d'ajout d'une nouvelle fenêtre (POST). */
function AddLeagueWindowRow() {
  const [draft, setDraft] = useState<ClubLeagueWindowInput>(() => emptyDraft(null));
  const create = useCreateClubLeagueWindow();
  const set = (patch: Partial<ClubLeagueWindowInput>): void => setDraft((d) => ({ ...d, ...patch }));

  const submit = (): void => {
    create.mutate(
      { ...draft, gender: "" === draft.gender ? null : draft.gender },
      { onSuccess: () => setDraft(emptyDraft(null)) },
    );
  };

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-dashed border-border px-3 py-2">
      <DraftFields draft={draft} set={set} />
      <Button size="sm" className="ml-auto" disabled={!isComplete(draft) || create.isPending} onClick={submit}>
        <Plus className="size-3.5" />
        Ajouter
      </Button>
    </div>
  );
}

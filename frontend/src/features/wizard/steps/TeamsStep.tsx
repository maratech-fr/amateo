import { closestCorners, DndContext, type DragEndEvent, DragOverlay, KeyboardSensor, PointerSensor, useDroppable, useSensor, useSensors } from "@dnd-kit/core";
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { ArrowUpDown, ChevronDown, ChevronUp, GripVertical, Link2, Plus, Trash2 } from "lucide-react";
import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";

import { useMe } from "@/shared/session/queries";
import { useTeamLinks } from "@/features/matches/queries";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { groupTeamsByTier, TIER_MEANING, tierGroupLabel } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

import { TEAM_COLUMNS } from "../lib/teamColumns";

import type { Gender, PriorityTier, SharedTrainingGroup, SportCategory, Team, TeamLevel, TeamPayload } from "../api";
import { useWizardFooter } from "../lib/footerSlot";
import { orderedTeams, teamsOfTier } from "../lib/ranking";
import { useCreateTeam, useDeleteTeam, useDeletionImpact, usePriorityTiers, useReorderTeams, useSharedTrainingGroups, useSportCategories, useUpdateTeam, useWizardTeams } from "../queries";
import { useWizardStore } from "../store";
import { PeriodTeams } from "./PeriodStructure";
import { TeamLinksModal } from "./TeamLinksModal";
import { compareNamesFr } from "@/shared/lib/nameOrder";

const GENDERS: { value: Gender | ""; label: string }[] = [
  { value: "", label: "—" },
  { value: "M", label: "Homme" },
  { value: "F", label: "Femme" },
  { value: "MIXTE", label: "Mixte" },
];

// FFBB competition levels (backend App\Enum\TeamLevel). "" = non renseigné.
const LEVELS: { value: TeamLevel | ""; label: string }[] = [
  { value: "", label: "—" },
  { value: "ELITE", label: "Élite" },
  { value: "NATIONAL", label: "National" },
  { value: "REGIONAL", label: "Régional" },
  { value: "PRE_REGION", label: "Pré-région" },
  { value: "DEPARTEMENTAL", label: "Départemental" },
  { value: "HONNEUR", label: "Honneur" },
  { value: "PROMOTION", label: "Promotion" },
  { value: "LOISIR_ADULTE", label: "Loisir adulte" },
  { value: "LOISIR_JEUNE", label: "Loisir jeune" },
];

/**
 * Une équipe qui JOUE déjà est inscrite sous son niveau auprès de la fédération : on
 * ne la supprime plus (ses matchs partiraient avec elle) et son niveau ne bouge plus.
 * Le serveur refuse les deux ; l'écran ne les propose donc pas, plutôt que d'offrir un
 * geste qui finira en 409.
 */
const ENGAGED_REASON = "Cette équipe joue en compétition : ses matchs sont engagés auprès de la fédération.";

/** A team is "competitive" unless it plays at a loisir level (or has none set). */
const isCompetitive = (level: TeamLevel | null): boolean =>
  null !== level && "LOISIR_ADULTE" !== level && "LOISIR_JEUNE" !== level;

/** The "Bonus" tier is identified by its label ("D"), not a fixture row id. */
const isBonusTier = (tiers: PriorityTier[], tierId: number): boolean =>
  tiers.find((t) => t.id === tierId)?.label === "D";

function payload(team: Team, patch: Partial<TeamPayload>): TeamPayload {
  return {
    name: team.name,
    sportCategoryId: team.sportCategoryId,
    priorityTierId: team.priorityTierId,
    tierOrder: team.tierOrder,
    gender: team.gender,
    level: team.level,
    sessionsPerWeek: team.sessionsPerWeek,
    isActive: team.isActive,
    ...patch,
  };
}

const nextOrder = (teams: Team[], tierId: number): number => {
  const group = teamsOfTier(teams, tierId);
  return 0 === group.length ? 0 : Math.max(...group.map((t) => t.tierOrder)) + 1;
};

interface RowProps {
  team: Team;
  /** Position dans l'ordre par rang — absente en liste plate, où elle n'aurait pas de sens. */
  number?: number;
  categories: SportCategory[];
  tiers: PriorityTier[];
  onField: (team: Team, patch: Partial<TeamPayload>) => void;
  onDelete: (team: Team) => void;
  /** P2-45 — ouvre la modale « Liens » (passerelles + mutualisation) de cette équipe. */
  onOpenLinks: (team: Team) => void;
  /** Badge de rang : `short` sur la ligne (S/A/B/C/D), `full` en infobulle. */
  rankLabel: { short: string; full: string };
  /** Flèches ↑↓ : ABSENTES quand `onMove` l'est — hors ordre par rang, elles n'ont pas de sens. */
  onMove?: (dir: -1 | 1) => void;
  canUp?: boolean;
  canDown?: boolean;
  /** P2-45 — sous-ligne des liens : « Mutualisée avec … · Passerelle avec … (Préféré) ».
   *  null = ni groupe ni passerelle → aucun texte ajouté (densité nominale). */
  linksLabel?: string | null;
}

function TeamRow({ team, number, categories, tiers, onField, onDelete, onOpenLinks, rankLabel, canUp, canDown, onMove, linksLabel }: RowProps) {
  // Local edit buffers (saved on blur). name/sessions only change through this row.
  const [name, setName] = useState(team.name);
  const [sessions, setSessions] = useState(String(team.sessionsPerWeek));

  // Competitive team ranked "Bonus" (D) is likely a mistake — it will be
  // scheduled last. Non-blocking warning (the solver stays the authority).
  const bonusCompetitionWarning = isCompetitive(team.level) && isBonusTier(tiers, team.priorityTierId);
  // Le serveur le dit (TeamResource.isEngaged) — on ne le recalcule pas ici.
  const engaged = true === team.isEngaged;

  return (
    <div className="border-t border-border py-1.5">
      <div className="flex items-center gap-2">
        {undefined === number ? null : <span className="w-6 shrink-0 text-center text-xs text-muted-foreground">{number}</span>}
        {/* P4-36 (b) — le rang n'existait que comme TITRE de section : invisible dès qu'on
            trie autrement, et illisible quand on cherche une équipe précise. Décision
            fondateur : un badge par ligne, et les flèches sorties du mode « Trier ». */}
        <span className="w-6 shrink-0 rounded border border-border text-center text-xs font-semibold text-muted-foreground" title={rankLabel.full}>
          {rankLabel.short}
        </span>
        {/* Les flèches ne servent QUE dans l'ordre par rang : elles déplacent au sein d'un
            rang, notion qui n'a pas de sens dans une liste triée par catégorie. Absentes
            plutôt qu'inertes — un bouton désactivé sans raison lisible est pire. */}
        {undefined !== onMove ? (
          <span className="flex shrink-0">
            <Button size="icon" variant="ghost" className="size-7" aria-label={`Monter ${team.name}`} disabled={true !== canUp} onClick={() => onMove(-1)}>
              <ChevronUp className="size-4" />
            </Button>
            <Button size="icon" variant="ghost" className="size-7" aria-label={`Descendre ${team.name}`} disabled={true !== canDown} onClick={() => onMove(1)}>
              <ChevronDown className="size-4" />
            </Button>
          </span>
        ) : null}
        <Input
          aria-label="Nom"
          className={cn("h-8", TEAM_COLUMNS.name)}
          value={name}
          onChange={(e) => setName(e.target.value)}
          onBlur={() => name.trim() && name !== team.name && onField(team, { name: name.trim() })}
        />
        <Select aria-label="Catégorie" className={cn("h-8", TEAM_COLUMNS.category)} value={team.sportCategoryId} onChange={(e) => onField(team, { sportCategoryId: e.target.value })}>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </Select>
        <Select aria-label="Genre" className={cn("h-8", TEAM_COLUMNS.gender)} value={team.gender ?? ""} onChange={(e) => onField(team, { gender: (e.target.value || null) as Gender | null })}>
          {GENDERS.map((g) => (
            <option key={g.value} value={g.value}>
              {g.label}
            </option>
          ))}
        </Select>
        <Select
            aria-label="Niveau de jeu"
            className={cn("h-8", TEAM_COLUMNS.level)}
            value={team.level ?? ""}
            disabled={engaged}
            onChange={(e) => onField(team, { level: (e.target.value || null) as TeamLevel | null })}
          >
            {LEVELS.map((l) => (
              <option key={l.value} value={l.value}>
                {l.label}
              </option>
            ))}
          </Select>
        <Input
          aria-label="Séances/sem"
          type="number"
          min={1}
          className={cn("h-8", TEAM_COLUMNS.sessions)}
          value={sessions}
          onChange={(e) => setSessions(e.target.value)}
          onBlur={() => Number(sessions) !== team.sessionsPerWeek && onField(team, { sessionsPerWeek: Number(sessions) })}
        />
        {/* Rang is not edited inline: changing a team's tier is done via the
            "Trier" mode (drag & drop between S/A/B/C/D zones). */}
        {/* P2-45 — l'affordance « Liens » : une icône muette par ligne (densité nominale), à côté
            de la corbeille. Elle ouvre passerelles + mutualisation de CETTE équipe. */}
        <Button size="icon" variant="ghost" className="size-8" aria-label={`Liens de ${team.name}`} onClick={() => onOpenLinks(team)}>
          <Link2 className="size-4" />
        </Button>
        <Button size="icon" variant="ghost" className="size-8 text-destructive" aria-label="Supprimer" disabled={engaged} onClick={() => onDelete(team)}>
          <Trash2 className="size-4" />
        </Button>
      </div>
      {engaged && (
        // Marqueur COURT, mais du TEXTE : un `title` de survol ne suffisait pas — un
        // contrôle `disabled` sort de l'ordre de tabulation ET ne reçoit aucun événement
        // souris, donc au clavier comme au lecteur d'écran deux contrôles disparaissaient
        // sans la moindre raison. Le POURQUOI est dit une fois au-dessus de la liste.
        <p className="ml-8 mt-1 text-xs text-muted-foreground">Engagée en compétition — niveau et suppression verrouillés.</p>
      )}
      {linksLabel ? <p className="ml-8 mt-1 text-xs text-muted-foreground">{linksLabel}</p> : null}
      {bonusCompetitionWarning && (
        <p role="alert" className="ml-8 mt-1 text-xs text-warning">
          Équipe en compétition classée Bonus (D) — elle passera en dernier ; vérifiez le rang.
        </p>
      )}
    </div>
  );
}

/** Les colonnes triables de la liste. `rang` est le défaut, et le seul qui garde les sections. */
type SortColumn = "rang" | "name" | "category" | "gender" | "level" | "sessions";

/**
 * Comparateur d'une colonne. ⚠ La catégorie se compare sur l'ORDRE SERVI par l'API (le
 * `sortOrder` du catalogue), jamais sur son nom : trier « U11 » avant « U9 » alphabétiquement
 * donnerait un second ordre de catégories dans la même application.
 */
/** Rang d'un niveau dans la hiérarchie FFBB affichée ; sans niveau ⇒ en fin. */
function levelRank(level: TeamLevel | null): number {
  const index = LEVELS.findIndex((l) => l.value === level);

  return index < 0 ? Number.MAX_SAFE_INTEGER : index;
}

function compareOn(column: SortColumn, a: Team, b: Team, categoryRank: Map<string, number>): number {
  const byName = compareNamesFr(a.name, b.name);
  switch (column) {
    case "name":
      return byName;
    case "category":
      return (categoryRank.get(a.sportCategoryId) ?? Number.MAX_SAFE_INTEGER) - (categoryRank.get(b.sportCategoryId) ?? Number.MAX_SAFE_INTEGER) || byName;
    case "gender":
      return (a.gender ?? "").localeCompare(b.gender ?? "", "fr") || byName;
    case "level":
      // ⚠ Sur la HIÉRARCHIE déclarée (`LEVELS`), pas sur l'alphabet : comparer les codes
      // de l'enum plaçait Départemental avant Élite, et les équipes sans niveau en tête
      // (revue #347). Sans niveau = en fin de liste, là où on va les compléter.
      return levelRank(a.level) - levelRank(b.level) || byName;
    case "sessions":
      return a.sessionsPerWeek - b.sessionsPerWeek || byName;
    default:
      return a.priorityTierId - b.priorityTierId || a.tierOrder - b.tierOrder || byName;
  }
}

/**
 * Un en-tête de colonne CLIQUABLE. `aria-sort` porte l'état pour les lecteurs d'écran —
 * une flèche dessinée ne dit rien à qui ne voit pas l'écran.
 */
function SortHeader({
  column,
  sort,
  onSort,
  className,
  label,
}: {
  column: SortColumn;
  sort: { column: SortColumn; dir: 1 | -1 };
  onSort: (next: { column: SortColumn; dir: 1 | -1 }) => void;
  className?: string;
  /** Libellé TEXTE (et non `children`) : il entre dans le nom accessible avec l'état. */
  label: string;
}) {
  const active = sort.column === column;
  // ⚠ `aria-sort` n'est reconnu que sur un `columnheader` — sur un span nu il est retiré de
  // l'arbre d'accessibilité (revue #347), et la flèche visible est `aria-hidden`. L'état
  // vit donc dans le NOM du bouton : sans lui, un lecteur d'écran entend « Rang, bouton »
  // avant et après le clic, et pour la colonne active comme pour les autres.
  // ⚠ Le nom dit l'ACTION, pas seulement la colonne : « Niveau de jeu » tout court entrait
  // en collision avec le champ du formulaire qui porte déjà ce nom — deux contrôles
  // différents, un seul nom, et un lecteur d'écran n'a plus de quoi les distinguer. Le test
  // l'a montré en trouvant deux éléments là où il en attendait un.
  const state = active ? (1 === sort.dir ? ", trié croissant" : ", trié décroissant") : "";

  return (
    <span className={className}>
      <button
        type="button"
        aria-label={`Trier par ${label.toLocaleLowerCase("fr")}${state}`}
        className={cn("inline-flex items-center gap-1 hover:text-foreground", active && "text-foreground")}
        onClick={() => onSort({ column, dir: active && 1 === sort.dir ? -1 : 1 })}
      >
        {label}
        {active ? <span aria-hidden="true">{1 === sort.dir ? "\u2191" : "\u2193"}</span> : null}
      </button>
    </span>
  );
}

// --- Sort mode (drag & drop, inter-tier) --------------------------------------

interface SortRowProps {
  team: Team;
  canUp: boolean;
  canDown: boolean;
  onArrow: (dir: -1 | 1) => void;
}

/** Compact sortable row: drag handle + name + up/down arrows (a11y fallback). No delete/edit. */
function SortableTeamRow({ team, canUp, canDown, onArrow }: SortRowProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: team.id });
  const style = { transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.4 : 1 };
  return (
    <div ref={setNodeRef} style={style} className="flex items-center gap-2 rounded-md border border-border bg-background px-2 py-2">
      <button type="button" className="-m-1 cursor-grab touch-none rounded p-1 text-muted-foreground hover:text-foreground" aria-label="Déplacer l'équipe" {...attributes} {...listeners}>
        <GripVertical className="size-4" />
      </button>
      <span className="flex-1 text-sm">{team.name}</span>
      <Button size="icon" variant="ghost" className="size-7" aria-label="Monter" disabled={!canUp} onClick={() => onArrow(-1)}>
        <ChevronUp className="size-4" />
      </Button>
      <Button size="icon" variant="ghost" className="size-7" aria-label="Descendre" disabled={!canDown} onClick={() => onArrow(1)}>
        <ChevronDown className="size-4" />
      </Button>
    </div>
  );
}

interface TierZoneProps {
  tier: PriorityTier;
  teamIds: string[];
  teamById: Map<string, Team>;
  onArrow: (tierId: number, teamId: string, dir: -1 | 1) => void;
}

/** A droppable tier zone — always visible during sort so teams can be dropped across tiers. */
function TierZone({ tier, teamIds, teamById, onArrow }: TierZoneProps) {
  const { setNodeRef, isOver } = useDroppable({ id: `zone-${tier.id}` });
  return (
    <section>
      <h3 className="mb-1 text-sm font-semibold">
        {tierGroupLabel(tier)}
      </h3>
      <SortableContext items={teamIds} strategy={verticalListSortingStrategy}>
        <div ref={setNodeRef} className={cn("flex min-h-14 flex-col gap-1.5 rounded-lg border-2 border-dashed p-2 transition-colors", isOver ? "border-accent bg-accent/5" : "border-border")}>
          {0 === teamIds.length ? (
            <p className="py-2 text-center text-xs text-muted-foreground">Glissez une équipe ici</p>
          ) : (
            teamIds.map((id, i) => {
              const team = teamById.get(id);
              return team ? <SortableTeamRow key={id} team={team} canUp={i > 0} canDown={i < teamIds.length - 1} onArrow={(dir) => onArrow(tier.id, id, dir)} /> : null;
            })
          )}
        </div>
      </SortableContext>
    </section>
  );
}

const zoneTierId = (id: string): number | null => (id.startsWith("zone-") ? Number(id.slice(5)) : null);

export function TeamsStep() {
  const mode = useWizardStore((s) => s.mode);
  const calendarEntryId = useWizardStore((s) => s.calendarEntryId);
  // Period mode NEVER renders the seasonal editor (which mutates the base plan) —
  // a null entryId (stale/partial store) shows a recovery hint, not TeamsEditor.
  if ("period" === mode) {
    return calendarEntryId ? <PeriodTeams calendarEntryId={calendarEntryId} /> : <EmptyHint>Période introuvable — revenez au cockpit pour l'ouvrir.</EmptyHint>;
  }
  return <TeamsEditor />;
}

function TeamsEditor() {
  const { data: teams = [] } = useWizardTeams();
  const { data: categories = [], isLoading: categoriesLoading } = useSportCategories();
  const { data: tiers = [] } = usePriorityTiers();
  // P3-16 — ces trois collections n'étaient chargées ICI que pour compter l'impact d'une
  // suppression depuis le cache. Le serveur le fait désormais : trois requêtes de moins, et
  // surtout un compte qui ne peut plus sous-estimer ce qu'il ignore.
  // P2-27 — le repère « mutualisée » sur chaque ligne : les groupes du SOCLE (l'éditeur de saison
  // ne travaille jamais une période). Sans param le provider renvoie socle ET périodes → on filtre.
  const { data: sharedGroups = [] } = useSharedTrainingGroups(null);
  // P2-45 — les passerelles du club+saison, SERVIES par le module matchs (régime 1, aucune
  // redérivation) : elles nourrissent la sous-ligne « Passerelle avec … » et la modale Liens.
  const { data: teamLinks = [] } = useTeamLinks();
  const [linksTeam, setLinksTeam] = useState<Team | null>(null);
  const create = useCreateTeam();
  const update = useUpdateTeam();
  const del = useDeleteTeam();
  const reorder = useReorderTeams();
  const [toDelete, setToDelete] = useState<Team | null>(null);
  // P3-16 — l'impact vient du serveur (et porte le refus du périmètre engagé).
  const teamImpact = useDeletionImpact("team", toDelete?.id ?? null);

  // P2-21 lot A — la modale d'annonce de l'import automatique FFBB (décision
  // fondateur 2026-08-04 : « le gestionnaire n'a rien à faire, il constate que
  // 10 équipes sont déjà chargées »). Gate = VÉRITÉ SERVEUR (`ffbbTeamsImported`,
  // posée par l'importeur) : la seule absence du flag localStorage faisait mentir
  // la modale à tout club à saisie manuelle vu d'un navigateur vierge — et
  // bloquait chaque spec e2e (CI suspendue 30 min, 2026-08-04). Le flag
  // localStorage ne sert plus qu'à la rendre one-shot par club.
  const { data: me } = useMe();
  const clubId = me?.club?.id;
  const imported = true === me?.club?.ffbbTeamsImported;
  const onboarding = undefined !== me && !me.seasonPlan?.hasFinishedVersion;
  const [noticeDismissed, setNoticeDismissed] = useState(false);
  // Dérivé au render (pas de setState en effect) : le flag n'est posé qu'à la
  // FERMETURE — un refresh avant lecture re-montre la modale, c'est voulu.
  const noticeSeen = useMemo(
    () => (undefined === clubId ? true : null !== window.localStorage.getItem(`ffbb-teams-import-notice-${clubId}`)),
    [clubId],
  );
  const importNotice = imported && onboarding && teams.length > 0 && !noticeSeen && !noticeDismissed;
  const dismissImportNotice = () => {
    setNoticeDismissed(true);
    if (undefined !== clubId) {
      window.localStorage.setItem(`ffbb-teams-import-notice-${clubId}`, "1");
    }
  };

  const [name, setName] = useState("");
  const [nameError, setNameError] = useState(false);
  const [catError, setCatError] = useState(false);
  const nameRef = useRef<HTMLInputElement>(null);
  const [catId, setCatId] = useState("");
  const [tierId, setTierId] = useState(1);
  const [gender, setGender] = useState<Gender | "">("");
  const [level, setLevel] = useState<TeamLevel | "">("");
  const [sessions, setSessions] = useState("2");
  // ⚠ PLUS de catégorie par défaut (revue #347). Le défaut valait `categories[0]`, ce que
  // le catalogue réordonné transforme en « Vétéran » pour TOUS les clubs : un club de jeunes
  // qui saisit ses vingt équipes d'affilée — le flux que ce lot optimise justement — les
  // classait toutes en vétéran, et la catégorie pilote les contraintes d'âge. L'ordre
  // précédent étant aléatoire, ce n'était pas une régression stricte : c'était un mauvais
  // défaut par hasard, que ce lot rendait mauvais par construction. On demande donc un
  // choix explicite, refusé à la soumission comme l'est déjà un nom vide.
  const effectiveCat = catId;

  // D-26 : la numérotation suit l'AFFICHAGE (groupTeamsByTier, seau « Autres » compris) —
  // sans les rangs elle triait par id brut et la colonne « # » se lisait 1, 2, 4, 5… 3.
  const numberOf = new Map(orderedTeams(teams, tiers).map((r) => [r.team.id, r.globalNumber]));

  // P4-36 (c) — trier par une AUTRE colonne que le rang est incompatible avec un affichage
  // en sections : « trié par catégorie » donnerait cinq listes triées séparément, ce qui ne
  // répond pas à la question posée. Décision fondateur : le rang garde les sections, toute
  // autre colonne bascule en LISTE PLATE — et le badge de rang, ajouté en (b), empêche d'y
  // perdre l'information que les titres de section portaient.
  const [sort, setSort] = useState<{ column: SortColumn; dir: 1 | -1 }>({ column: "rang", dir: 1 });
  const byRank = "rang" === sort.column;
  // ⚠ `groupTeamsByTier` et non `usedTiers`/`teamsOfTier` (revue #347) : le helper partagé
  // range les équipes au rang DÉRIVÉ dans un seau « Autres » et documente qu'aucune n'est
  // jamais perdue. Sans lui, une telle équipe était visible en liste plate et s'évanouissait
  // au retour sur « Rang » — ni supprimable ni reclassable depuis l'écran qui en a la charge.
  const rankGroups = -1 === sort.dir ? [...groupTeamsByTier(teams, tiers)].reverse() : groupTeamsByTier(teams, tiers);
  const rankLabelOf = (team: Team): { short: string; full: string } => {
    const tier = tiers.find((t) => t.id === team.priorityTierId) ?? null;

    return { short: tier?.label ?? "?", full: tierGroupLabel(tier ?? null) };
  };
  // Repère « mutualisée avec … » par équipe — nommer les co-équipières, jamais un simple pictogramme.
  const baseGroups = sharedGroups.filter((g) => null === g.schedulePlanId);
  const groupOfTeam = new Map<string, SharedTrainingGroup>();
  for (const g of baseGroups) {
    for (const id of g.teamIds) {
      groupOfTeam.set(id, g);
    }
  }
  const teamNameById = new Map(teams.map((t) => [t.id, t.name]));
  const mutualiseLabelOf = (teamId: string): string | null => {
    const g = groupOfTeam.get(teamId);
    if (undefined === g) {
      return null;
    }
    const others = g.teamIds.filter((x) => x !== teamId).map((x) => teamNameById.get(x) ?? "?");
    return others.length > 0 ? `Mutualisée avec ${others.join(", ")}` : "Mutualisée";
  };
  // P2-45 — le repère « passerelle » : nomme la co-équipière ET l'INTENSITÉ (tranchage fondateur),
  // lue telle quelle du lien servi (jamais recalculée). Une équipe peut être passerelée à plusieurs.
  const bridgeLabelOf = (teamId: string): string | null => {
    const parts = teamLinks
      .filter((l) => l.teamAId === teamId || l.teamBId === teamId)
      .map((l) => {
        const other = l.teamAId === teamId ? l.teamBId : l.teamAId;
        return `${teamNameById.get(other) ?? "?"} (${"PREFERRED" === l.trainingIntensity ? "Préféré" : "Obligatoire"})`;
      });
    return parts.length > 0 ? `Passerelle avec ${parts.join(", ")}` : null;
  };
  // La sous-ligne combinée : « Mutualisée avec … · Passerelle avec … (Préféré) ». Sans lien ni
  // groupe, `null` → aucun texte (densité nominale, l'icône Liens suffit).
  const linksLabelOf = (teamId: string): string | null => {
    const combined = [mutualiseLabelOf(teamId), bridgeLabelOf(teamId)].filter((x): x is string => null !== x);
    return combined.length > 0 ? combined.join(" · ") : null;
  };
  const categoryRank = new Map(categories.map((c, index) => [c.id, index]));
  const flatTeams = [...teams].sort((a, b) => sort.dir * compareOn(sort.column, a, b, categoryRank));

  // Déplacer d'un rang : on renvoie l'ordre COMPLET, comme le mode « Trier » à sa sortie —
  // un envoi partiel laisserait les équipes sans `tierOrder` explicite là où elles sont.
  // ⚠ Les flèches se TAISENT tant qu'un ordre est en vol (revue #347). Deux pièges
  // sinon : juste après « Terminer le tri », la liste normale se réaffiche avec un `teams`
  // encore PÉRIMÉ (l'invalidation n'a lieu qu'au `onSuccess`), et un clic y reconstruirait
  // l'ordre complet depuis l'état d'AVANT le glisser-déposer — annulant en silence le
  // reclassement qu'on venait de faire. Le double-clic rapide a la même racine.
  const reorderBusy = reorder.isPending;
  const moveInTier = (team: Team, dir: -1 | 1) => {
    if (reorderBusy) {
      return;
    }
    const group = teamsOfTier(teams, team.priorityTierId);
    const from = group.findIndex((t) => t.id === team.id);
    const to = from + dir;
    if (from < 0 || to < 0 || to >= group.length) {
      return;
    }
    const swapped = [...group];
    [swapped[from], swapped[to]] = [swapped[to], swapped[from]];
    const items = tiers.flatMap((tier) =>
      (tier.id === team.priorityTierId ? swapped : teamsOfTier(teams, tier.id)).map((t, index) => ({ id: t.id, priorityTierId: tier.id, tierOrder: index })),
    );
    reorder.mutate(items);
  };

  const onField = (team: Team, patch: Partial<TeamPayload>) => {
    const extra: Partial<TeamPayload> = "priorityTierId" in patch ? { tierOrder: nextOrder(teams, patch.priorityTierId as number) } : {};
    update.mutate({ id: team.id, body: payload(team, { ...patch, ...extra }) });
  };

  const addTeam = (event: FormEvent) => {
    event.preventDefault();
    if ("" === name.trim()) {
      // Silent no-op was frustrating: surface why + jump to the empty field.
      setNameError(true);
      nameRef.current?.focus();
      return;
    }
    if ("" === effectiveCat) {
      setCatError(true);
      return;
    }
    setNameError(false);
    setCatError(false);
    create.mutate({
      name: name.trim(),
      sportCategoryId: effectiveCat,
      priorityTierId: tierId,
      tierOrder: nextOrder(teams, tierId),
      gender: gender || null,
      level: level || null,
      sessionsPerWeek: Number(sessions),
      isActive: true,
    });
    setName("");
    // Return focus to the name field so the next team can be typed straight away.
    nameRef.current?.focus();
  };

  // --- Sort mode: local reordering, committed atomically on exit ---
  const { setFooterExtra, setSuppressScrollJump } = useWizardFooter();
  const [sortMode, setSortMode] = useState(false);
  const [lanes, setLanes] = useState<Record<number, string[]>>({});
  const [activeId, setActiveId] = useState<string | null>(null);
  const lanesRef = useRef(lanes);
  const reorderRef = useRef(reorder);
  const tiersRef = useRef(tiers);
  // Dirty = the user reordered but hasn't committed yet. Guards the flush-on-exit.
  const dirtyRef = useRef(false);
  useEffect(() => {
    reorderRef.current = reorder;
    tiersRef.current = tiers;
  });

  // Build the atomic reorder payload from the current lanes and persist it.
  // Reads everything through refs so the callback is stable — the flush-on-exit
  // effect below must fire ONLY on unmount, never on a tiers refetch.
  const flushSort = useCallback(() => {
    if (!dirtyRef.current) {
      return;
    }
    const items: { id: string; priorityTierId: number; tierOrder: number }[] = [];
    for (const tier of tiersRef.current) {
      (lanesRef.current[tier.id] ?? []).forEach((id, index) => items.push({ id, priorityTierId: tier.id, tierOrder: index }));
    }
    dirtyRef.current = false;
    if (items.length > 0) {
      reorderRef.current.mutate(items);
    }
  }, []);

  // Commit any pending reorder when the step unmounts (e.g. the user clicks
  // "Suivant" while still in sort mode) — otherwise the order was lost.
  useEffect(() => () => flushSort(), [flushSort]);
  const sortedTiers = [...tiers].sort((a, b) => a.id - b.id);
  const teamById = new Map(teams.map((t) => [t.id, t] as const));

  const setBothLanes = (next: Record<number, string[]>) => {
    lanesRef.current = next;
    dirtyRef.current = true;
    setLanes(next);
  };

  // Enter → snapshot the current order into lanes; exit → persist the whole
  // ordering in ONE atomic call (every team gets an explicit tierOrder = index,
  // so anyone without a number gets one). Lanes are edited locally during sort;
  // the server is not re-read until exit, so manual order isn't reverted by the
  // name-sort.
  const toggleSort = useCallback(() => {
    if (sortMode) {
      flushSort();
      setSortMode(false);
      return;
    }
    const next: Record<number, string[]> = {};
    for (const tier of tiers) {
      next[tier.id] = teamsOfTier(teams, tier.id).map((t) => t.id);
    }
    lanesRef.current = next;
    dirtyRef.current = false;
    setLanes(next);
    setSortMode(true);
  }, [sortMode, teams, tiers, flushSort]);

  // Register the "Trier" toggle in the wizard footer, left of "Suivant".
  useEffect(() => {
    setFooterExtra(
      teams.length > 0 ? (
        <Button size="sm" variant={sortMode ? "default" : "outline"} onClick={toggleSort}>
          <ArrowUpDown className="size-4" />
          {sortMode ? "Terminer le tri" : "Trier"}
        </Button>
      ) : null,
    );
    return () => setFooterExtra(null);
  }, [sortMode, teams.length, toggleSort, setFooterExtra]);

  // Hide the floating scroll-jump arrows during drag-reorder (they'd sit over
  // the drop zones and a mis-click would scroll-jump mid-sort).
  useEffect(() => {
    setSuppressScrollJump(sortMode);
    return () => setSuppressScrollJump(false);
  }, [sortMode, setSuppressScrollJump]);

  const laneOf = (id: string): number | null => {
    const zone = zoneTierId(id);
    if (null !== zone) {
      return zone;
    }
    for (const [t, ids] of Object.entries(lanesRef.current)) {
      if (ids.includes(id)) {
        return Number(t);
      }
    }
    return null;
  };

  const onDragEnd = (event: DragEndEvent) => {
    setActiveId(null);
    const { active, over } = event;
    if (null === over) {
      return;
    }
    const from = laneOf(String(active.id));
    const to = laneOf(String(over.id));
    if (null === from || null === to) {
      return;
    }
    const next = { ...lanesRef.current };
    if (from === to) {
      const items = [...(next[from] ?? [])];
      const oldIdx = items.indexOf(String(active.id));
      const overZone = zoneTierId(String(over.id));
      const newIdx = null !== overZone ? items.length - 1 : items.indexOf(String(over.id));
      if (oldIdx < 0 || newIdx < 0 || oldIdx === newIdx) {
        return;
      }
      next[from] = arrayMove(items, oldIdx, newIdx);
    } else {
      const src = [...(next[from] ?? [])];
      const dst = [...(next[to] ?? [])];
      src.splice(src.indexOf(String(active.id)), 1);
      const overZone = zoneTierId(String(over.id));
      let idx = null !== overZone ? dst.length : dst.indexOf(String(over.id));
      if (idx < 0) {
        idx = dst.length;
      }
      dst.splice(idx, 0, String(active.id));
      next[from] = src;
      next[to] = dst;
    }
    setBothLanes(next);
  };

  const moveInLane = (laneId: number, teamId: string, dir: -1 | 1) => {
    const items = [...(lanesRef.current[laneId] ?? [])];
    const idx = items.indexOf(teamId);
    const j = idx + dir;
    if (idx < 0 || j < 0 || j >= items.length) {
      return;
    }
    setBothLanes({ ...lanesRef.current, [laneId]: arrayMove(items, idx, j) });
  };

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  return (
    <div>
      {/* No inner "Équipes" heading: the sticky wizard header already shows
          "Étape 1/6 · Équipes" (WizardLayout). Keep the contextual description. */}
      <p className="mb-2 text-sm text-muted-foreground">
        Listez vos équipes et donnez à chacune un <strong>rang</strong> : il tranche quand les créneaux manquent — les mieux classées passent d'abord.
        Le <strong>niveau de jeu</strong> (division FFBB) est indépendant : il décrit la compétition, pas la priorité de placement.
      </p>
      <div className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
        {(Object.entries(TIER_MEANING) as [string, string][]).map(([label, meaning]) => (
          <span key={label}>
            <strong className="text-foreground">{label}</strong> {meaning}
          </span>
        ))}
      </div>

      {sortMode ? (
        <>
          <p className="mb-3 text-sm text-muted-foreground">
            Glissez une équipe par sa poignée pour la réordonner <strong>ou la déplacer dans un autre niveau</strong>. Les flèches restent disponibles.
          </p>
          <DndContext
            sensors={sensors}
            collisionDetection={closestCorners}
            onDragStart={(event) => setActiveId(String(event.active.id))}
            onDragCancel={() => setActiveId(null)}
            onDragEnd={onDragEnd}
          >
            <div className="flex flex-col gap-4">
              {sortedTiers.map((tier) => (
                <TierZone key={tier.id} tier={tier} teamIds={lanes[tier.id] ?? []} teamById={teamById} onArrow={moveInLane} />
              ))}
            </div>
            <DragOverlay>
              {null !== activeId ? <div className="rounded-md border border-accent bg-background px-3 py-2 text-sm shadow-lg">{teamById.get(activeId)?.name}</div> : null}
            </DragOverlay>
          </DndContext>
        </>
      ) : (
        <>
          {/* P4-36 (a) — l'en-tête ne vivait QUE dans la branche « au moins une équipe »,
              alors que le formulaire est au-dessus et n'a que des placeholders : un club
              neuf saisissait sa première équipe à l'aveugle. Il nomme donc les champs DU
              FORMULAIRE, dont les colonnes diffèrent de celles de la liste (rang ici,
              numéro et suppression là-bas). */}
          <div aria-hidden="true" className="mb-1 flex flex-wrap items-end gap-2 px-3 text-xs font-medium text-muted-foreground">
            <span className={TEAM_COLUMNS.name}>Nom de l'équipe</span>
            <span className={TEAM_COLUMNS.category}>Catégorie</span>
            <span className={TEAM_COLUMNS.gender}>Genre</span>
            <span className={TEAM_COLUMNS.level}>Niveau de jeu</span>
            <span className={TEAM_COLUMNS.sessions}>Séances</span>
            <span className={TEAM_COLUMNS.tier}>Rang</span>
            <span className="ml-auto w-8" />
          </div>
          <form onSubmit={addTeam} className="mb-6 flex flex-wrap items-end gap-2 rounded-lg border border-border bg-card p-3 text-sm">
            <Input
              ref={nameRef}
              aria-label="Nom de l'équipe"
              aria-invalid={nameError}
              aria-describedby={nameError ? "team-name-error" : undefined}
              placeholder="Nom de l'équipe"
              className={cn("h-8", TEAM_COLUMNS.name, nameError ? "border-destructive focus-visible:ring-destructive" : "")}
              value={name}
              onChange={(e) => {
                setName(e.target.value);
                if (nameError) {
                  setNameError(false);
                }
              }}
            />
            <Select
              aria-label="Catégorie"
              aria-invalid={catError}
              aria-describedby={catError ? "team-cat-error" : undefined}
              className={cn("h-8", TEAM_COLUMNS.category, catError ? "border-destructive focus-visible:ring-destructive" : "")}
              value={effectiveCat}
              onChange={(e) => {
                setCatId(e.target.value);
                setCatError(false);
              }}
            >
              <option value="">— catégorie —</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
            <Select aria-label="Genre" className={cn("h-8", TEAM_COLUMNS.gender)} value={gender} onChange={(e) => setGender(e.target.value as Gender | "")}>
              {GENDERS.map((g) => (
                <option key={g.value} value={g.value}>
                  {g.label}
                </option>
              ))}
            </Select>
            <Select aria-label="Niveau de jeu" className={cn("h-8", TEAM_COLUMNS.level)} value={level} onChange={(e) => setLevel(e.target.value as TeamLevel | "")}>
              {LEVELS.map((l) => (
                <option key={l.value} value={l.value}>
                  {l.label}
                </option>
              ))}
            </Select>
            <Input aria-label="Séances/sem" type="number" min={1} className={cn("h-8", TEAM_COLUMNS.sessions)} value={sessions} onChange={(e) => setSessions(e.target.value)} />
            <Select aria-label="Rang" className={cn("h-8", TEAM_COLUMNS.tier)} value={tierId} onChange={(e) => setTierId(Number(e.target.value))}>
              {tiers.map((t) => (
                <option key={t.id} value={t.id}>
                  {tierGroupLabel(t)}
                </option>
              ))}
            </Select>
            {/* Désarmé tant que les catégories chargent : un submit précoce
                enverrait sportCategoryId undefined → 422 (course vue en e2e). */}
            <Button type="submit" size="icon" className="ml-auto size-8" disabled={create.isPending || categoriesLoading} title="Ajouter l'équipe" aria-label="Ajouter l'équipe">
              <Plus className="size-4" />
            </Button>
          </form>
          {/* AUD-A11Y-13 — `aria-invalid` disait « ce champ est fautif » sans jamais dire
          POURQUOI : le message existait à côté, non relié. Un lecteur d'écran l'annonce
          une fois par `role="alert"` (interruption), puis le champ redevient muet — on
          revient dessus, on sait que c'est faux, on ne sait plus ce qu'on doit corriger.
          `aria-describedby` le rattache : le motif se relit avec le champ, à volonté. */}
          {nameError ? (
            <p id="team-name-error" role="alert" className="-mt-4 mb-4 text-sm text-destructive">
              Le nom de l'équipe est obligatoire.
            </p>
          ) : null}
          {catError ? (
            <p id="team-cat-error" role="alert" className="-mt-4 mb-4 text-sm text-destructive">
              Choisissez la catégorie de l'équipe.
            </p>
          ) : null}

          {0 === teams.length ? (
            <EmptyHint>Aucune équipe pour le moment.</EmptyHint>
          ) : (
            <div className="flex flex-col gap-4">
              {teams.some((t) => true === t.isEngaged) && (
                // UNE fois pour la liste : après un import FBI, presque toutes les lignes
                // sont engagées — répéter les deux phrases sous chacune en ferait du bruit
                // qu'on ne lit plus. Chaque ligne porte son propre marqueur court.
                <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                  {ENGAGED_REASON} Leur niveau de jeu et leur suppression sont verrouillés ; le reste (nom, rang, créneaux, gymnase) se modifie librement.
                </p>
              )}
              <div className="flex items-center gap-2 px-2 text-xs font-medium text-muted-foreground">
                {/* Le « # » est la position dans l'ordre par RANG : en liste plate il se
                    lirait 7, 3, 14, 1… soit comme un défaut d'affichage (revue #347). Il
                    sort avec les sections ; le badge, lui, garde le rang sur chaque ligne. */}
                {byRank ? <span className="w-6 text-center">#</span> : null}
                <SortHeader column="rang" sort={sort} onSort={setSort} className="w-6 text-center" label="Rang" />
                {byRank ? <span className="w-14" /> : null}
                <SortHeader column="name" sort={sort} onSort={setSort} className={TEAM_COLUMNS.name} label="Nom de l'équipe" />
                <SortHeader column="category" sort={sort} onSort={setSort} className={TEAM_COLUMNS.category} label="Catégorie" />
                <SortHeader column="gender" sort={sort} onSort={setSort} className={TEAM_COLUMNS.gender} label="Genre" />
                <SortHeader column="level" sort={sort} onSort={setSort} className={TEAM_COLUMNS.level} label="Niveau de jeu" />
                <SortHeader column="sessions" sort={sort} onSort={setSort} className={TEAM_COLUMNS.sessions} label="Séances" />
                <span className="w-8 text-right">Suppr.</span>
              </div>
              {byRank ? (
                // Le SENS s'applique aussi au rang (revue #347) : sans ça, un second clic
                // peignait une flèche descendante et n'inversait rien — l'écran affirmait
                // avoir obéi, et le lecteur d'écran annonçait un tri inexistant.
                rankGroups.map(({ tier, teams: group }) => (
                  <section key={tier?.id ?? "orphan"}>
                    <h3 className="mb-1 text-sm font-semibold">{tierGroupLabel(tier)}</h3>
                    <div className="rounded-lg border border-border bg-card px-2">
                      {group.map((team, index) => (
                        <TeamRow
                          key={team.id}
                          team={team}
                          number={numberOf.get(team.id) ?? 0}
                          categories={categories}
                          tiers={tiers}
                          onField={onField}
                          onDelete={setToDelete}
                          onOpenLinks={setLinksTeam}
                          rankLabel={rankLabelOf(team)}
                          canUp={index > 0 && !reorderBusy}
                          canDown={index < group.length - 1 && !reorderBusy}
                          onMove={null === tier ? undefined : (dir) => moveInTier(team, dir)}
                          linksLabel={linksLabelOf(team.id)}
                        />
                      ))}
                    </div>
                  </section>
                ))
              ) : (
                // Liste PLATE : les sections n'auraient plus de sens (le tri les traverserait),
                // et le badge de rang par ligne porte l'information qu'elles donnaient.
                <div className="rounded-lg border border-border bg-card px-2">
                  {flatTeams.map((team) => (
                    <TeamRow key={team.id} team={team} categories={categories} tiers={tiers} onField={onField} onDelete={setToDelete} onOpenLinks={setLinksTeam} rankLabel={rankLabelOf(team)} linksLabel={linksLabelOf(team.id)} />
                  ))}
                </div>
              )}
            </div>
          )}
        </>
      )}

      {/* P2-21 lot A — l'annonce de l'import automatique, dans les mots du fondateur. */}
      <ConfirmDialog
        open={importNotice}
        title="Équipes importées depuis la FFBB"
        description="Les équipes ont été importées automatiquement depuis la FFBB. Des erreurs ont pu se glisser — merci de corriger et de compléter cet écran (vos équipes loisir et école de basket ne sont pas connues de la fédération)."
        confirmLabel="Compris"
        cancelLabel="Fermer"
        onConfirm={dismissImportNotice}
        onCancel={dismissImportNotice}
      />
      <DeleteConfirm
        open={toDelete !== null}
        entityName={toDelete?.name ?? ""}
        affectsPeriodPlans
        // P3-16 : le serveur compte (et dit si le périmètre engagé refusera le geste).
        impact={teamImpact.data ?? undefined}
        impactLoading={teamImpact.isPending && null !== toDelete}
        impactFailed={teamImpact.isError}
        onConfirm={() => {
          if (toDelete) {
            del.mutate(toDelete.id);
          }
          setToDelete(null);
        }}
        onCancel={() => setToDelete(null)}
      />
      {/* P2-45 — la modale Liens de l'équipe. En SAISON, les passerelles sont ÉDITABLES
          (readOnlyLinks=false) et la mutualisation écrit sur le socle (schedulePlanId null). */}
      {null !== linksTeam ? (
        <TeamLinksModal team={linksTeam} teams={teams} tiers={tiers} schedulePlanId={null} readOnlyLinks={false} onClose={() => setLinksTeam(null)} />
      ) : null}
    </div>
  );
}

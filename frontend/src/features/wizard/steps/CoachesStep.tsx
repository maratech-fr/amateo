import { Check, Pencil, Plus, Trash2, X } from "lucide-react";
import { type FormEvent, useEffect, useRef, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { coachFullName } from "@/shared/lib/coachName";
import { stripDiacritics } from "@/shared/lib/utils";

import type { Coach, CoachPlayerMembership, PriorityTier, Team, TeamCoach, TeamCoachRole } from "../api";
import {
  useCreateCoach,
  useCreateCoachPlayer,
  useCreateTeamCoach,
  useDeleteCoach,
  useDeletionImpact,
  useDeleteCoachPlayer,
  useDeleteTeamCoach,
  usePriorityTiers,
  useUpdateCoach,
  useWizardCoachPlayers,
  useWizardCoaches,
  useWizardTeamCoaches,
  useWizardTeams,
} from "../queries";
import { type CoachGroup, coachGroupOf, groupedCoaches } from "../lib/ranking";
import { useWizardStore } from "../store";
import { ReadonlyCoaches } from "./StructureSummary";
import { PlacedConflictsNotice } from "@/features/planning/PlacedConflictsNotice";
import { usePlacedConflicts } from "@/features/planning/queries";

function payload(coach: Coach, patch: Partial<Coach>) {
  return {
    firstName: coach.firstName,
    lastName: coach.lastName,
    email: coach.email,
    isEmployee: coach.isEmployee,
    isActive: coach.isActive,
    maxDaysOverride: coach.maxDaysOverride,
    isVehicled: coach.isVehicled,
    ...patch,
  };
}

interface CardProps {
  coach: Coach;
  teams: Team[];
  tiers: PriorityTier[];
  teamName: Map<string, string>;
  coachLinks: TeamCoach[];
  playerLinks: CoachPlayerMembership[];
  /** Édition contrôlée par le parent (une seule carte à la fois) : lier une personne pendant
   *  l'édition ne doit pas la faire sauter de section — le reclassement attend « Terminé ». */
  editing: boolean;
  onToggleEdit: () => void;
  /** Coach qui vient d'être créé : on ouvre l'édition ET on met le focus sur le 1ᵉʳ contrôle de
   *  liaison (l'équipe) — seulement s'il y a des équipes à lier. */
  autoFocusLink: boolean;
}

function CoachCard({ coach, teams, tiers, teamName, coachLinks, playerLinks, editing, onToggleEdit, autoFocusLink }: CardProps) {
  const update = useUpdateCoach();
  const del = useDeleteCoach();
  const addTeamCoach = useCreateTeamCoach();
  const delTeamCoach = useDeleteTeamCoach();
  const addPlayer = useCreateCoachPlayer();
  const delPlayer = useDeleteCoachPlayer();

  const [first, setFirst] = useState(coach.firstName);
  const [last, setLast] = useState(coach.lastName);
  const [email, setEmail] = useState(coach.email ?? "");
  const [linkTeam, setLinkTeam] = useState("");
  const [linkRole, setLinkRole] = useState<TeamCoachRole | "PLAYER">("MAIN");
  const [confirmDelete, setConfirmDelete] = useState(false);
  // P3-16 — l'impact vient du serveur.
  const coachImpact = useDeletionImpact("coach", confirmDelete ? coach.id : null);

  const firstTeam = teams[0]?.id ?? "";
  const addLink = () => {
    const teamId = linkTeam || firstTeam;
    if ("" === teamId) {
      return;
    }
    if ("PLAYER" === linkRole) {
      addPlayer.mutate({ teamId, coachId: coach.id, isActive: true });
    } else {
      addTeamCoach.mutate({ teamId, coachId: coach.id, role: linkRole });
    }
  };

  const actions = (
    <div className="flex shrink-0 items-center gap-1">
      <Button size="sm" variant={editing ? "outline" : "ghost"} aria-label={editing ? "Terminer l'édition" : "Éditer le coach"} onClick={onToggleEdit}>
        {editing ? <Check className="size-4" /> : <Pencil className="size-4" />}
        {editing ? "Terminé" : "Éditer"}
      </Button>
      <Button size="icon" variant="ghost" className="size-8 text-destructive" aria-label="Supprimer le coach" onClick={() => setConfirmDelete(true)}>
        <Trash2 className="size-4" />
      </Button>
    </div>
  );

  return (
    <div data-coach-id={coach.id} className="rounded-lg border border-border bg-card p-3">
      {editing ? (
        <div className="flex flex-wrap items-center gap-2">
          <Input
            aria-label="Prénom"
            className="w-32"
            value={first}
            onChange={(e) => setFirst(e.target.value)}
            onBlur={() => first.trim() && first !== coach.firstName && update.mutate({ id: coach.id, body: payload(coach, { firstName: first.trim() }) })}
          />
          <Input
            aria-label="Nom"
            className="w-32"
            value={last}
            onChange={(e) => setLast(e.target.value)}
            onBlur={() => last !== coach.lastName && update.mutate({ id: coach.id, body: payload(coach, { lastName: last }) })}
          />
          {/* #10 C2 — email éditable ici (préparation C3 : envoi automatique du lien). Sans effet en C2. */}
          <Input
            type="email"
            aria-label="Email"
            placeholder="email (optionnel)"
            className="w-52"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            onBlur={() => email.trim() !== (coach.email ?? "") && update.mutate({ id: coach.id, body: payload(coach, { email: email.trim() || null }) })}
          />
          <label className="flex items-center gap-1 text-xs text-muted-foreground">
            <input type="checkbox" checked={coach.isEmployee} onChange={(e) => update.mutate({ id: coach.id, body: payload(coach, { isEmployee: e.target.checked }) })} />
            Salarié
          </label>
          {/* P2-53 RMM-8 — le statut véhiculé choisit le barème de trajet (voiture/à pied) appliqué
              aux enchaînements du coach. Défaut décoché. Aide PERSISTANTE (pas un tooltip : ce
              public ne survole pas — passe de design 2026-08-26). */}
          <span className="flex items-center gap-1 text-xs text-muted-foreground">
            <label className="flex items-center gap-1">
              <input
                type="checkbox"
                aria-describedby={`vehicled-help-${coach.id}`}
                checked={coach.isVehicled}
                onChange={(e) => update.mutate({ id: coach.id, body: payload(coach, { isVehicled: e.target.checked }) })}
              />
              Véhiculé
            </label>
            <span id={`vehicled-help-${coach.id}`} className="text-muted-foreground">
              (trajet en voiture, à vélo sinon)
            </span>
          </span>
          {/* P4-51 — le plafond de COMPTE : « peu importe quels jours, pas plus de N par
              semaine ». Distinct d'une indisponibilité (qui dit QUELS jours, dans
              Contraintes). Vide = pas de plafond. Le solveur le traite en PRÉFÉRÉ : il
              regroupe quand il peut, ne sacrifie jamais une séance, et le récap nomme le
              dépassement sinon. */}
          <label className="flex items-center gap-1 text-xs text-muted-foreground" title="Nombre maximum de jours au club par semaine — les séances sont regroupées quand c'est possible, et le récap signale si ce n'est pas le cas. Vide = pas de plafond.">
            Max
            <Input
              type="number"
              min={1}
              max={6}
              aria-label="Jours maximum par semaine"
              className="w-16"
              value={coach.maxDaysOverride ?? ""}
              onChange={(e) => {
                const raw = e.target.value;
                // ⚠ Vidé → 0, pas null : le PUT est PARTIEL côté serveur (null = « inchangé »),
                // donc null ne peut pas porter le retrait. 0 est la sentinelle « retirer ».
                const parsed = "" === raw ? 0 : Number(raw);
                if (0 !== parsed && (!Number.isInteger(parsed) || parsed < 1 || parsed > 6)) {
                  return; // hors bornes : on n'envoie rien, le champ reste piloté par le serveur
                }
                update.mutate({ id: coach.id, body: payload(coach, { maxDaysOverride: parsed }) });
              }}
            />
            j/sem
          </label>
          <div className="ml-auto">{actions}</div>
        </div>
      ) : (
        // Read-only: everything on a single line — name, salarié, team links, actions.
        <div className="flex items-center gap-2">
          <span className="whitespace-nowrap text-sm font-medium">{`${coach.firstName} ${coach.lastName}`.trim()}</span>
          {coach.isEmployee ? (
            <StatusPill variant="accent" className="shrink-0">
              Salarié
            </StatusPill>
          ) : null}
          {null !== coach.maxDaysOverride ? (
            <StatusPill variant="accent" className="shrink-0" title="Plafond préféré : les séances sont regroupées quand c'est possible, le récap signale sinon.">
              ≤ {coach.maxDaysOverride} j/sem
            </StatusPill>
          ) : null}
          {/* eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- région défilante nommée, atteignable au clavier (WCAG 2.1.1, A11Y-26 résidu) */}
          <div aria-label="Rattachements aux équipes" tabIndex={0} className="flex min-w-0 flex-1 items-center gap-1.5 overflow-x-auto">
            {coachLinks.map((link) => (
              <span key={link.id} className="whitespace-nowrap rounded-full bg-accent/15 px-2 py-0.5 text-xs">
                {teamName.get(link.teamId) ?? "?"} · {link.role === "MAIN" ? "coach" : "adjoint"}
              </span>
            ))}
            {playerLinks.map((link) => (
              <span key={link.id} className="whitespace-nowrap rounded-full border border-border px-2 py-0.5 text-xs">
                {teamName.get(link.teamId) ?? "?"} · joueur
              </span>
            ))}
          </div>
          {actions}
        </div>
      )}

      <DeleteConfirm
        open={confirmDelete}
        entityName={`${coach.firstName} ${coach.lastName}`.trim()}
        // P3-16 : le serveur compte — l'écran ne voit ni les contraintes ni les séances.
        impact={coachImpact.data ?? undefined}
        impactLoading={coachImpact.isPending && confirmDelete}
        impactFailed={coachImpact.isError}
        onConfirm={() => {
          del.mutate(coach.id);
          setConfirmDelete(false);
        }}
        onCancel={() => setConfirmDelete(false)}
      />

      {editing ? (
        <>
          {coachLinks.length > 0 || playerLinks.length > 0 ? (
            <div className="mt-2 flex flex-wrap gap-1.5">
              {coachLinks.map((link) => (
                <span key={link.id} className="flex items-center gap-1 rounded-full bg-accent/15 px-2 py-0.5 text-xs">
                  {teamName.get(link.teamId) ?? "?"} · {link.role === "MAIN" ? "coach" : "adjoint"}
                  <button type="button" aria-label={`Retirer de l'équipe ${teamName.get(link.teamId) ?? "?"} (${link.role === "MAIN" ? "coach" : "adjoint"})`} className="rounded p-1.5 -m-1.5" onClick={() => delTeamCoach.mutate(link.id)}>
                    <X className="size-3" />
                  </button>
                </span>
              ))}
              {playerLinks.map((link) => (
                <span key={link.id} className="flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-xs">
                  {teamName.get(link.teamId) ?? "?"} · joueur
                  <button type="button" aria-label={`Retirer de l'équipe ${teamName.get(link.teamId) ?? "?"} (joueur)`} className="rounded p-1.5 -m-1.5" onClick={() => delPlayer.mutate(link.id)}>
                    <X className="size-3" />
                  </button>
                </span>
              ))}
            </div>
          ) : null}

          <div className="mt-2 flex flex-wrap items-center gap-2">
            {/* Focus d'ouverture du coach fraîchement créé (piloté par le parent) : le 1ᵉʳ geste
                attendu est de lier une équipe — d'où l'autoFocus, ciblé et non permanent. */}
            {/* eslint-disable-next-line jsx-a11y/no-autofocus */}
            <TeamSelect autoFocus={autoFocusLink} aria-label="Équipe" wrapperClassName="w-40" teams={teams} tiers={tiers} value={linkTeam || firstTeam} onValueChange={setLinkTeam} />
            <Select aria-label="Rôle" wrapperClassName="w-28" value={linkRole} onChange={(e) => setLinkRole(e.target.value as TeamCoachRole | "PLAYER")}>
              <option value="MAIN">Coach</option>
              <option value="ASSISTANT">Adjoint</option>
              <option value="PLAYER">Joueur</option>
            </Select>
            <Button size="sm" variant="outline" className="ml-auto" onClick={addLink} disabled={0 === teams.length}>
              <Plus className="size-4" />
              Lier
            </Button>
          </div>
        </>
      ) : null}
    </div>
  );
}

export function CoachesStep() {
  const periodMode = useWizardStore((s) => s.mode === "period");
  if (periodMode) {
    return <ReadonlyCoaches />;
  }
  return <CoachesEditor />;
}

function CoachesEditor() {
  const { data: coaches = [] } = useWizardCoaches();
  const { data: teams = [] } = useWizardTeams();
  const { data: tiers = [] } = usePriorityTiers();
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  // P4-269 — le radar « personne à deux endroits » du planning EN VIGUEUR : compléter le modèle
  // sans régénérer (lier un coach/joueur) peut créer un vrai conflit sur des séances déjà placées.
  // L'encart rafraîchit après chaque mutation de lien (clé invalidée par les mutations).
  const { data: placedConflicts } = usePlacedConflicts();
  const create = useCreateCoach();

  const [first, setFirst] = useState("");
  const [last, setLast] = useState("");
  const [employee, setEmployee] = useState(false);
  const [firstError, setFirstError] = useState(false);
  const [search, setSearch] = useState("");
  const firstRef = useRef<HTMLInputElement>(null);
  // Édition contrôlée par le parent (une seule carte à la fois), avec la SECTION figée au
  // démarrage de l'édition : lier une personne comme joueur pendant l'édition ne la fait pas
  // sauter de « Bénévoles » à « Coachs-joueurs » sous le curseur — le reclassement attend
  // « Terminé ». `justCreatedId` = coach fraîchement créé, ouvert d'office et défilé jusqu'à lui.
  const [editingCoachId, setEditingCoachId] = useState<string | null>(null);
  const [editingGroup, setEditingGroup] = useState<CoachGroup | null>(null);
  const [justCreatedId, setJustCreatedId] = useState<string | null>(null);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  // Same taxonomy as the recap/constraint picker (ranking.ts): Salariés / Coachs-joueurs / Bénévoles.
  const coachPlayerIds = new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId));

  // Défilement doux jusqu'à la carte du coach qu'on vient de créer, une fois qu'elle est rendue
  // (l'invalidation react-query la fait apparaître). Même garde que ConstraintsStep : `scrollIntoView`
  // n'existe pas en jsdom, d'où l'appel optionnel.
  useEffect(() => {
    if (null == justCreatedId || !coaches.some((c) => c.id === justCreatedId)) {
      return;
    }
    const id = justCreatedId;
    requestAnimationFrame(() => {
      document.querySelector(`[data-coach-id="${id}"]`)?.scrollIntoView?.({ block: "center", behavior: "smooth" });
      // Différé (hors corps d'effet) : consommer le drapeau une fois le défilement lancé, sans
      // cascade de rendu synchrone.
      setJustCreatedId(null);
    });
  }, [justCreatedId, coaches]);

  const toggleEdit = (coach: Coach) => {
    if (editingCoachId === coach.id) {
      // « Terminé » — reclassement : on rend la carte à sa section EN VIGUEUR.
      setEditingCoachId(null);
      setEditingGroup(null);
    } else {
      setEditingCoachId(coach.id);
      setEditingGroup(coachGroupOf(coach, coachPlayerIds));
    }
  };

  // Recherche par nom (insensible à la casse et aux accents) ; la carte en édition reste visible
  // même si elle ne correspond plus au filtre.
  const query = stripDiacritics(search.trim().toLowerCase());
  const matchesSearch = (coach: Coach) =>
    "" === query || coach.id === editingCoachId || stripDiacritics(coachFullName(coach).toLowerCase()).includes(query);
  const visible = coaches.filter(matchesSearch);

  // Sections en vigueur, MAIS la carte en cours d'édition reste dans la section où elle était au
  // démarrage (`editingGroup`) tant que « Terminé » n'a pas été cliqué.
  const groups = groupedCoaches(visible, coachPlayerIds);
  if (null != editingCoachId && null != editingGroup) {
    const editing = visible.find((c) => c.id === editingCoachId);
    if (editing && coachGroupOf(editing, coachPlayerIds) !== editingGroup) {
      for (const g of ["salaried", "player", "other"] as const) {
        groups[g] = groups[g].filter((c) => c.id !== editingCoachId);
      }
      groups[editingGroup] = [...groups[editingGroup], editing].sort((a, b) => coachFullName(a).localeCompare(coachFullName(b), "fr"));
    }
  }

  const add = (event: FormEvent) => {
    event.preventDefault();
    if ("" === first.trim()) {
      // Silent no-op was frustrating: surface why + jump to the empty field.
      setFirstError(true);
      firstRef.current?.focus();
      return;
    }
    setFirstError(false);
    create.mutate(
      { firstName: first.trim(), lastName: last.trim() || null, isEmployee: employee, isActive: true },
      {
        onSuccess: (created) => {
          // Un coach créé s'ouvre directement en édition, figé dans sa section de naissance
          // (aucun lien encore → salarié ou bénévole), et l'écran défile jusqu'à lui.
          setEditingCoachId(created.id);
          setEditingGroup(coachGroupOf(created, coachPlayerIds));
          setJustCreatedId(created.id);
        },
      },
    );
    setFirst("");
    setLast("");
    setEmployee(false);
    // Back to the first-name field for the next coach.
    firstRef.current?.focus();
  };

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">Ajoutez vos coachs, marquez les salariés, et liez-les à des équipes (coach, adjoint) ou aux équipes où ils jouent.</p>

      {/* P4-269 — si un lien met une personne sur deux séances déjà placées qui se chevauchent
          (planning en vigueur), on le SIGNALE ici, rafraîchi après chaque mutation de lien. */}
      <PlacedConflictsNotice conflicts={placedConflicts?.conflicts ?? []} role="status" className="mb-4" />

      <form onSubmit={add} className="mb-2 flex flex-wrap items-center gap-2 rounded-lg border border-border bg-card p-3">
        <Input
          ref={firstRef}
          aria-label="Prénom"
          aria-invalid={firstError}
          aria-describedby={firstError ? "coach-first-error" : undefined}
          placeholder="Prénom"
          className={`h-9 w-40 ${firstError ? "border-destructive focus-visible:ring-destructive" : ""}`}
          value={first}
          onChange={(e) => {
            setFirst(e.target.value);
            if (firstError) {
              setFirstError(false);
            }
          }}
        />
        <Input aria-label="Nom" placeholder="Nom" className="h-9 w-40" value={last} onChange={(e) => setLast(e.target.value)} />
        <label className="flex items-center gap-1 text-sm text-muted-foreground">
          <input type="checkbox" checked={employee} onChange={(e) => setEmployee(e.target.checked)} />
          Salarié
        </label>
        <Button type="submit" size="icon" className="ml-auto size-8" disabled={create.isPending} title="Ajouter le coach" aria-label="Ajouter le coach">
          <Plus className="size-4" />
        </Button>
      </form>

      {/* AUD-A11Y-13 — `aria-invalid` disait « ce champ est fautif » sans jamais dire
          POURQUOI : le message existait à côté, non relié. Un lecteur d'écran l'annonce
          une fois par `role="alert"` (interruption), puis le champ redevient muet — on
          revient dessus, on sait que c'est faux, on ne sait plus ce qu'on doit corriger.
          `aria-describedby` le rattache : le motif se relit avec le champ, à volonté. */}
      {firstError ? (
        <p id="coach-first-error" role="alert" className="mb-4 text-sm text-destructive">
          Indiquez au moins le prénom du coach avant de l'ajouter.
        </p>
      ) : null}

      {0 === coaches.length ? (
        <EmptyHint>Aucun coach pour le moment.</EmptyHint>
      ) : (
        <>
          <Input
            type="search"
            aria-label="Rechercher un coach"
            placeholder="Rechercher un coach…"
            className="mb-3 h-9 w-full sm:w-72"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {0 === visible.length ? (
            <EmptyHint>Aucun coach ne correspond.</EmptyHint>
          ) : (
            <div className="flex flex-col gap-4">
              {(
                [
                  ["Salariés", groups.salaried],
                  ["Coachs-joueurs", groups.player],
                  ["Bénévoles", groups.other],
                ] as const
              ).map(([label, list]) =>
                list.length > 0 ? (
                  <section key={label}>
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</h3>
                    <div className="flex flex-col gap-3">
                      {list.map((coach) => (
                        <CoachCard
                          key={coach.id}
                          coach={coach}
                          teams={teams}
                          tiers={tiers}
                          teamName={teamName}
                          coachLinks={teamCoaches.filter((l) => l.coachId === coach.id)}
                          playerLinks={coachPlayers.filter((l) => l.coachId === coach.id)}
                          editing={editingCoachId === coach.id}
                          onToggleEdit={() => toggleEdit(coach)}
                          autoFocusLink={justCreatedId === coach.id && teams.length > 0}
                        />
                      ))}
                    </div>
                  </section>
                ) : null,
              )}
            </div>
          )}
        </>
      )}
    </div>
  );
}

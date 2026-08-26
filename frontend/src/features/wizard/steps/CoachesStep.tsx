import { Check, Pencil, Plus, Trash2, X } from "lucide-react";
import { type FormEvent, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";

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
import { groupedCoaches } from "../lib/ranking";
import { useWizardStore } from "../store";
import { ReadonlyCoaches } from "./StructureSummary";

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
}

function CoachCard({ coach, teams, tiers, teamName, coachLinks, playerLinks }: CardProps) {
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
  // Cards are read-only by default; « Éditer » reveals the fields (batch item 4).
  const [editing, setEditing] = useState(false);

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
      <Button size="sm" variant={editing ? "outline" : "ghost"} className="h-8" aria-label={editing ? "Terminer l'édition" : "Éditer le coach"} onClick={() => setEditing((e) => !e)}>
        {editing ? <Check className="size-4" /> : <Pencil className="size-4" />}
        {editing ? "Terminé" : "Éditer"}
      </Button>
      <Button size="icon" variant="ghost" className="size-8 text-destructive" aria-label="Supprimer le coach" onClick={() => setConfirmDelete(true)}>
        <Trash2 className="size-4" />
      </Button>
    </div>
  );

  return (
    <div className="rounded-lg border border-border bg-card p-3">
      {editing ? (
        <div className="flex flex-wrap items-center gap-2">
          <Input
            aria-label="Prénom"
            className="h-8 w-32"
            value={first}
            onChange={(e) => setFirst(e.target.value)}
            onBlur={() => first.trim() && first !== coach.firstName && update.mutate({ id: coach.id, body: payload(coach, { firstName: first.trim() }) })}
          />
          <Input
            aria-label="Nom"
            className="h-8 w-32"
            value={last}
            onChange={(e) => setLast(e.target.value)}
            onBlur={() => last !== coach.lastName && update.mutate({ id: coach.id, body: payload(coach, { lastName: last }) })}
          />
          {/* #10 C2 — email éditable ici (préparation C3 : envoi automatique du lien). Sans effet en C2. */}
          <Input
            type="email"
            aria-label="Email"
            placeholder="email (optionnel)"
            className="h-8 w-52"
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
              (trajet en voiture, à pied sinon)
            </span>
          </span>
          {/* P4-51 — le plafond de COMPTE : « peu importe quels jours, pas plus de N par
              semaine ». Distinct d'une indisponibilité (qui dit QUELS jours, dans
              Contraintes). Vide = pas de plafond. Le solveur le traite en PRÉFÉRÉ : il
              regroupe quand il peut, ne sacrifie jamais une séance, et le récap nomme le
              dépassement sinon. */}
          <label className="flex items-center gap-1 text-xs text-muted-foreground" title="Nombre maximum de jours au club par semaine — le solveur regroupe les séances quand c'est possible, et le récap signale s'il n'y arrive pas. Vide = pas de plafond.">
            Max
            <Input
              type="number"
              min={1}
              max={6}
              aria-label="Jours maximum par semaine"
              className="h-8 w-16"
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
          {coach.isEmployee ? <span className="shrink-0 rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent">Salarié</span> : null}
          {null !== coach.maxDaysOverride ? (
            <span className="shrink-0 rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent" title="Plafond préféré : le solveur regroupe les séances quand c'est possible, le récap signale sinon.">
              ≤ {coach.maxDaysOverride} j/sem
            </span>
          ) : null}
          <div className="flex min-w-0 flex-1 items-center gap-1.5 overflow-x-auto">
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
                  <button type="button" aria-label="Retirer" className="rounded p-1.5 -m-1.5" onClick={() => delTeamCoach.mutate(link.id)}>
                    <X className="size-3" />
                  </button>
                </span>
              ))}
              {playerLinks.map((link) => (
                <span key={link.id} className="flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-xs">
                  {teamName.get(link.teamId) ?? "?"} · joueur
                  <button type="button" aria-label="Retirer" className="rounded p-1.5 -m-1.5" onClick={() => delPlayer.mutate(link.id)}>
                    <X className="size-3" />
                  </button>
                </span>
              ))}
            </div>
          ) : null}

          <div className="mt-2 flex flex-wrap items-center gap-2">
            <TeamSelect aria-label="Équipe" className="h-8 w-40" teams={teams} tiers={tiers} value={linkTeam || firstTeam} onChange={(e) => setLinkTeam(e.target.value)} />
            <Select aria-label="Rôle" className="h-8 w-28" value={linkRole} onChange={(e) => setLinkRole(e.target.value as TeamCoachRole | "PLAYER")}>
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
  const create = useCreateCoach();

  const [first, setFirst] = useState("");
  const [last, setLast] = useState("");
  const [employee, setEmployee] = useState(false);
  const [firstError, setFirstError] = useState(false);
  const firstRef = useRef<HTMLInputElement>(null);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  // Same taxonomy as the recap/constraint picker (ranking.ts): Salariés / Coachs-joueurs / Bénévoles.
  const coachPlayerIds = new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId));
  const groups = groupedCoaches(coaches, coachPlayerIds);

  const add = (event: FormEvent) => {
    event.preventDefault();
    if ("" === first.trim()) {
      // Silent no-op was frustrating: surface why + jump to the empty field.
      setFirstError(true);
      firstRef.current?.focus();
      return;
    }
    setFirstError(false);
    create.mutate({ firstName: first.trim(), lastName: last.trim() || null, isEmployee: employee, isActive: true });
    setFirst("");
    setLast("");
    setEmployee(false);
    // Back to the first-name field for the next coach.
    firstRef.current?.focus();
  };

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">Ajoutez vos coachs, marquez les salariés, et liez-les à des équipes (coach, adjoint) ou aux équipes où ils jouent.</p>

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
                    />
                  ))}
                </div>
              </section>
            ) : null,
          )}
        </div>
      )}
    </div>
  );
}

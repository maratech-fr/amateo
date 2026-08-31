import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

// P2-25 — SlotDetail porte désormais un lien « Corriger cette contrainte » (WizardStepLink →
// useMe pour le verrou du mode guidé). Club établi (version finie) → lien actif, pas verrouillé.
vi.mock("@/shared/session/queries", () => ({
  useMe: () => ({ data: { seasonPlan: { hasFinishedVersion: true } } }),
}));

import { buildTagTeamIds } from "./lib/applicableConstraints";
import type { Constraint, LockOrigin, Slot, Venue } from "./api";
import type { GridCell } from "./lib/grid";
import { SlotDetail, type MoveFeedback } from "./SlotDetail";

const slot = (over: Partial<Slot> = {}): Slot => ({
  id: "s1",
  scheduleId: "sch1",
  teamId: "team-A",
  venueId: "venue-1",
  coachId: "coach-X",
  dayOfWeek: 2,
  startTime: "18:00:00",
  durationMinutes: 90,
  lockLevel: "HARD",
  lockOrigin: "RESERVATION",
  ...over,
});

const cell = (locked: boolean): GridCell => ({
  key: "k",
  slotId: "s1",
  gridColumn: 1,
  gridRowStart: 1,
  gridRowSpan: 1,
  lane: 0,
  laneCount: 1,
  primaryLabel: "",
  secondaryLabel: "",
  roleTag: null,
  teamLabel: "U11",
  venueLabel: "Gymnase Alpha",
  venueId: "venue-1",
  venueColor: null,
  coachLabel: "Jean Dupont",
  day: 2,
  startLabel: "18:00",
  endLabel: "19:30",
  locked,
  lockOrigin: locked ? "MANUAL" : null,
  groupLabel: null,
  members: [],
});

const constraint = (over: Partial<Constraint>): Constraint => ({
  id: "c1",
  name: "Contrainte",
  scope: "CLUB",
  scopeTargetId: null,
  family: "TIME",
  ruleType: "HARD",
  config: {},
  isActive: true,
  ...over,
});

function renderDetail(
  over: {
    slot?: Partial<Slot>;
    constraints?: Constraint[];
    tagTeamIds?: ReadonlyMap<string, ReadonlySet<string>>;
    moveState?: MoveFeedback;
    venues?: Venue[];
    teamName?: (id: string) => string | undefined;
    coachName?: (id: string) => string | undefined;
    categoryLabel?: string;
    cell?: Partial<ReturnType<typeof cell>>;
    armed?: boolean;
    onArmMove?: () => void;
    groupSession?: { memberCount: number } | null;
  } = {},
) {
  const s = slot(over.slot);
  renderWithProviders(
    <SlotDetail
      cell={{ ...cell(s.lockLevel !== "NONE"), ...over.cell }}
      slot={s}
      venues={over.venues ?? []}
      categoryLabel={over.categoryLabel ?? "U11"}
      constraints={over.constraints ?? []}
      tagTeamIds={over.tagTeamIds}
      teamName={over.teamName}
      coachName={over.coachName}
      busy={false}
      moveState={over.moveState}
      armed={over.armed}
      groupSession={over.groupSession}
      onClose={vi.fn()}
      onToggleLock={vi.fn()}
      onArmMove={over.onArmMove ?? vi.fn()}
    />,
  );
}

/** Le panneau des contraintes est REPLIÉ par défaut — on l'ouvre pour lire son contenu. */
const openConstraints = () => userEvent.click(screen.getByRole("button", { name: /Contraintes applicables/ }));

describe("SlotDetail — sous-ligne compacte (B1)", () => {
  it("résume catégorie · durée · Coach sur une seule ligne discrète, sans labels", () => {
    renderDetail({ slot: { durationMinutes: 90 } });
    // Une seule ligne, séparateurs « · », aucun libellé sauf le préfixe « Coach ».
    expect(screen.getByText("U11 · 90 min · Coach Jean Dupont")).toBeInTheDocument();
    // Plus de lignes étiquetées « Catégorie »/« Durée ».
    expect(screen.queryByText("Catégorie")).not.toBeInTheDocument();
    expect(screen.queryByText("Durée")).not.toBeInTheDocument();
  });

  it("omet un segment vide sans « · » orphelin", () => {
    renderDetail({ slot: { durationMinutes: 60 }, categoryLabel: "" });
    // Catégorie vide → la ligne commence à la durée, jamais par un « · » orphelin.
    expect(screen.getByText("60 min · Coach Jean Dupont")).toBeInTheDocument();
  });

  it("remplace le nom par une croix rouge quand l'équipe n'a pas de coach", () => {
    renderDetail({ cell: { coachLabel: "Sans coach" } });
    // Décision fondateur 2026-08-16 : jamais « Coach Sans coach » — le préfixe reste,
    // le nom devient une croix rouge, lisible aussi au lecteur d'écran.
    expect(screen.getByLabelText("Sans coach")).toBeInTheDocument();
    expect(screen.queryByText(/Sans coach/)).not.toBeInTheDocument();
  });
});

describe("SlotDetail — origine du verrou (F1)", () => {
  it.each<[LockOrigin, string]>([
    ["RESERVATION", "Réservation gymnase"],
    ["MANUAL", "Épinglé manuellement"],
    ["UNKNOWN", "Verrouillé — origine inconnue"],
  ])("affiche l'origine %s en clair, sans code d'enum", (origin, label) => {
    renderDetail({ slot: { lockOrigin: origin } });
    // Libellé EXACT, pas une correspondance approchée : c'est le texte que le
    // gestionnaire lit, et sa formulation est le sujet du test (voir le cas UNKNOWN
    // ci-dessous). Un `new RegExp(label, "i")` relâchait l'assertion — et semgrep le
    // refusait à raison (ReDoS sur regex construite dynamiquement).
    expect(screen.getByText(label)).toBeInTheDocument();
    // Jamais le code brut de l'enum à l'écran.
    expect(screen.queryByText(origin)).not.toBeInTheDocument();
  });

  it("UNKNOWN se lit comme une IGNORANCE, pas comme une absence de verrou", () => {
    renderDetail({ slot: { lockOrigin: "UNKNOWN" } });
    // Le mot « verrouillé » DOIT apparaître (label ET explication) : le créneau est bien
    // bloqué, on ne sait juste pas d'où vient le verrou.
    expect(screen.getAllByText(/verrouill/i).length).toBeGreaterThan(0);
  });

  it("n'affiche aucune origine quand le créneau n'est pas verrouillé", () => {
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null } });
    expect(screen.queryByText(/Réservation gymnase|Épinglé manuellement|origine inconnue/i)).not.toBeInTheDocument();
  });
});

describe("SlotDetail — contraintes applicables (F1)", () => {
  it("liste les contraintes qui s'appliquent au créneau, pas les autres", async () => {
    renderDetail({
      constraints: [
        constraint({ id: "mine", name: "Pas le lundi", scope: "TEAM", scopeTargetId: "team-A" }),
        constraint({ id: "other", name: "Autre équipe", scope: "TEAM", scopeTargetId: "team-B" }),
      ],
    });
    await openConstraints();
    expect(screen.getByText("Pas le lundi")).toBeInTheDocument();
    expect(screen.queryByText("Autre équipe")).not.toBeInTheDocument();
  });

  it("P2-25 — chaque contrainte applicable offre « Corriger » vers l'éditeur pré-rempli (from=planning)", async () => {
    renderDetail({ constraints: [constraint({ id: "cX", name: "Pas le lundi", scope: "TEAM", scopeTargetId: "team-A" })] });
    await openConstraints();
    const link = screen.getByRole("link", { name: /Corriger cette contrainte/ });
    const href = link.getAttribute("href") ?? "";
    expect(href).toContain("step=constraints");
    expect(href).toContain("edit=cX");
    expect(href).toContain("from=planning");
  });

  it("le dit franchement quand aucune contrainte ne s'applique", async () => {
    renderDetail({ constraints: [] });
    await openConstraints();
    expect(screen.getByText(/Aucune contrainte spécifique/i)).toBeInTheDocument();
  });

  it("est REPLIÉ par défaut, avec le NOMBRE de contraintes visible sans ouvrir", () => {
    renderDetail({
      constraints: [
        constraint({ id: "a", name: "Pas le lundi", scope: "TEAM", scopeTargetId: "team-A" }),
        constraint({ id: "b", name: "Toute la saison", scope: "CLUB", config: {} }),
      ],
    });
    // Le compte est là replié…
    expect(screen.getByRole("button", { name: /Contraintes applicables \(2\)/ })).toBeInTheDocument();
    // …mais le détail n'est PAS rendu tant qu'on n'ouvre pas (n'agrandit pas l'aside).
    expect(screen.queryByText("Pas le lundi")).not.toBeInTheDocument();
  });

  it("sépare « Cette équipe » de « Tout le club » une fois déplié", async () => {
    renderDetail({
      constraints: [
        constraint({ id: "team", name: "Pas le lundi", scope: "TEAM", scopeTargetId: "team-A" }),
        constraint({ id: "club", name: "Toute la saison", scope: "CLUB", config: {} }),
      ],
    });
    await openConstraints();
    expect(screen.getByText("Cette équipe")).toBeInTheDocument();
    expect(screen.getByText("Tout le club")).toBeInTheDocument();
    expect(screen.getByText("Pas le lundi")).toBeInTheDocument();
    expect(screen.getByText("Toute la saison")).toBeInTheDocument();
  });

  it("une contrainte CLUB ciblant un tag ne s'affiche que sur une équipe TAGUÉE, côté « Cette équipe »", async () => {
    // team-A porte REGIONAL, pas team-B. Miroir de l'éclatement CLUB+targetTag du backend.
    const tagTeamIds = buildTagTeamIds([{ id: "t", name: "REGIONAL" }], [{ teamId: "team-A", tagId: "t" }]);
    const reg = constraint({ id: "reg", name: "Préfèrent Matéo", scope: "CLUB", config: { targetTag: "REGIONAL" } });

    renderDetail({ slot: { teamId: "team-A" }, constraints: [reg], tagTeamIds });
    await openConstraints();
    expect(screen.getByText("Préfèrent Matéo")).toBeInTheDocument();
    // Elle vise une équipe précise → jamais dans « Tout le club ».
    expect(screen.queryByText("Tout le club")).not.toBeInTheDocument();
    expect(screen.getByText("Cette équipe")).toBeInTheDocument();
  });

  it("la même contrainte CLUB+tag est ABSENTE sur une équipe non taguée", () => {
    const tagTeamIds = buildTagTeamIds([{ id: "t", name: "REGIONAL" }], [{ teamId: "team-A", tagId: "t" }]);
    const reg = constraint({ id: "reg", name: "Préfèrent Matéo", scope: "CLUB", config: { targetTag: "REGIONAL" } });

    renderDetail({ slot: { teamId: "team-B" }, constraints: [reg], tagTeamIds });
    // Rien à ouvrir : le compte est 0, et le nom n'apparaît nulle part.
    expect(screen.getByRole("button", { name: /Contraintes applicables \(0\)/ })).toBeInTheDocument();
    expect(screen.queryByText("Préfèrent Matéo")).not.toBeInTheDocument();
  });
});

describe("SlotDetail — le panneau dit ce que la règle FAIT, pas seulement son nom", () => {
  const mateo: Venue = { id: "v-mateo", name: "Matéo", color: null };

  it("une DAY forbiddenDays:[6] mal NOMMÉE affiche quand même « samedi » — le cas fondateur", async () => {
    // Une règle « samedi interdit, tout le club » portant un nom qui décrit autre chose : le
    // fondateur lisait ce nom sur un créneau U11 et concluait que l'app mentait.
    renderDetail({
      constraints: [constraint({ id: "d", name: "SM2 au moins 1 seance a Mateo", scope: "CLUB", family: "DAY", config: { forbiddenDays: [6] } })],
    });
    await openConstraints();
    // Ce que la règle FAIT, dérivé de la config — vérifiable sans confiance.
    expect(screen.getByText(/samedi/i)).toBeInTheDocument();
    // B2 — une seule ligne par contrainte : le nom libre trompeur ne double plus la
    // description dérivée (il ne s'affiche que faute de description fidèle).
    expect(screen.queryByText("SM2 au moins 1 seance a Mateo")).not.toBeInTheDocument();
  });

  it("nomme le gymnase pour minAtVenueId et preferredVenueId", async () => {
    renderDetail({
      slot: { teamId: "team-A" },
      venues: [mateo],
      constraints: [
        constraint({ id: "min", name: "peu importe", scope: "TEAM", scopeTargetId: "team-A", family: "FACILITY", config: { minAtVenueId: "v-mateo", minAtVenueCount: 1 } }),
        constraint({ id: "pref", name: "peu importe non plus", scope: "CLUB", family: "FACILITY", config: { preferredVenueId: "v-mateo" } }),
      ],
    });
    await openConstraints();
    // La règle « min » vise team-A mais aucun résolveur d'équipe n'est fourni ici → prédicat seul.
    expect(screen.getByText("Au moins 1 séance à Matéo")).toBeInTheDocument();
    // La règle « pref » est CLUB nu → cible « Toutes les équipes · … ».
    expect(screen.getByText("Toutes les équipes · préfère Matéo")).toBeInTheDocument();
  });

  it("P4-94 / B2 — la ligne UNIQUE NOMME la cible (équipe résolue), sans doubler le nom libre", async () => {
    renderDetail({
      slot: { teamId: "team-A" },
      venues: [mateo],
      teamName: (id) => (id === "team-A" ? "U11 A" : undefined),
      constraints: [
        constraint({ id: "min", name: "SM2 au moins 1 seance a Mateo", scope: "TEAM", scopeTargetId: "team-A", family: "FACILITY", config: { minAtVenueId: "v-mateo", minAtVenueCount: 1 } }),
      ],
    });
    await openConstraints();
    // Dérivé, cible en tête (le bon objet corrigeable) — désormais la SEULE ligne.
    expect(screen.getByText("U11 A · au moins 1 séance à Matéo")).toBeInTheDocument();
    // B2 — le nom LIBRE ne double plus la description : une seule ligne par contrainte.
    expect(screen.queryByText("SM2 au moins 1 seance a Mateo")).not.toBeInTheDocument();
  });

  it("retombe sur le NOM SEUL pour une combinaison qu'on ne sait pas décrire (pas d'invention)", async () => {
    renderDetail({
      constraints: [constraint({ id: "x", name: "Règle exotique", scope: "CLUB", family: "DAY", config: { mysteryKey: [1, 2] } })],
    });
    await openConstraints();
    // Le nom est là, une seule fois (pas de description approximative doublée).
    expect(screen.getAllByText("Règle exotique")).toHaveLength(1);
  });
});

describe("SlotDetail — mode cible (P2-30 PR B, geste 1)", () => {
  it("« Déplacer » ARME le mode cible (ne rend plus le formulaire 3 champs)", async () => {
    const onArmMove = vi.fn();
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null }, onArmMove });

    await userEvent.click(screen.getByRole("button", { name: /Déplacer/ }));
    expect(onArmMove).toHaveBeenCalledTimes(1);
  });

  // D11 (décision fondateur) : le formulaire jour/heure/gymnase est SUPPRIMÉ — jamais utilisé,
  // pas intuitif. On le prouve par l'absence des trois champs.
  it("ne rend AUCUN des trois champs jour / heure / gymnase", () => {
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null } });
    expect(screen.queryByLabelText("Jour")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("Heure")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("Gymnase")).not.toBeInTheDocument();
  });

  it("armé : le bouton bascule sur une consigne de choix de cible et une aide Échap", () => {
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null }, armed: true });
    expect(screen.getByText(/Échap/i)).toBeInTheDocument();
  });
});

describe("SlotDetail — verdict du déplacement (F2b)", () => {
  it("affiche le refus AVEC ses motifs nommés, sans code de règle brut", () => {
    renderDetail({
      slot: { lockLevel: "NONE", lockOrigin: null },
      moveState: { status: "rejected", violations: [{ rule: "coach_double_booking", message: "le coach Dupont a déjà les U15 à 20h dans un autre gymnase." }] },
    });
    expect(screen.getByText(/coach Dupont a déjà les U15/i)).toBeInTheDocument();
    // Jamais le code machine de la règle à l'écran.
    expect(screen.queryByText("coach_double_booking")).not.toBeInTheDocument();
  });

  it("dit qu'une vérification est en cours pendant l'appel au moteur", () => {
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null }, moveState: { status: "pending" } });
    expect(screen.getByText(/Vérification/i)).toBeInTheDocument();
  });

  it("explique qu'une génération en cours empêche le déplacement", () => {
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null }, moveState: { status: "blocked" } });
    expect(screen.getByText(/génération/i)).toBeInTheDocument();
  });

  it("invite à réessayer quand le moteur n'a pas répondu", () => {
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null }, moveState: { status: "error" } });
    expect(screen.getByText(/réessay/i)).toBeInTheDocument();
  });
});

describe("SlotDetail — déplacer le GROUPE de mutualisation (P2-51 PR-6, D11)", () => {
  it("sur une séance de bloc, « Déplacer » devient « Déplacer le groupe » (le déplacement individuel n'est PAS proposé)", () => {
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null }, groupSession: { memberCount: 3 } });
    // Le bouton porte le geste de GROUPE, avec le compte de membres dans son nom accessible.
    expect(screen.getByRole("button", { name: "Déplacer le groupe, 3 équipes" })).toBeInTheDocument();
    // Le déplacement d'UNE seule équipe n'est plus offert (le moteur le refuserait).
    expect(screen.queryByRole("button", { name: "Déplacer" })).toBeNull();
    // La conséquence est annoncée AVANT le geste.
    expect(screen.getByText(/Déplace les 3 équipes du groupe ensemble/)).toBeInTheDocument();
  });

  it("armer le déplacement de groupe déclenche onArmMove et bascule le bouton sur la consigne de cible", () => {
    const onArmMove = vi.fn();
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null }, groupSession: { memberCount: 2 }, armed: true, onArmMove });
    expect(screen.getByRole("button", { name: /Choisir la case cible/ })).toBeInTheDocument();
    expect(screen.getByText(/déplacer tout le groupe/)).toBeInTheDocument();
  });

  it("un créneau ordinaire garde « Déplacer » (aucune régression)", () => {
    renderDetail({ slot: { lockLevel: "NONE", lockOrigin: null } });
    expect(screen.getByRole("button", { name: "Déplacer" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Déplacer le groupe/ })).toBeNull();
  });
});

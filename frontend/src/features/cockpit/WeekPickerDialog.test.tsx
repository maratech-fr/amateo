import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ComponentProps } from "react";
import { describe, expect, it, vi } from "vitest";

import { WeekPickerDialog } from "./WeekPickerDialog";

const mother = { title: "Barros en travaux", startDate: "2026-11-12", endDate: "2026-11-18" };

const weeks = [
  { startDate: "2026-11-09", endDate: "2026-11-15", monday: "2026-11-09" },
  { startDate: "2026-11-16", endDate: "2026-11-22", monday: "2026-11-16" },
];

describe("WeekPickerDialog (P2-5 E1)", () => {
  it("prechecks every segment and creates the picked ones", async () => {
    const user = userEvent.setup();
    const onPickSegments = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} onPickSegments={onPickSegments} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    // Incident 12→18 nov : les deux semaines sont ENTAMÉES par l'événement → deux segments de
    // taille 1, chacun précoché.
    const boxes = screen.getAllByRole("checkbox");
    expect(boxes).toHaveLength(2);
    boxes.forEach((b) => expect(b).toBeChecked());

    // Décocher le segment 2 → seul le segment 1 est créé (un enfant de taille 1).
    await user.click(boxes[1]);
    await user.click(screen.getByRole("button", { name: /^créer le planning$/i }));
    expect(onPickSegments).toHaveBeenCalledWith([{ weeks: [weeks[0]], startDate: "2026-11-09", endDate: "2026-11-15", monday: "2026-11-09", partial: true }]);
  });

  it("keeps the whole-block path available (founder decision)", async () => {
    const user = userEvent.setup();
    const onAdaptWhole = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} onPickSegments={vi.fn()} onAdaptWhole={onAdaptWhole} onClose={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: /d'un bloc/i }));
    expect(onAdaptWhole).toHaveBeenCalled();
  });

  it("disarms creation when nothing is picked", async () => {
    const user = userEvent.setup();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);
    for (const b of screen.getAllByRole("checkbox")) {
      await user.click(b);
    }
    expect(screen.getByRole("button", { name: /créer/i })).toBeDisabled();
  });
});

// P2-38 PR3 — un refus « une seule planification par fenêtre » (409 window_already_planned) sur la
// création de semaines s'AFFICHE dans le picker (proposition, pas toast fugace) et propose d'ouvrir
// le planning en conflit — jamais réécrit côté front (le message vient du serveur).
describe("WeekPickerDialog — refus de chevauchement (P2-38)", () => {
  const conflict = {
    message: "Ces dates sont déjà planifiées par « Vacances de Toussaint » (du 19 octobre 2026 au 2 novembre 2026). Modifiez ce planning existant ou supprimez-le. Vous pouvez aussi découper la période en semaines.",
    entryId: "conflict-entry-9",
  };

  it("affiche le message du serveur et propose d'ouvrir le planning en place, sur son entryId", async () => {
    const user = userEvent.setup();
    const onOpenConflict = vi.fn();
    render(
      <WeekPickerDialog
        title={mother.title}
        startDate={mother.startDate}
        endDate={mother.endDate}
        weeks={weeks}
        busy={false}
        onPickSegments={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
        conflict={conflict}
        onOpenConflict={onOpenConflict}
      />,
    );

    // Le message SERVEUR est affiché tel quel (nomme la période + sa fenêtre + les issues).
    expect(screen.getByText(/déjà planifiées par « Vacances de Toussaint »/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /ouvrir le planning en place/i }));
    expect(onOpenConflict).toHaveBeenCalledWith("conflict-entry-9");
  });

  it("témoin : sans conflit, aucun bloc de refus (non-régression)", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);
    expect(screen.queryByRole("button", { name: /ouvrir le planning en place/i })).not.toBeInTheDocument();
  });
});

// P2-36 — le dialogue s'OUVRE toujours et NOMME son état, au lieu de basculer en bloc sans un
// mot. Quatre causes distinctes ; ici les trois qui ouvrent un dialogue : chargement, choix des
// semaines (couvert plus haut), bloc déjà généré.
describe("WeekPickerDialog — états nommés (P2-36)", () => {
  const block = { versionCount: 2, validated: false, generationInFlight: false, deleting: false, deleteFailed: false, onDeleteVersions: vi.fn() };

  it("état « chargement » : le dit, n'affiche PAS de cases à cocher, garde « d'un bloc »", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="loading" onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    expect(screen.getByText(/On vérifie l'état de/)).toBeInTheDocument();
    // Pas de coches tant qu'on ne sait pas (le repli « ne jamais cocher sans savoir » demeure)…
    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
    // …ni de bouton « Créer », mais le chemin « d'un bloc » reste offert.
    expect(screen.queryByRole("button", { name: /créer/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /d'un bloc/i })).toBeInTheDocument();
  });

  it("état « bloc » : NOMME le fait (N versions), garde « Continuer d'un bloc », propose la découpe", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={block} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    expect(screen.getByText(/déjà été adaptée d'un bloc — 2 versions/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Continuer d'un bloc/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Supprimer les versions et découper en semaines/i })).toBeInTheDocument();
    // « Bloc » n'offre PAS de cases : on repart de zéro, on ne coche pas sur des versions existantes.
    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
  });

  it("« Continuer d'un bloc » conserve le comportement (appelle onAdaptWhole)", async () => {
    const user = userEvent.setup();
    const onAdaptWhole = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={block} onPickSegments={vi.fn()} onAdaptWhole={onAdaptWhole} onClose={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: /Continuer d'un bloc/i }));
    expect(onAdaptWhole).toHaveBeenCalled();
  });

  it("la confirmation NOMME la portée : nombre de versions supprimées + réglages repartant de la saison", async () => {
    const user = userEvent.setup();
    const onDeleteVersions = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={{ ...block, onDeleteVersions }} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: /Supprimer les versions et découper en semaines/i }));
    // La confirmation nomme la portée avant d'agir.
    expect(screen.getByText(/supprime 2 versions déjà générées/)).toBeInTheDocument();
    expect(screen.getByText(/repartiront de la saison/)).toBeInTheDocument();
    expect(onDeleteVersions).not.toHaveBeenCalled(); // rien tant que non confirmé
    await user.click(screen.getByRole("button", { name: "Supprimer et découper" }));
    expect(onDeleteVersions).toHaveBeenCalled();
  });

  it("bloc VALIDÉ : PAS de bouton de découpe, la raison est affichée et renvoie aux gestes existants", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={{ ...block, validated: true }} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    expect(screen.queryByRole("button", { name: /Supprimer les versions et découper/i })).not.toBeInTheDocument();
    expect(screen.getByText(/validé.*rouvrez-le puis supprimez-le/i)).toBeInTheDocument();
    // Le comportement de repli reste offert.
    expect(screen.getByRole("button", { name: /Continuer d'un bloc/i })).toBeInTheDocument();
  });

  it("génération en vol : le bouton de découpe est DÉSACTIVÉ avec sa raison", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} state="block" block={{ ...block, generationInFlight: true }} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);

    expect(screen.getByRole("button", { name: /Supprimer les versions et découper/i })).toBeDisabled();
    expect(screen.getByText(/génération est en cours/i)).toBeInTheDocument();
  });
});

// P2-40 — une fermeture qui chevauche des vacances : les semaines gouvernées par les vacances sont
// EXCLUES (pas grisées), une ligne d'info le dit, et le chemin « d'un bloc » DISPARAÎT (un plan de
// bloc gouvernerait la fenêtre des vacances). 100 % sous vacances → info seule ; sur le chemin
// pending, un bouton « Consigner l'indisponibilité » crée le FAIT sans plan ni navigation.
describe("WeekPickerDialog — chevauchement vacances (P2-40)", () => {
  const offered = [
    { startDate: "2026-09-07", endDate: "2026-09-13", monday: "2026-09-07" },
    { startDate: "2026-09-14", endDate: "2026-09-20", monday: "2026-09-14" },
  ];
  const excludedRanges = [{ startDate: "2026-08-17", endDate: "2026-09-06", labels: ["Vacances d'été"] }];

  it("affiche la ligne d'info, propose les semaines hors vacances, et DÉSACTIVE le chemin d'un bloc avec sa raison", () => {
    render(
      <WeekPickerDialog
        title="Armand indisponible"
        startDate="2026-08-17"
        endDate="2026-10-01"
        weeks={offered}
        excludedRanges={excludedRanges}
        busy={false}
        state="holiday"
        onPickSegments={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
      />,
    );

    expect(screen.getByText(/couvertes par Vacances d'été/)).toBeInTheDocument();
    expect(screen.getByText(/le rappel vous attend/i)).toBeInTheDocument();
    // P2-41 — les deux semaines hors vacances, contiguës et pleines, sont proposées comme UN
    // segment (une seule coche), pas deux semaines individuelles.
    expect(screen.getAllByRole("checkbox")).toHaveLength(1);
    expect(screen.getByText("Semaines du 7 sept. 2026 au 20 sept. 2026 — d'un bloc (2 semaines)")).toBeInTheDocument();
    // ALIGNEMENT fondateur (P2-38) : le chemin « d'un bloc » n'est plus CACHÉ mais DÉSACTIVÉ avec sa
    // raison visible (patron B). Bascule VOULUE — trois assertions « absent » deviennent « présent,
    // désactivé, avec sa raison ». « Continuer d'un bloc » (libellé de l'état block) reste, lui, absent.
    expect(screen.getByRole("button", { name: /adapter toute la période d'un bloc/i })).toBeDisabled();
    expect(screen.getByText(/vacances couvrent une partie de cette période/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /continuer d'un bloc/i })).not.toBeInTheDocument();
  });

  it("100 % sous vacances (aucune semaine offerte) → info seule + « Consigner l'indisponibilité » (chemin pending)", async () => {
    const user = userEvent.setup();
    const onRecordOnly = vi.fn();
    render(
      <WeekPickerDialog
        title="Armand indisponible"
        startDate="2026-08-03"
        endDate="2026-08-28"
        weeks={[]}
        excludedRanges={excludedRanges}
        busy={false}
        state="holiday"
        onPickSegments={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
        onRecordOnly={onRecordOnly}
      />,
    );

    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /créer/i })).not.toBeInTheDocument();
    // P4-150 — la copie d'écran de l'état vide « tout est sous vacances » est assertée.
    expect(screen.getByText("Toutes les semaines de cette indisponibilité sont couvertes par des vacances — il n'y a rien à ajuster en dehors.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /consigner l'indisponibilité/i }));
    expect(onRecordOnly).toHaveBeenCalled();
  });

  it("entrée déjà en base (pas de onRecordOnly) : info seule, ni « Consigner » ni « Créer » ; le chemin d'un bloc reste désactivé", () => {
    render(
      <WeekPickerDialog
        title="Armand indisponible"
        startDate="2026-08-03"
        endDate="2026-08-28"
        weeks={[]}
        excludedRanges={excludedRanges}
        busy={false}
        state="holiday"
        onPickSegments={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
      />,
    );

    expect(screen.queryByRole("button", { name: /consigner l'indisponibilité/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /créer/i })).not.toBeInTheDocument();
    // ALIGNEMENT fondateur (P2-38) : le chemin « d'un bloc » est désormais présent mais DÉSACTIVÉ
    // (bascule voulue), au lieu d'être caché.
    expect(screen.getByRole("button", { name: /adapter toute la période d'un bloc/i })).toBeDisabled();
    expect(screen.getByText(/couvertes par Vacances d'été/)).toBeInTheDocument();
  });
});

// P2-41 — la liste devient une liste de SEGMENTS précochés (blocs de semaines pleines et contiguës
// aux ruptures géométriques). Le gestionnaire peut SCINDER un segment (le déplier en semaines) et
// FUSIONNER des segments adjacents. Une phrase pédagogique sur tout segment multi-semaines. PAS de
// bouton « adapter le reste d'un bloc » (le reste EST un segment proposé).
describe("WeekPickerDialog — segments (P2-41)", () => {
  // Exemple normatif : indispo mer 02/09 → dim 27/09 → [entame 31/08] + [bloc 07/09→27/09].
  const closure = { title: "Barros fermé", startDate: "2026-09-02", endDate: "2026-09-27" };
  const offered = [
    { startDate: "2026-08-31", endDate: "2026-09-06", monday: "2026-08-31" },
    { startDate: "2026-09-07", endDate: "2026-09-13", monday: "2026-09-07" },
    { startDate: "2026-09-14", endDate: "2026-09-20", monday: "2026-09-14" },
    { startDate: "2026-09-21", endDate: "2026-09-27", monday: "2026-09-21" },
  ];
  const renderPicker = (props: Partial<ComponentProps<typeof WeekPickerDialog>> = {}) =>
    render(
      <WeekPickerDialog
        title={closure.title}
        startDate={closure.startDate}
        endDate={closure.endDate}
        weeks={offered}
        busy={false}
        onPickSegments={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
        {...props}
      />,
    );

  it("propose deux segments précochés : l'entame et le bloc, avec leurs libellés", () => {
    renderPicker();
    const boxes = screen.getAllByRole("checkbox");
    expect(boxes).toHaveLength(2);
    boxes.forEach((b) => expect(b).toBeChecked());
    expect(screen.getByText("Semaine du 31 août 2026 (entamée)")).toBeInTheDocument();
    expect(screen.getByText("Semaines du 7 sept. 2026 au 27 sept. 2026 — d'un bloc (3 semaines)")).toBeInTheDocument();
  });

  it("affiche la phrase pédagogique SUR un segment multi-semaines (présentation)", () => {
    renderPicker();
    expect(screen.getByText(/la fermeture la plus large s'applique à toutes/i)).toBeInTheDocument();
  });

  it("n'offre AUCUN bouton « adapter le reste d'un bloc » (le reste est déjà un segment)", () => {
    renderPicker();
    expect(screen.queryByRole("button", { name: /le reste/i })).not.toBeInTheDocument();
  });

  it("confirme les deux segments précochés (1 entame + 1 bloc de 3 semaines)", async () => {
    const user = userEvent.setup();
    const onPickSegments = vi.fn();
    renderPicker({ onPickSegments });
    await user.click(screen.getByRole("button", { name: /^créer les 2 plannings$/i }));
    expect(onPickSegments).toHaveBeenCalledTimes(1);
    const segs = onPickSegments.mock.calls[0][0];
    expect(segs.map((s: { monday: string }) => s.monday)).toEqual(["2026-08-31", "2026-09-07"]);
    expect(segs[1].weeks).toHaveLength(3);
  });

  it("SCINDER un bloc le déplie en semaines individuelles (toujours précochées)", async () => {
    const user = userEvent.setup();
    const onPickSegments = vi.fn();
    renderPicker({ onPickSegments });
    await user.click(screen.getByRole("button", { name: /Scinder .* en semaines/i }));
    // entame + 3 semaines = 4 coches, toutes cochées ; plus aucune phrase pédagogique (aucun bloc).
    const boxes = screen.getAllByRole("checkbox");
    expect(boxes).toHaveLength(4);
    boxes.forEach((b) => expect(b).toBeChecked());
    expect(screen.queryByText(/la fermeture la plus large/i)).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /^créer les 4 plannings$/i }));
    const segs = onPickSegments.mock.calls[0][0];
    expect(segs.map((s: { monday: string }) => s.monday)).toEqual(["2026-08-31", "2026-09-07", "2026-09-14", "2026-09-21"]);
    expect(segs.every((s: { weeks: unknown[] }) => 1 === s.weeks.length)).toBe(true);
  });

  it("FUSIONNER absorbe l'entame dans le bloc → un seul segment de 4 semaines", async () => {
    const user = userEvent.setup();
    const onPickSegments = vi.fn();
    renderPicker({ onPickSegments });
    await user.click(screen.getByRole("button", { name: /Fusionner .* avec le segment précédent/i }));
    expect(screen.getAllByRole("checkbox")).toHaveLength(1);
    expect(screen.getByText("Semaines du 31 août 2026 au 27 sept. 2026 — d'un bloc (4 semaines)")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /^créer le planning$/i }));
    const segs = onPickSegments.mock.calls[0][0];
    expect(segs).toHaveLength(1);
    expect(segs[0].startDate).toBe("2026-08-31");
    expect(segs[0].endDate).toBe("2026-09-27");
    expect(segs[0].weeks).toHaveLength(4);
  });
});

// P2-38 (prévention) — une semaine gouvernée par un AUTRE plan de période est RETIRÉE des cases à
// cocher (la soustraction vit dans useWeekAdapt) et NOMMÉE dans un encart au-dessus de la liste,
// portant la `reason` SERVEUR telle quelle + « Ouvrir le planning en place ». Le chemin « d'un bloc »
// est désactivé avec sa raison. Le picker n'invente aucune phrase métier (règle d'or).
describe("WeekPickerDialog — prévention semaines déjà planifiées (P2-38)", () => {
  const planned = [{ entryId: "reprise-3", title: "Reprise", startDate: "2026-11-16", endDate: "2026-11-22", label: "semaine du 16 nov.", reason: "Ces dates sont déjà planifiées par « Reprise » (semaine du 16 nov.)." }];

  it("état weeks : NOMME la fenêtre gouvernante (reason servie), propose de l'ouvrir, et DÉSACTIVE le chemin d'un bloc avec sa raison", async () => {
    const user = userEvent.setup();
    const onOpenConflict = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} plannedRanges={planned} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} onOpenConflict={onOpenConflict} />);

    // La reason SERVEUR est affichée telle quelle (le front ne compose rien).
    expect(screen.getByText(/déjà planifiées par « Reprise » \(semaine du 16 nov\.\)/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /ouvrir le planning en place/i }));
    expect(onOpenConflict).toHaveBeenCalledWith("reprise-3");

    // B — le chemin « d'un bloc » est VISIBLE mais désactivé, sa raison en clair (jamais via title=).
    expect(screen.getByRole("button", { name: /adapter toute la période d'un bloc/i })).toBeDisabled();
    expect(screen.getByText(/déjà planifiée par ailleurs/i)).toBeInTheDocument();
  });

  it("un encart PAR fenêtre gouvernante", () => {
    const two = [planned[0], { entryId: "stage-9", title: "Stage", startDate: "2026-11-23", endDate: "2026-11-29", label: "semaine du 23 nov.", reason: "Ces dates sont déjà planifiées par « Stage » (semaine du 23 nov.)." }];
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} plannedRanges={two} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} onOpenConflict={vi.fn()} />);
    expect(screen.getAllByRole("button", { name: /ouvrir le planning en place/i })).toHaveLength(2);
  });

  it("les encarts coexistent avec l'exclusion vacances (état holiday, notions orthogonales)", () => {
    render(
      <WeekPickerDialog
        title="Armand indisponible"
        startDate="2026-08-17"
        endDate="2026-10-01"
        weeks={[{ startDate: "2026-09-07", endDate: "2026-09-13", monday: "2026-09-07" }]}
        excludedRanges={[{ startDate: "2026-08-17", endDate: "2026-09-06", labels: ["Vacances d'été"] }]}
        plannedRanges={planned}
        state="holiday"
        busy={false}
        onPickSegments={vi.fn()}
        onAdaptWhole={vi.fn()}
        onClose={vi.fn()}
        onOpenConflict={vi.fn()}
      />,
    );
    expect(screen.getByText(/couvertes par Vacances d'été/)).toBeInTheDocument();
    expect(screen.getByText(/déjà planifiées par « Reprise »/)).toBeInTheDocument();
  });

  it("D — 100 % déjà planifiée : ligne « rien à ajuster », encart présent, « Consigner » offert au pending", async () => {
    const user = userEvent.setup();
    const onRecordOnly = vi.fn();
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={[]} busy={false} plannedRanges={planned} onRecordOnly={onRecordOnly} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} onOpenConflict={vi.fn()} />);

    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /créer/i })).not.toBeInTheDocument();
    expect(screen.getByText(/Aucune semaine de cette indisponibilité ne reste à ajuster ici/)).toBeInTheDocument();
    expect(screen.getByText(/déjà planifiées par « Reprise »/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /consigner l'indisponibilité/i }));
    expect(onRecordOnly).toHaveBeenCalled();
  });

  it("témoin : sans plannedRanges, aucun encart et le chemin d'un bloc reste actif", () => {
    render(<WeekPickerDialog title={mother.title} startDate={mother.startDate} endDate={mother.endDate} weeks={weeks} busy={false} onPickSegments={vi.fn()} onAdaptWhole={vi.fn()} onClose={vi.fn()} />);
    expect(screen.queryByRole("button", { name: /ouvrir le planning en place/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /adapter toute la période d'un bloc/i })).toBeEnabled();
  });
});

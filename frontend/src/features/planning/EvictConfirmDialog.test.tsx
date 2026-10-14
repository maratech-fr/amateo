import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { MOVE_VERDICT_TIMEOUT_SECONDS } from "./api";
import { EvictConfirmDialog } from "./EvictConfirmDialog";

const baseProps = {
  open: true as const,
  occupantName: "U13 M1",
  compromises: [],
  violations: [],
  failureKind: "timeout" as const,
  busy: false,
  onConfirm: () => {},
  onRetry: () => {},
  onClose: () => {},
};

describe("EvictConfirmDialog", () => {
  it("affiche la borne d'attente DÉRIVÉE de MOVE_VERDICT_TIMEOUT_SECONDS — jamais un « 45 » codé en dur (P4-119 c bis)", () => {
    // L'attente construit son nombre depuis la constante partagée (le vrai budget client) : si la
    // phrase re-codait un littéral et que la constante dérivait, ce test rougirait. La cohérence
    // message⇄comportement est ainsi gardée par construction.
    render(<EvictConfirmDialog {...baseProps} phase="checking" />);

    expect(screen.getByText(new RegExp(`jusqu'à ${MOVE_VERDICT_TIMEOUT_SECONDS} s`))).toBeInTheDocument();
  });

  it("ENG-51 — la phase INDÉTERMINÉE montre un bandeau NEUTRE « réessayez » + [Réessayer], jamais un style de refus", () => {
    render(<EvictConfirmDialog {...baseProps} phase="indeterminate" />);

    expect(screen.getByText("La vérification a pris trop de temps, réessayez.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Réessayer" })).toBeInTheDocument();
    // Pas un refus : aucune mention « casse une règle » ni rôle d'alerte conflit.
    expect(screen.queryByText(/casse une règle/i)).not.toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("variante kind=\"swap\" — titre, texte d'acceptation et bouton parlent d'ÉCHANGE (pas d'éviction)", () => {
    render(<EvictConfirmDialog {...baseProps} kind="swap" phase="accepted" />);

    expect(screen.getByRole("heading", { name: "Échanger les deux créneaux ?" })).toBeInTheDocument();
    expect(screen.getByText(/troqueront leurs créneaux, sans perte/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Échanger" })).toBeInTheDocument();
    // Jamais le vocabulaire d'éviction dans cette variante.
    expect(screen.queryByRole("button", { name: "Déplacer et évincer" })).not.toBeInTheDocument();
    expect(screen.queryByText(/séances à replacer/)).not.toBeInTheDocument();
  });

  it("accepted + occupantIsGroup — le texte parle d'un GROUPE (« Le déplacer », « Ses séances passeront »), jamais le féminin singulier d'une équipe", () => {
    render(<EvictConfirmDialog {...baseProps} phase="accepted" occupantIsGroup occupantName="le groupe" />);

    expect(screen.getByText(/Ses séances passeront dans les séances à replacer/)).toBeInTheDocument();
    // Un groupe n'est pas « une » séance : jamais la tournure équipe « Elle passera ».
    expect(screen.queryByText(/Elle passera/)).not.toBeInTheDocument();
  });

  it("accepted — pour une ÉQUIPE (occupant non groupe), la tournure reste au féminin singulier (« Elle passera »)", () => {
    render(<EvictConfirmDialog {...baseProps} phase="accepted" occupantName="U13 M1" />);

    expect(screen.getByText(/Elle passera dans les séances à replacer/)).toBeInTheDocument();
    expect(screen.queryByText(/Ses séances passeront/)).not.toBeInTheDocument();
  });
});

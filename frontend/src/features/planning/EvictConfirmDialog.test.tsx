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
});

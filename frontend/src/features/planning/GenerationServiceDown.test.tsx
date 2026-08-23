import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { beforeEach, describe, expect, it, vi } from "vitest";

// Le TEXTE du CSS d'arrêt (lu du disque) : jsdom n'a aucun moteur de mise en page, on garde donc
// le piège reduced-motion sur la SOURCE, pas sur le rendu (`.claude/rules/frontend.md`). Chemin
// depuis la racine du projet vitest (`/app/frontend`).
const css = readFileSync(resolve(process.cwd(), "src/features/planning/GenerationWaiting.css"), "utf8");

import { clearLastIncident, recordIncident } from "@/shared/api/lastIncidentStore";

// La modale de signalement est mockée pour RECORDER la variante et le scheduleId reçus —
// c'est le CÂBLAGE « Contacter le support » → FeedbackDialog contextuel qu'on épingle (le
// contexte n'est joint qu'en `contextual`, cf. FeedbackDialog.tsx:62-69).
const feedbackProps = vi.fn();
vi.mock("@/features/feedback/FeedbackDialog", () => ({
  FeedbackDialog: (props: { variant: string; scheduleId?: string | null }) => {
    feedbackProps(props);
    return <div data-testid="feedback-dialog" data-variant={props.variant} data-schedule={props.scheduleId ?? ""} />;
  },
}));

import { GenerationServiceDown } from "./GenerationServiceDown";

beforeEach(() => {
  feedbackProps.mockClear();
  clearLastIncident();
});

describe("GenerationServiceDown — l'écran « le service de calcul ne répond pas »", () => {
  it("porte la scène de génération À L'ARRÊT (classe gw-halted, chrono barré) sans perdre les testids du décor", () => {
    render(<GenerationServiceDown onRetry={vi.fn()} creditsBlocked={false} creditSuffix="" scheduleId={null} />);
    const frame = screen.getByTestId("gen-scene-frame");
    expect(frame.className).toContain("gw-halted");
    // Décor conservé (scène réutilisée, pas dupliquée).
    expect(within(frame).getByTestId("gen-band-left")).toBeInTheDocument();
    expect(within(frame).getByTestId("gen-band-right")).toBeInTheDocument();
    expect(within(frame).getByTestId("gen-minigrid")).toBeInTheDocument();
    // Le chrono est barré et le ballon est posé (état arrêté) — absents de l'écran d'attente.
    expect(within(frame).getByTestId("gen-timer-strike")).toBeInTheDocument();
    expect(within(frame).getByTestId("gen-rest-ball")).toBeInTheDocument();
  });

  it("affiche la copie de disculpation — c'est le SEUL écran qui dit « rien à corriger de votre côté »", () => {
    render(<GenerationServiceDown onRetry={vi.fn()} creditsBlocked={false} creditSuffix="" scheduleId={null} />);
    expect(screen.getByText(/La table de marque ne répond plus/i)).toBeInTheDocument();
    expect(screen.getByText(/momentanément indisponible/i)).toBeInTheDocument();
    expect(screen.getByText(/vos équipes, vos gymnases et vos contraintes sont intacts/i)).toBeInTheDocument();
    expect(screen.getByText(/rien à corriger de votre côté/i)).toBeInTheDocument();
  });

  it("annonce le titre stable en région role=status polite, SANS voler le focus (état, pas navigation)", () => {
    render(<GenerationServiceDown onRetry={vi.fn()} creditsBlocked={false} creditSuffix="" scheduleId={null} />);
    const status = screen.getByRole("status");
    expect(status.getAttribute("aria-live")).toBe("polite");
    expect(within(status).getByText(/La table de marque ne répond plus/i)).toBeInTheDocument();
    // Aucun vol de focus : le focus reste sur le body (ce n'est pas une navigation de route).
    expect(document.activeElement).toBe(document.body);
  });

  it("« Réessayer » appelle l'action injectée et conserve le suffixe de crédits", async () => {
    const onRetry = vi.fn();
    render(<GenerationServiceDown onRetry={onRetry} creditsBlocked={false} creditSuffix=" (3)" scheduleId="run-1" />);
    const retry = screen.getByRole("button", { name: /Réessayer \(3\)/ });
    await userEvent.click(retry);
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it("« Réessayer » est désactivé quand les crédits bloquent (miroir d'affichage)", () => {
    render(<GenerationServiceDown onRetry={vi.fn()} creditsBlocked={true} creditSuffix=" (0)" scheduleId={null} />);
    expect(screen.getByRole("button", { name: /Réessayer/ })).toBeDisabled();
  });

  it("« Contacter le support » ouvre le FeedbackDialog en variante CONTEXTUELLE avec le scheduleId du run échoué", async () => {
    render(<GenerationServiceDown onRetry={vi.fn()} creditsBlocked={false} creditSuffix="" scheduleId="run-42" />);
    expect(screen.queryByTestId("feedback-dialog")).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /Contacter le support/ }));
    const dialog = screen.getByTestId("feedback-dialog");
    // Impératif : contextual, sinon le scheduleId ne serait PAS joint (FeedbackDialog:62-69).
    expect(dialog).toHaveAttribute("data-variant", "contextual");
    expect(dialog).toHaveAttribute("data-schedule", "run-42");
  });

  it("« Code incident » n'apparaît QUE si un incident frais existe (l'échec async rend ce cas rare)", () => {
    // Sans incident récent (cas quasi toujours vrai : POST 202, échec en réponse 200) : rien.
    const { unmount } = render(<GenerationServiceDown onRetry={vi.fn()} creditsBlocked={false} creditSuffix="" scheduleId={null} />);
    expect(screen.queryByText(/Code incident/i)).not.toBeInTheDocument();
    unmount();

    // Avec un incident frais (≥ 500 récent) : la ligne s'affiche.
    recordIncident({ status: 502, url: "/api/generate", requestId: "req-abc" });
    render(<GenerationServiceDown onRetry={vi.fn()} creditsBlocked={false} creditSuffix="" scheduleId={null} />);
    expect(screen.getByText(/Code incident\s*:\s*req-abc/i)).toBeInTheDocument();
  });
});

/**
 * jsdom n'a AUCUN moteur de mise en page (`.claude/rules/frontend.md`) : le CSS `gw-halted`
 * ne s'atteste pas au rendu. On garde donc le PIÈGE VÉRIFIÉ directement sur le TEXTE du CSS :
 * le bloc `prefers-reduced-motion` force `.gw-anim { opacity: 1 !important }` (cases PLEINES
 * pour l'attente). Transposé à l'arrêt, un utilisateur reduced-motion verrait une grille PLEINE
 * sous un titre « service en panne » — un faux état de SUCCÈS. L'override d'arrêt doit battre ce
 * bloc : `.gw-halted .gw-anim { opacity: 0 !important }` (spécificité supérieure, !important égal).
 */
describe("GenerationWaiting.css — l'arrêt bat le bloc reduced-motion (cases VIDES, jamais pleines)", () => {
  it("porte l'override d'arrêt qui vide les cases à !important", () => {
    expect(css).toMatch(/\.gw-halted\s+\.gw-anim\s*\{[^}]*opacity:\s*0\s*!important/);
  });

  it("neutralise les animations à l'arrêt", () => {
    expect(css).toMatch(/\.gw-halted\s+\.gw-anim\s*\{[^}]*animation:\s*none\s*!important/);
  });

  it("le bloc reduced-motion existe TOUJOURS (le piège qu'on doit battre) et fige aussi le ballon au repos", () => {
    expect(css).toMatch(/prefers-reduced-motion/);
    expect(css).toMatch(/\.gw-restball\s*\{[^}]*animation:\s*none\s*!important/);
  });
});

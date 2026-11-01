import { HTTPError } from "ky";
import { type FormEvent, useEffect, useId, useRef, useState } from "react";
import { useLocation } from "react-router";

import { readRecentIncident, readRecentIncidents } from "@/shared/api/lastIncidentStore";
import { Button } from "@/shared/components/ui/button";
import { Label } from "@/shared/components/ui/label";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";
import { APP_VERSION } from "@/shared/lib/appVersion";
import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

import type { FeedbackContext, FeedbackTopic } from "./api";
import { captureScreen } from "./captureScreen";
import { readRecentClicks, readRecentLastClick } from "./lastClickStore";
import { useSubmitFeedback } from "./queries";
import { FEEDBACK_TEXTS } from "./texts";

interface FeedbackDialogProps {
  /**
   * `contextual` : topic figé à « bug », contexte maximal joint automatiquement
   * (écran, planning affiché, incident récent). `free` : sélecteur de topic, contexte léger.
   */
  variant: "contextual" | "free";
  onClose: () => void;
  /** Libellé d'écran joint au contexte (défaut : le pathname courant). */
  screen?: string;
  /** Planning affiché par l'écran appelant (variante contextuelle uniquement). */
  scheduleId?: string | null;
  /** Libellé d'écran LISIBLE (ex. « Assistant · Étape 2/6 · Gymnases ») — contextuel seul. */
  screenLabel?: string;
  /** Filtres actifs composés par l'écran (ex. « familles : Championnat ») — contextuel seul. */
  activeFilters?: string;
  /** Plage affichée (ex. « week-end du 08/11 ») — contextuel seul. */
  displayedRange?: string;
  /**
   * D15 (PR B) — état COMPLET de la modale ouvrante (string JSON), joint comme `modalState` quand le
   * signalement est déclenché depuis le drapeau d'une modale (variante contextuelle).
   */
  modalState?: string;
}

const TOPIC_OPTIONS: { value: FeedbackTopic; label: string }[] = [
  { value: "bug", label: FEEDBACK_TEXTS.topicBug },
  { value: "missing_constraint", label: FEEDBACK_TEXTS.topicMissingConstraint },
  { value: "idea", label: FEEDBACK_TEXTS.topicIdea },
];

/**
 * Le texte d'un envoi refusé. `FeedbackController` écrit en français ses 422 et son 429, dans `error` ;
 * ses 400 et 404, comme le `detail` du limiteur global (429), restent en anglais et n'atteignent pas
 * l'écran. Local à ce parcours : `errorMessage` reprend tout message de 4xx hors reason-phrase HTTP.
 * Le reste (5xx, SEC-08 ; réseau ; délai) suit la règle commune.
 */
async function refusalMessage(error: Error): Promise<string> {
  if (!(error instanceof HTTPError) || error.response.status >= 500) {
    return errorMessage(error);
  }
  const status = error.response.status;
  const data = (error as { data?: unknown }).data;
  const serverMessage = null !== data && "object" === typeof data ? (data as { error?: unknown }).error : undefined;
  if ((422 === status || 429 === status) && "string" === typeof serverMessage && "" !== serverMessage.trim()) {
    return serverMessage;
  }
  return 429 === status ? FEEDBACK_TEXTS.tooManyReports : FEEDBACK_TEXTS.sendRefused;
}

export function FeedbackDialog({ variant, onClose, screen: screenProp, scheduleId, screenLabel, activeFilters, displayedRange, modalState }: FeedbackDialogProps) {
  const { pathname } = useLocation();
  // Fenêtre encore ouverte ? Annuler reste possible pendant l'envoi : un refus arrivé après la
  // fermeture n'a plus de fenêtre où s'afficher, il passe alors par un toast (jamais silencieux).
  const openRef = useRef(true);
  useEffect(() => {
    openRef.current = true;
    return () => {
      openRef.current = false;
    };
  }, []);
  const [topic, setTopic] = useState<FeedbackTopic>("bug");
  const [message, setMessage] = useState("");
  const [attachScreenshot, setAttachScreenshot] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // D16 (anti double envoi) — garde SYNCHRONE posée en tête de `handleSubmit`, AVANT l'await de
  // `captureScreen` : un 2ᵉ « Envoyer » pendant la capture pendante est inerte (le cas réel d'un club pilote ×4 à
  // la même seconde). `submit.isPending` ne bascule qu'APRÈS l'await — il ne protège pas cette fenêtre.
  const sendingRef = useRef(false);
  const [sending, setSending] = useState(false);
  const submit = useSubmitFeedback((err) => {
    void refusalMessage(err).then((text) => {
      if (openRef.current) {
        setError(text);
      } else {
        toast.error(text);
      }
    });
  });
  const topicId = useId();
  const messageId = useId();
  const formId = useId();

  const contextual = "contextual" === variant;
  const screen = screenProp ?? pathname;
  const messageLabel = contextual ? FEEDBACK_TEXTS.messageLabelContextual : FEEDBACK_TEXTS.messageLabelFree;
  const pending = submit.isPending || sending;

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    // Garde synchrone : tout 2ᵉ envoi tant qu'un envoi est en cours est ignoré.
    if (sendingRef.current) {
      return;
    }
    const trimmed = message.trim();
    if ("" === trimmed) {
      setError(FEEDBACK_TEXTS.messageRequired);
      return;
    }
    setError(null);
    sendingRef.current = true;
    setSending(true);

    const context: FeedbackContext = {
      screen,
      url: window.location.href,
      userAgent: navigator.userAgent,
      // Version du bundle jointe au contexte de BASE (les DEUX portes) — le support
      // compare « App vX · API vY » (la version de l'API est ajoutée côté serveur).
      appVersion: APP_VERSION,
    };
    if (contextual) {
      // La porte CONTEXTUELLE joint les repères que seul le front connaît. La porte LIBRE
      // (burger) reste un contexte léger — aucun de ces repères n'y fuite (garde de frontière).
      if (scheduleId) {
        context.scheduleId = scheduleId;
      }
      if (screenLabel) {
        context.screenLabel = screenLabel;
      }
      if (activeFilters) {
        context.activeFilters = activeFilters;
      }
      if (displayedRange) {
        context.displayedRange = displayedRange;
      }
      // D15 — l'état complet de la modale ouvrante (déjà sérialisé par la primitive Modal).
      if (modalState) {
        context.modalState = modalState;
      }
      const lastClick = readRecentLastClick();
      if (lastClick) {
        context.lastClick = lastClick.label;
      }
      const incident = readRecentIncident();
      if (incident) {
        context.incidentStatus = incident.status;
        context.incidentUrl = incident.url;
        if (incident.code) {
          context.incidentCode = incident.code;
        }
        if (incident.requestId) {
          context.requestId = incident.requestId;
        }
      }
      // D17 — le fil d'incident CLIENT : 5 dernières erreurs API, 5 derniers clics, horloge du poste.
      const recentErrors = readRecentIncidents();
      if (recentErrors.length > 0) {
        context.recentErrors = recentErrors.map((e) => ({
          method: e.method,
          route: e.route ?? e.url,
          status: e.status,
          code: e.code,
          errors: e.errors,
          requestId: e.requestId,
          at: new Date(e.at).toISOString(),
        }));
      }
      const recentClicks = readRecentClicks();
      if (recentClicks.length > 0) {
        context.recentClicks = recentClicks;
      }
      // D17 — `clientClock` = l'horloge RÉELLE DU POSTE. Exception ASSUMÉE à `shared/lib/clock.ts` :
      // ce n'est PAS l'« aujourd'hui » métier (override `?today=` / horloge simulée d'un club démo),
      // c'est l'heure et le fuseau du navigateur de l'auteur — précisément ce que le support veut
      // pour recouper un incident avec les logs serveur. D'où `new Date()` / `Intl`, volontairement.
      context.clientClock = { at: new Date().toISOString(), timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone };
    }

    // Topic FIGÉ en contextuel — jamais dérivé d'un état d'UI (aucun sélecteur rendu).
    const effectiveTopic: FeedbackTopic = contextual ? "bug" : topic;

    // Capture d'écran optionnelle : on EXCLUT la fenêtre de signalement elle-même (elle
    // couvre l'écran). Un échec de capture ne perd pas le signalement — il part sans image.
    let screenshot: Blob | undefined;
    if (attachScreenshot) {
      try {
        const blob = await captureScreen({ excludeSelector: '[role="dialog"]' });
        if (null !== blob) {
          screenshot = blob;
        }
      } catch {
        // Capture ratée : on continue sans image.
      }
    }

    submit.mutate(
      { topic: effectiveTopic, message: trimmed, context, screenshot },
      {
        onSuccess: () => {
          // Rendre la main (en prod `onClose` démonte la modale ; en test elle peut rester montée).
          sendingRef.current = false;
          setSending(false);
          toast.success(FEEDBACK_TEXTS.sentToast);
          onClose();
        },
        onError: () => {
          // Rendre la main pour permettre un nouvel envoi (le texte du refus vit dans le hook).
          sendingRef.current = false;
          setSending(false);
        },
      },
    );
  }

  return (
    <Modal
      label={FEEDBACK_TEXTS.reportLabel}
      title={contextual ? FEEDBACK_TEXTS.titleContextual : FEEDBACK_TEXTS.titleFree}
      onClose={onClose}
      // D14 — le canal de signalement ne se signale pas lui-même : pas de drapeau sur SA modale.
      showReportFlag={false}
      // Le formulaire vit dans le corps défilant ; le bouton d'envoi part au pied épinglé et
      // reste rattaché au `<form>` par l'attribut HTML `form={formId}` (submit + Entrée intacts).
      footer={
        <>
          <Button type="button" variant="ghost" onClick={onClose}>
            {FEEDBACK_TEXTS.cancel}
          </Button>
          <Button type="submit" form={formId} disabled={pending}>
            {pending ? FEEDBACK_TEXTS.sending : FEEDBACK_TEXTS.send}
          </Button>
        </>
      }
    >
      <form id={formId} onSubmit={(event) => void handleSubmit(event)} className="mt-4 space-y-4">
        {contextual ? null : (
          <div className="space-y-1.5">
            <Label htmlFor={topicId}>{FEEDBACK_TEXTS.topicFieldLabel}</Label>
            <Select id={topicId} value={topic} onChange={(event) => setTopic(event.target.value as FeedbackTopic)}>
              {TOPIC_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>
        )}

        <div className="space-y-1.5">
          <Label htmlFor={messageId}>{messageLabel}</Label>
          <textarea
            id={messageId}
            value={message}
            onChange={(event) => setMessage(event.target.value)}
            rows={5}
            maxLength={5000}
            required
            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <div className="space-y-1.5">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={attachScreenshot}
              onChange={(event) => setAttachScreenshot(event.target.checked)}
              className="size-4 rounded border border-input"
            />
            {FEEDBACK_TEXTS.attachScreenshot}
          </label>
          <p className="text-xs text-muted-foreground">{FEEDBACK_TEXTS.attachScreenshotHint}</p>
        </div>

        {contextual ? <p className="text-xs text-muted-foreground">{FEEDBACK_TEXTS.contextualHint}</p> : null}

        {null !== error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : null}

      </form>
    </Modal>
  );
}

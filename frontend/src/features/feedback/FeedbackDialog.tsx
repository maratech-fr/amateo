import { HTTPError } from "ky";
import { type FormEvent, useId, useState } from "react";
import { useLocation } from "react-router";

import { readRecentIncidentRequestId } from "@/shared/api/lastIncidentStore";
import { Button } from "@/shared/components/ui/button";
import { Label } from "@/shared/components/ui/label";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";
import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

import type { FeedbackContext, FeedbackTopic } from "./api";
import { useSubmitFeedback } from "./queries";

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
}

const TOPIC_OPTIONS: { value: FeedbackTopic; label: string }[] = [
  { value: "bug", label: "Bug" },
  { value: "missing_constraint", label: "Contrainte manquante" },
  { value: "idea", label: "Idée" },
];

export function FeedbackDialog({ variant, onClose, screen: screenProp, scheduleId }: FeedbackDialogProps) {
  const { pathname } = useLocation();
  const submit = useSubmitFeedback();
  const [topic, setTopic] = useState<FeedbackTopic>("bug");
  const [message, setMessage] = useState("");
  const [error, setError] = useState<string | null>(null);
  const topicId = useId();
  const messageId = useId();
  const formId = useId();

  const contextual = "contextual" === variant;
  const screen = screenProp ?? pathname;
  const messageLabel = contextual ? "Décrivez le problème" : "Votre message";

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const trimmed = message.trim();
    if ("" === trimmed) {
      setError("Le message est obligatoire.");
      return;
    }
    setError(null);

    const context: FeedbackContext = {
      screen,
      url: window.location.href,
      userAgent: navigator.userAgent,
    };
    if (contextual) {
      if (scheduleId) {
        context.scheduleId = scheduleId;
      }
      const requestId = readRecentIncidentRequestId();
      if (requestId) {
        context.requestId = requestId;
      }
    }

    // Topic FIGÉ en contextuel — jamais dérivé d'un état d'UI (aucun sélecteur rendu).
    const effectiveTopic: FeedbackTopic = contextual ? "bug" : topic;

    submit.mutate(
      { topic: effectiveTopic, message: trimmed, context },
      {
        onSuccess: () => {
          toast.success("Signalement envoyé — vous recevrez un accusé par email.");
          onClose();
        },
        onError: (err) => {
          // 429 : message doux dédié (le générique pousserait à re-cliquer).
          if (err instanceof HTTPError && 429 === err.response.status) {
            setError("Trop de signalements, réessayez plus tard.");
            return;
          }
          // 422 et autres 4xx : messages métier du serveur (errorMessage lit error.data).
          void errorMessage(err).then(setError);
        },
      },
    );
  }

  return (
    <Modal
      label="Signaler"
      title={contextual ? "Signaler un problème sur cet écran" : "Signaler un bug ou une idée"}
      onClose={onClose}
      // Le formulaire vit dans le corps défilant ; le bouton d'envoi part au pied épinglé et
      // reste rattaché au `<form>` par l'attribut HTML `form={formId}` (submit + Entrée intacts).
      footer={
        <>
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" form={formId} disabled={submit.isPending}>
            {submit.isPending ? "Envoi…" : "Envoyer"}
          </Button>
        </>
      }
    >
      <form id={formId} onSubmit={handleSubmit} className="mt-4 space-y-4">
        {contextual ? null : (
          <div className="space-y-1.5">
            <Label htmlFor={topicId}>Type de signalement</Label>
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

        {contextual ? (
          <p className="text-xs text-muted-foreground">L'écran courant et quelques informations techniques seront joints automatiquement pour nous aider à reproduire.</p>
        ) : null}

        {null !== error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : null}

      </form>
    </Modal>
  );
}

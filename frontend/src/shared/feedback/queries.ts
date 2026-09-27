import { useMutation } from "@tanstack/react-query";

import * as feedbackApi from "./api";
import type { FeedbackPayload } from "./api";

/** Envoi d'un signalement — pas de cache à invalider (rien n'est lu côté club). */
export function useSubmitFeedback() {
  return useMutation({
    mutationFn: (body: FeedbackPayload) => feedbackApi.submitFeedback(body),
  });
}

import { useMutation } from "@tanstack/react-query";

import * as feedbackApi from "./api";
import type { FeedbackPayload } from "./api";

/**
 * Envoi d'un signalement — pas de cache à invalider (rien n'est lu côté club). `onError` au niveau
 * HOOK : il désarme le filet global de `shared/lib/queryClient.ts`, un refus ne se lit qu'une fois.
 */
export function useSubmitFeedback(onError: (error: Error) => void) {
  return useMutation({
    mutationFn: (body: FeedbackPayload) => feedbackApi.submitFeedback(body),
    onError,
  });
}

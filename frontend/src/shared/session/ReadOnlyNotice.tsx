import { Eye } from "lucide-react";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";

import { useCanManage } from "./queries";
import { SESSION_TEXTS } from "./texts";

/**
 * Bandeau « Consultation » d'un écran de saisie, rendu au Membre seulement : il lit tout, ne
 * modifie rien. Rien pour un gestionnaire.
 */
export function ReadOnlyNotice() {
  if (useCanManage()) {
    return null;
  }
  return <NoticeBanner tone="muted" role="status" icon={<Eye className="size-4" />} message={SESSION_TEXTS.readOnlyNotice} className="mb-4" />;
}

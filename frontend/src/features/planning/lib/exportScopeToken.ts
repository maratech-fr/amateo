// Chantier E — le JETON qui termine le nom de fichier d'un export PDF, foyer PUR partagé par la
// page qui attend l'export (`queries.ts` `useScheduleExport`). La page ne télécharge QUE l'URL dont
// le nom de fichier se termine par le jeton de SA demande (`url.endsWith(exportScopeToken(...))`),
// sinon deux exports simultanés du même planning (autre onglet, autre portée, autre choix de
// coachs) se voleraient leur fichier. Le jeton reflète EXACTEMENT le nom que le back écrit
// (`PdfGenerator::generate` → `schedule-<id>-<scope>-coaches.pdf` / `schedule-<id>-<scope>.pdf`) :
// portée `-all` (tous les gymnases) ou `-<8 premiers caractères du venueId>`, suffixe `-coaches`
// quand les noms de coachs sont demandés, extension `.pdf`.

import type { ExportVenueScope } from "../api";

export function exportScopeToken(venueId: ExportVenueScope, showCoaches: boolean): string {
  const scope = null === venueId ? "all" : venueId.slice(0, 8);
  const coachToken = showCoaches ? "-coaches" : "";
  return `-${scope}${coachToken}.pdf`;
}

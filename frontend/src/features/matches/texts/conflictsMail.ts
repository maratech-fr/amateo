/**
 * Textes de l'envoi des conflits aux coachs (onglet Conflits) : le bouton, ses raisons de
 * désactivation, la confirmation, la ligne « Envoyé le … » et le toast du résultat. Accords
 * singulier/pluriel PAR SEGMENT.
 */

const emails = (count: number): string => (1 === count ? "1 e-mail" : `${count} e-mails`);

const coachesWithoutAddress = (names: string[]): string =>
  `${names.length} ${1 === names.length ? "coach" : "coachs"} sans adresse`;

export const MATCHES_CONFLICTS_MAIL_TEXTS = {
  sendButton: "Envoyer aux coachs",
  sending: "Envoi…",
  disabledNothingToSend: "Aucun conflit à envoyer et aucun coach à prévenir.",
  disabledDemo: "Envoi d'e-mails indisponible en démonstration.",
  disabledArchived: "Cette saison est archivée — elle est en lecture seule.",
  disabledUnavailable: "Envoi indisponible pour le moment.",
  confirmTitle: "Envoyer aux coachs ?",
  confirmBody: (seasonName: string): string =>
    `Saison ${seasonName}. Chaque coach concerné recevra par e-mail, à titre indicatif, les conflits de personnes sur un match, datés d'aujourd'hui ou à venir, qui le touchent. Un coach informé lors d'un envoi précédent et qui n'a plus de conflit recevra un e-mail le disant. Les autres familles de conflits ne sont pas envoyées.`,
  confirmAction: "Envoyer",
  confirmCancel: "Annuler",
  coachRemoved: "un coach retiré",
  /** « Envoyé le 03/11/2026 à 14:32 par Nadia — 7 e-mails · 1 coach sans adresse à cet envoi : Karim B. » */
  statusLine: ({ date, time, sender, mailedCount, skippedNames }: { date: string; time: string; sender: string | null; mailedCount: number; skippedNames: string[] }): string => {
    const head = `Envoyé le ${date} à ${time}${null === sender ? "" : ` par ${sender}`} — ${emails(mailedCount)}`;
    return 0 === skippedNames.length ? head : `${head} · ${coachesWithoutAddress(skippedNames)} à cet envoi : ${skippedNames.join(", ")}`;
  },
  /** « 7 e-mails envoyés · 1 coach sans adresse : Karim B. · 1 e-mail non parti : Léa R. » */
  toastResult: ({ mailedCount, skippedNames, failedNames }: { mailedCount: number; skippedNames: string[]; failedNames: string[] }): string => {
    const head = 0 === mailedCount ? "Aucun e-mail envoyé" : `${emails(mailedCount)} ${1 === mailedCount ? "envoyé" : "envoyés"}`;
    const segments = [head];
    if (skippedNames.length > 0) {
      segments.push(`${coachesWithoutAddress(skippedNames)} : ${skippedNames.join(", ")}`);
    }
    if (failedNames.length > 0) {
      segments.push(`${emails(failedNames.length)} ${1 === failedNames.length ? "non parti" : "non partis"} : ${failedNames.join(", ")}`);
    }
    return 1 === segments.length ? `${head}.` : segments.join(" · ");
  },
} as const satisfies Record<string, string | ((...args: never[]) => string)>;

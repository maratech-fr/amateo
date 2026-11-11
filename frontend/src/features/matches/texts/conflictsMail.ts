/**
 * Textes de l'envoi des conflits aux coachs (onglet Conflits) : le bouton, ses raisons de
 * désactivation, la modale d'aperçu avant envoi, la ligne « Envoyé le … » et le toast du résultat.
 * Accords singulier/pluriel PAR SEGMENT.
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
    `Saison ${seasonName}. Chaque coach concerné recevra par e-mail, à titre indicatif, les conflits de personnes sur un match, datés d'aujourd'hui ou à venir, qui le touchent. Par défaut, seuls les coachs qui ont un conflit nouveau ou modifié depuis le dernier envoi reçoivent leur liste complète, nouveautés marquées ; un coach informé précédemment et qui n'a plus de conflit reçoit un e-mail le disant. Les autres familles de conflits ne sont pas envoyées.`,
  /** Plus lu par l'écran (l'aperçu avant envoi compte ce qui partira) ; son retrait va avec celui de son test. */
  confirmCounts: ({ coachCount, conflictCount }: { coachCount: number; conflictCount: number }): string =>
    `${coachCount} ${1 === coachCount ? "coach recevra" : "coachs recevront"} un e-mail · ${conflictCount} ${conflictCount > 1 ? "conflits de personnes" : "conflit de personne"}.`,
  confirmIgnoresFilters: "Les filtres de l'écran ne comptent pas : la liste ci-dessous est ce qui partira.",
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
  // ── Aperçu avant envoi (récap par destinataire) : chaque nombre et chaque motif viennent du serveur. ──
  /** « Karim B. — karim@club.fr » ou « Nadia K. — » (sans adresse : la pastille suit). */
  recipientLine: ({ name, email }: { name: string; email: string | null }): string => (null === email || "" === email ? `${name} —` : `${name} — ${email}`),
  noAddressPill: "Pas d'adresse",
  invalidAddressPill: "Adresse invalide",
  /** « 3 conflits · 1 nouveau · 2 déjà envoyés le 03/11 » ; sans reçu connu : « 2 conflits · aucun envoi détaillé connu ». */
  recipientDetail: ({ conflictCount, newCount, changedCount, alreadySentCount, lastSentDate, receiptKnown }: { conflictCount: number; newCount: number; changedCount: number; alreadySentCount: number; lastSentDate: string | null; receiptKnown: boolean }): string => {
    const segments = [1 === conflictCount ? "1 conflit" : `${conflictCount} conflits`];
    if (!receiptKnown) {
      segments.push("aucun envoi détaillé connu");
      return segments.join(" · ");
    }
    if (newCount > 0) {
      segments.push(1 === newCount ? "1 nouveau" : `${newCount} nouveaux`);
    }
    if (changedCount > 0) {
      segments.push(1 === changedCount ? "1 modifié" : `${changedCount} modifiés`);
    }
    if (alreadySentCount > 0 && null !== lastSentDate) {
      segments.push(1 === alreadySentCount ? `1 déjà envoyé le ${lastSentDate}` : `${alreadySentCount} déjà envoyés le ${lastSentDate}`);
    }
    return segments.join(" · ");
  },
  clearedDetail: "plus aucun conflit — une liste lui avait été envoyée",
  willSend: "recevra un e-mail",
  willSendFull: "recevra sa liste complète",
  willNotSendNothingNew: "rien de nouveau — ne recevra rien",
  willNotSendNoAddress: "non envoyé : pas d'adresse valide",
  emailAria: (fullName: string): string => `Adresse e-mail de ${fullName}`,
  emailPlaceholder: "adresse e-mail",
  emailSaved: "Adresse enregistrée.",
  /** « 4 e-mails partiront · 1 coach sans adresse valide ne recevra rien » ; « Aucun e-mail ne partira. » si 0. */
  previewSummary: ({ mails, noAddress, resendAll }: { mails: number; noAddress: number; resendAll: boolean }): string => {
    if (0 === mails) {
      return "Aucun e-mail ne partira.";
    }
    const head = `${1 === mails ? "1 e-mail partira" : `${mails} e-mails partiront`}${resendAll ? " (tout est renvoyé)" : ""}`;
    if (0 === noAddress) {
      return head;
    }
    return `${head} · ${1 === noAddress ? "1 coach sans adresse valide ne recevra rien" : `${noAddress} coachs sans adresse valide ne recevront rien`}`;
  },
  resendAllLabel: "Tout renvoyer — chaque coach concerné reçoit sa liste complète, même sans nouveauté.",
  blockedNoAddress: "Rien ne partira : aucun coach concerné n'a d'adresse valide.",
  blockedNothingNew: "Rien ne partira : rien de nouveau depuis le dernier envoi. Cochez « Tout renvoyer » pour renvoyer les listes.",
  blockedMixed: "Rien ne partira : rien de nouveau pour les coachs qui ont une adresse, et les autres n'en ont pas. Ajoutez une adresse ou cochez « Tout renvoyer ».",
  confirmDisabledRefreshing: "Mise à jour de l'aperçu…",
  confirmDisabledSaving: "Enregistrement de l'adresse…",
  previewLoadFailed: "L'aperçu n'a pas pu être chargé.",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;

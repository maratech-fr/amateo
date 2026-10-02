import { api } from "@/shared/api/client";

/**
 * La « boîte aux lettres » d'un club à horloge simulée (P4-16) : les e-mails qu'un club réel
 * aurait reçus, interceptés au lieu d'être envoyés tant que le club vit à une date simulée.
 * Lecture seule ; le club vient du JWT (tenant), jamais d'un input.
 */
export interface MailboxSummary {
  id: string;
  /** Expéditeur (adresse brute, ex. `Amateo <noreply@…>`). */
  from: string;
  /** Destinataire(s), joints par « , ». */
  to: string;
  subject: string;
  /** Le jour SIMULÉ du club au moment de l'interception (YYYY-MM-DD). */
  simulatedDate: string;
  /** Instant RÉEL d'écriture (ISO) — l'ordre de la boîte. */
  createdAt: string;
}

export interface MailboxListResponse {
  messages: MailboxSummary[];
  count: number;
}

export interface MailboxMessage extends MailboxSummary {
  /** Corps texte (le texte métier, capté avant la signature de marque). */
  bodyText: string | null;
  /** Corps HTML quand présent (en général nul à l'enfilage). */
  bodyHtml: string | null;
}

export const getMailbox = (): Promise<MailboxListResponse> => api.get("mailbox").json();

export const getMailboxMessage = (id: string): Promise<MailboxMessage> => api.get(`mailbox/${id}`).json();

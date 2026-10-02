import { Inbox, Mail } from "lucide-react";
import { useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { PageHeader } from "@/shared/components/ui/page-header";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";

import type { MailboxMessage } from "./api";
import { useMailbox, useMailboxMessage } from "./queries";

/**
 * L'écran « Boîte aux lettres » (P4-16, /boite-aux-lettres) : les e-mails qu'un club réel aurait
 * reçus, interceptés tant que le club vit à une horloge simulée. Liste à gauche (expéditeur,
 * destinataire, sujet, date simulée) + détail à droite (corps). Lecture seule.
 *
 * L'entrée de nav n'apparaît que pour un club à horloge ; un club réel n'a jamais de ligne ici.
 * Lecture react-query lue à la `readState` (inlinée pour que TS affine `data`) : `undefined` =
 * pas encore de donnée → chargement, ou échec s'il n'y a rien en cache ; sinon on a la liste,
 * même périmée (un refetch d'arrière-plan raté n'efface pas l'écran).
 */
export function MailboxPage() {
  const query = useMailbox();
  const [selectedId, setSelectedId] = useState<string | null>(null);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Boîte aux lettres"
        screen="/boite-aux-lettres"
        subtitle="Les e-mails interceptés tant que ce club vit à une date simulée — rien n'est envoyé pour de vrai."
      />

      <NoticeBanner
        tone="accent"
        role="note"
        message="Ce club est à l'heure simulée : aucun e-mail ne part réellement. Les relances et rappels qu'il aurait reçus sont rangés ici."
      />

      {renderList()}
    </div>
  );

  function renderList() {
    if (undefined === query.data) {
      return query.isError ? (
        <LoadErrorHint onRetry={() => void query.refetch()}>La boîte aux lettres n'a pas pu être chargée.</LoadErrorHint>
      ) : (
        <FullPageSpinner />
      );
    }

    if (0 === query.data.messages.length) {
      return (
        <EmptyState
          icon={Inbox}
          title="Boîte vide"
          description="Aucun e-mail intercepté pour l'instant — avancez l'horloge et déclenchez une relance pour en voir arriver."
        />
      );
    }

    return (
      <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_1fr]">
        <ul className="space-y-2" aria-label="Messages interceptés">
          {query.data.messages.map((message) => (
            <li key={message.id}>
              <button
                type="button"
                onClick={() => setSelectedId(message.id)}
                aria-pressed={selectedId === message.id}
                className={cn(
                  "w-full rounded-lg border p-3 text-left transition-colors",
                  selectedId === message.id ? "border-accent bg-surface-accent" : "border-border bg-card hover:border-accent/60",
                )}
              >
                <p className="truncate text-sm font-semibold text-foreground">{message.subject}</p>
                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                  De {message.from} · à {message.to}
                </p>
                <div className="mt-1.5">
                  <StatusPill variant="neutral">{formatDate(message.simulatedDate)}</StatusPill>
                </div>
              </button>
            </li>
          ))}
        </ul>

        <MailboxDetail id={selectedId} />
      </div>
    );
  }
}

function MailboxDetail({ id }: { id: string | null }) {
  const query = useMailboxMessage(id);

  if (null == id) {
    return <EmptyState icon={Mail} title="Aucun message sélectionné" description="Choisissez un e-mail à gauche pour lire son contenu." />;
  }

  const message = query.data;
  if (undefined === message) {
    return query.isError ? (
      <LoadErrorHint onRetry={() => void query.refetch()}>Ce message n'a pas pu être chargé.</LoadErrorHint>
    ) : (
      <FullPageSpinner />
    );
  }

  return (
    <article className="space-y-4 rounded-lg border border-border bg-card p-4">
      <header className="space-y-1 border-b border-border pb-3">
        <h2 className="text-lg font-semibold text-foreground">{message.subject}</h2>
        <p className="text-sm text-muted-foreground">De {message.from}</p>
        <p className="text-sm text-muted-foreground">À {message.to}</p>
        <div>
          <StatusPill variant="neutral">{formatDate(message.simulatedDate)}</StatusPill>
        </div>
      </header>
      <MailboxBody message={message} />
    </article>
  );
}

/**
 * Rendu SÛR du corps : on privilégie le texte (`whitespace-pre-line`). Un corps HTML (rare) n'est
 * JAMAIS injecté via `dangerouslySetInnerHTML` sur du contenu non assaini — il est rendu dans un
 * `<iframe sandbox>` SANS `allow-scripts` (ni scripts, ni même origine), un bac à sable inerte.
 */
function MailboxBody({ message }: { message: MailboxMessage }) {
  if (null != message.bodyText && "" !== message.bodyText) {
    return <p className="whitespace-pre-line text-sm text-foreground">{message.bodyText}</p>;
  }
  if (null != message.bodyHtml && "" !== message.bodyHtml) {
    return <iframe title="Corps de l'e-mail" sandbox="" srcDoc={message.bodyHtml} className="h-96 w-full rounded border border-border bg-white" />;
  }

  return <p className="text-sm text-muted-foreground">(Message sans corps.)</p>;
}

const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "long", year: "numeric" });

function formatDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

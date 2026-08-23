import { useEffect, useRef, useState } from "react";
import { Link } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";

import { useMarkReleaseNotesSeen, useReleaseNotes } from "./queries";

/**
 * P5-12 — la modale « quoi de neuf ». Elle s'ouvre UNIQUEMENT s'il existe une
 * note publiée APRÈS le filigrane de lecture (`publishedAt > seenUpTo`) ET que
 * le membre a déjà un filigrane (`seenUpTo !== null`).
 *
 * Nouvel inscrit (`seenUpTo` null) : on POSE le filigrane en silence au premier
 * chargement (POST /seen) sans rien afficher — sinon tout l'historique
 * s'ouvrirait d'un coup à la première connexion. À partir de là, seule une note
 * publiée plus tard déclenchera la modale.
 *
 * Le corps est du TEXTE BRUT (`whitespace-pre-line`), jamais de markdown/html.
 */
export function WhatsNewModal() {
  const { data } = useReleaseNotes();
  const markSeen = useMarkReleaseNotesSeen();
  const [dismissed, setDismissed] = useState(false);
  const baselined = useRef(false);

  const seenUpTo = data?.seenUpTo ?? null;

  useEffect(() => {
    // Premier chargement d'un nouvel inscrit : filigrane posé, aucune modale.
    if (data && null === seenUpTo && !baselined.current) {
      baselined.current = true;
      markSeen.mutate();
    }
  }, [data, seenUpTo, markSeen]);

  if (!data || null === seenUpTo || dismissed) return null;

  const seenTime = new Date(seenUpTo).getTime();
  const fresh = data.items.filter((item) => new Date(item.publishedAt).getTime() > seenTime);
  if (0 === fresh.length) return null;

  return (
    <Modal
      label="Quoi de neuf"
      title="Quoi de neuf ?"
      onClose={() => setDismissed(true)}
      // Rangée en `justify-between` (lien à gauche, action à droite) : un enfant `w-full`
      // remplit le pied `justify-end` de la primitive et préserve exactement cette disposition.
      footer={
        <div className="flex w-full items-center justify-between gap-3">
          <Link to="/nouveautes" className="text-sm text-accent underline" onClick={() => setDismissed(true)}>
            Tout voir
          </Link>
          <Button type="button" disabled={markSeen.isPending} onClick={() => markSeen.mutate(undefined, { onSuccess: () => setDismissed(true) })}>
            J'ai compris
          </Button>
        </div>
      }
    >
      <ul className="mt-4 space-y-4">
        {fresh.map((item) => (
          <li key={item.id} className="border-b border-border pb-4 last:border-b-0">
            <p className="text-sm font-semibold">{item.title}</p>
            <p className="mt-0.5 text-xs text-muted-foreground">{formatDate(item.date)}</p>
            <p className="mt-2 whitespace-pre-line text-sm text-foreground">{item.body}</p>
          </li>
        ))}
      </ul>
    </Modal>
  );
}

const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "long", year: "numeric" });

function formatDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

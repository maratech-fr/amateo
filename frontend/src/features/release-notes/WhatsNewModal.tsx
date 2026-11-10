import { useEffect, useRef, useState } from "react";
import { Link } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { RichText } from "@/shared/components/ui/rich-text";

import { useMarkReleaseNotesSeen, useReleaseNotes } from "./queries";
import { ReleaseNoteHeading } from "./ReleaseNoteHeading";
import { RELEASE_NOTES_TEXTS } from "./texts";

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
 * Une SEULE note est montrée : la plus récemment PUBLIÉE (la date éditoriale est
 * antidatable, elle ne trie pas) ; les autres fraîches sont comptées en pied, lisibles
 * par « Voir tout ». TOUTE sortie (croix, Échap, clic sur le fond, « Voir tout »,
 * « J'ai compris ») enregistre la lecture et ferme aussitôt — un échec du POST est
 * toasté par le filet global et la modale revient au prochain chargement.
 *
 * Corps : `RichText` (éléments React, titres au niveau 4 sous le `<h3>` de la note) pour une note
 * riche ; texte brut `whitespace-pre-line` pour une note texte (`bodyRich` null). Jamais de HTML.
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
  const fresh = data.items
    .filter((item) => new Date(item.publishedAt).getTime() > seenTime)
    .sort((a, b) => new Date(b.publishedAt).getTime() - new Date(a.publishedAt).getTime());
  const note = fresh[0];
  if (undefined === note) return null;
  const others = fresh.length - 1;

  const close = () => {
    markSeen.mutate();
    setDismissed(true);
  };

  return (
    <Modal
      label={RELEASE_NOTES_TEXTS.modalLabel}
      title={RELEASE_NOTES_TEXTS.modalTitle}
      onClose={close}
      // Rangée en `justify-between` (compteur + lien à gauche, action à droite) : un enfant
      // `w-full` remplit le pied `justify-end` de la primitive et préserve cette disposition.
      footer={
        <div className="flex w-full items-center justify-between gap-3">
          <p className="text-sm text-muted-foreground">
            {others >= 1 ? <><span>{RELEASE_NOTES_TEXTS.modalOthers(others)}</span>{" · "}</> : null}
            <Link to="/nouveautes" className="text-accent underline" onClick={close}>
              {RELEASE_NOTES_TEXTS.modalSeeAll}
            </Link>
          </p>
          <Button type="button" onClick={close}>
            {RELEASE_NOTES_TEXTS.modalGotIt}
          </Button>
        </div>
      }
    >
      <article className="mt-4">
        <ReleaseNoteHeading date={note.date} version={note.version} />
        <h3 className="mt-0.5 text-sm font-semibold">{note.title}</h3>
        {null !== note.bodyRich ? (
          <RichText doc={note.bodyRich} headingLevel={4} className="mt-2 text-sm text-foreground" />
        ) : (
          <p className="mt-2 whitespace-pre-line text-sm text-foreground">{note.body}</p>
        )}
      </article>
    </Modal>
  );
}

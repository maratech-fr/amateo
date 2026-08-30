import { type FormEvent, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Spinner } from "@/shared/components/ui/spinner";
import { toast } from "@/shared/stores/toastStore";

import type { AdminReleaseNote } from "../api";
import { useAdminReleaseNotes, useCreateAdminReleaseNote, useDeleteAdminReleaseNote, usePublishAdminReleaseNote } from "../queries";

const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", year: "numeric" });

/** Date du jour au format AAAA-MM-JJ, valeur par défaut du champ éditorial. */
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/**
 * P5-12 — l'atelier du journal de nouveautés (onglet Référentiels). Liste les
 * notes (brouillons + publiées), un formulaire titre/date/corps pour en créer,
 * et par ligne : Publier (brouillon) + Supprimer.
 */
export function ReleaseNotesSection() {
  const notes = useAdminReleaseNotes();
  const create = useCreateAdminReleaseNote();
  const publish = usePublishAdminReleaseNote();
  const remove = useDeleteAdminReleaseNote();

  const [title, setTitle] = useState("");
  const [noteDate, setNoteDate] = useState(today());
  const [body, setBody] = useState("");

  const canSubmit = "" !== title.trim() && "" !== body.trim() && "" !== noteDate && !create.isPending;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canSubmit) return;
    create.mutate(
      { title: title.trim(), body: body.trim(), noteDate },
      {
        onSuccess: () => {
          toast.success("Note enregistrée (brouillon).");
          setTitle("");
          setBody("");
          setNoteDate(today());
        },
        onError: () => toast.error("Impossible d'enregistrer la note."),
      },
    );
  }

  const items = notes.data?.items ?? [];

  return (
    <section aria-labelledby="release-notes-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Communication</p>
        <h2 id="release-notes-heading" className="mt-2 text-xl font-semibold text-white">Journal de nouveautés</h2>
        <p className="mt-1 text-sm text-console-text-dim">Ce que les membres voient dans « Nouveautés » et la modale « quoi de neuf ». Un brouillon reste invisible jusqu'à sa publication.</p>
      </div>

      <form onSubmit={submit} className="space-y-3 rounded-xl border border-white/10 bg-white/5 p-4">
        <div className="grid gap-3 md:grid-cols-[1fr_auto]">
          <div className="space-y-1">
            <label htmlFor="release-note-title" className="block text-xs text-console-text-dim">Titre</label>
            <input
              id="release-note-title"
              type="text"
              maxLength={160}
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              className="h-10 w-full rounded-md border border-white/15 bg-white/[0.04] px-3 text-sm text-white outline-none placeholder:text-console-text-faint focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20"
            />
          </div>
          <div className="space-y-1">
            <label htmlFor="release-note-date" className="block text-xs text-console-text-dim">Date</label>
            <input
              id="release-note-date"
              type="date"
              value={noteDate}
              onChange={(event) => setNoteDate(event.target.value)}
              className="h-10 rounded-md border border-white/15 bg-white/[0.04] px-3 text-sm text-white outline-none focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20"
            />
          </div>
        </div>
        <div className="space-y-1">
          <label htmlFor="release-note-body" className="block text-xs text-console-text-dim">Contenu</label>
          <textarea
            id="release-note-body"
            rows={4}
            value={body}
            onChange={(event) => setBody(event.target.value)}
            className="w-full rounded-md border border-white/15 bg-white/[0.04] px-3 py-2 text-sm text-white outline-none placeholder:text-console-text-faint focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20"
          />
        </div>
        <div className="flex justify-end">
          <Button type="submit" className="bg-console-accent text-console-surface hover:bg-console-accent-hover" disabled={!canSubmit}>
            {create.isPending ? <Spinner className="size-4" /> : null}
            Enregistrer
          </Button>
        </div>
      </form>

      {notes.isPending ? (
        <p className="flex items-center gap-2 text-sm text-console-text-dim"><Spinner className="size-4" /> Chargement…</p>
      ) : notes.isError ? (
        <p className="text-sm text-console-destructive">
          La liste n'a pas pu être lue.{" "}
          <button type="button" className="underline" onClick={() => void notes.refetch()}>Réessayer</button>
        </p>
      ) : 0 === items.length ? (
        <p className="text-sm text-console-text-dim">Aucune note pour le moment.</p>
      ) : (
        <ul className="divide-y divide-white/10 rounded-xl border border-white/10 bg-white/[0.03]">
          {items.map((note) => (
            <ReleaseNoteRow
              key={note.id}
              note={note}
              publishing={publish.isPending && publish.variables === note.id}
              removing={remove.isPending && remove.variables === note.id}
              onPublish={() => publish.mutate(note.id, { onError: () => toast.error("Impossible de publier.") })}
              onRemove={() => remove.mutate(note.id, { onError: () => toast.error("Impossible de supprimer.") })}
            />
          ))}
        </ul>
      )}
    </section>
  );
}

function ReleaseNoteRow({ note, publishing, removing, onPublish, onRemove }: {
  note: AdminReleaseNote;
  publishing: boolean;
  removing: boolean;
  onPublish: () => void;
  onRemove: () => void;
}) {
  return (
    <li className="flex flex-col gap-2 p-4 md:flex-row md:items-start md:justify-between">
      <div className="min-w-0">
        <p className="flex items-center gap-2 text-sm font-medium text-white">
          {note.title}
          {null === note.publishedAt ? (
            <span className="rounded bg-console-muted/20 px-1.5 py-0.5 text-xs font-semibold uppercase text-console-text">Brouillon</span>
          ) : (
            <span className="rounded bg-console-success-tint/15 px-1.5 py-0.5 text-xs font-semibold uppercase text-console-success">Publiée</span>
          )}
        </p>
        <p className="mt-0.5 text-xs text-console-muted">{formatDate(note.date)}</p>
        <p className="mt-2 whitespace-pre-line text-sm text-console-text">{note.body}</p>
      </div>
      <div className="flex shrink-0 gap-2">
        {null === note.publishedAt ? (
          <Button type="button" size="sm" className="bg-console-accent text-console-surface hover:bg-console-accent-hover" disabled={publishing} onClick={onPublish}>
            {publishing ? <Spinner className="size-3.5" /> : null}
            Publier
          </Button>
        ) : null}
        <Button type="button" size="sm" variant="outline" className="border-console-destructive-edge/40 text-console-destructive hover:bg-console-destructive-surface/10" disabled={removing} onClick={onRemove}>
          Supprimer
        </Button>
      </div>
    </li>
  );
}

function formatDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : dateFormatter.format(date);
}

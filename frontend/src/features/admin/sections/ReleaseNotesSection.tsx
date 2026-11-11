import { type FormEvent, useState } from "react";

import { ReleaseNoteHeading } from "@/features/release-notes/ReleaseNoteHeading";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { RichText } from "@/shared/components/ui/rich-text";
import { Spinner } from "@/shared/components/ui/spinner";
import { APP_VERSION } from "@/shared/lib/appVersion";
import type { RichDoc } from "@/shared/lib/richDoc";
import { toast } from "@/shared/stores/toastStore";

import type { AdminReleaseNote } from "../api";
import { useAdminReleaseNotes, useCreateAdminReleaseNote, useDeleteAdminReleaseNote, usePublishAdminReleaseNote } from "../queries";
import { ReleaseNoteEditor } from "../releaseNoteEditor/ReleaseNoteEditor";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";

/** Date du jour au format AAAA-MM-JJ, valeur par défaut du champ éditorial. */
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/**
 * La version en service, si le bundle la connaît sous forme de numéro (« v1.3.5 » → « 1.3.5 ») ;
 * vide sinon (« dev », un sha de commit). Le serveur normalise et valide de toute façon.
 */
function defaultVersion(): string {
  const match = /^v?(\d+(?:\.\d+){1,3})$/.exec(APP_VERSION);
  return match?.[1] ?? "";
}

/** Vrai si le document porte au moins un caractère non blanc (un document vide ne s'enregistre pas). */
function hasText(node: { text?: string; content?: unknown[] } | null): boolean {
  if (null === node) return false;
  if ("string" === typeof node.text && "" !== node.text.trim()) return true;
  return (node.content ?? []).some((child) => hasText(child as { text?: string; content?: unknown[] }));
}

/**
 * P5-12 — l'atelier du journal de nouveautés (onglet Référentiels). Liste les
 * notes (brouillons + publiées), un formulaire titre/date/version/corps pour en créer,
 * et par ligne : Publier (brouillon) + Supprimer.
 *
 * Le corps se saisit dans l'éditeur riche (`ReleaseNoteEditor`) ; seul `bodyRich` part au
 * serveur, qui en dérive le texte brut `body`. L'aperçu et les lignes rendent le corps en peau
 * CONSOLE (`RichText variant="console"`) ; une note texte (`bodyRich` null) reste en texte brut.
 * Le `<label>` du corps porte l'id `release-note-body-label` : la zone éditable (contenteditable,
 * non « labelable ») s'y nomme par `aria-labelledby`.
 */
export function ReleaseNotesSection() {
  const notes = useAdminReleaseNotes();
  const create = useCreateAdminReleaseNote();
  const publish = usePublishAdminReleaseNote();
  const remove = useDeleteAdminReleaseNote();

  const [title, setTitle] = useState("");
  const [noteDate, setNoteDate] = useState(today());
  const [bodyRich, setBodyRich] = useState<RichDoc | null>(null);
  // Remonte l'éditeur (document vide) après un enregistrement : `value` n'est lu qu'au montage.
  const [editorKey, setEditorKey] = useState(0);
  const [version, setVersion] = useState(defaultVersion);

  const missing = { title: "" === title.trim(), date: "" === noteDate, content: !hasText(bodyRich) };
  const incomplete = missing.title || missing.date || missing.content;
  const canSubmit = !incomplete && !create.isPending;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canSubmit || null === bodyRich) return;
    const trimmedVersion = version.trim();
    create.mutate(
      // Champ vide = note sans version : la clé n'est pas envoyée. Le texte brut est dérivé serveur.
      { title: title.trim(), bodyRich, noteDate, ...("" !== trimmedVersion ? { version: trimmedVersion } : {}) },
      {
        onSuccess: () => {
          toast.success(ADMIN_SECTIONS_TEXTS.releaseNotesToastSaved);
          setTitle("");
          setBodyRich(null);
          setEditorKey((key) => key + 1);
          setNoteDate(today());
          setVersion(defaultVersion());
        },
      },
    );
  }

  const items = notes.data?.items ?? [];

  return (
    <section aria-labelledby="release-notes-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_SECTIONS_TEXTS.releaseNotesEyebrow}</p>
        <h2 id="release-notes-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_SECTIONS_TEXTS.releaseNotesHeading}</h2>
        <p className="mt-1 text-sm text-console-text-dim">{ADMIN_SECTIONS_TEXTS.releaseNotesDescription}</p>
      </div>

      <form onSubmit={submit} className="space-y-3 rounded-xl border border-white/10 bg-white/5 p-4">
        <div className="grid gap-3 md:grid-cols-[1fr_auto]">
          <div className="space-y-1">
            <label htmlFor="release-note-title" className="block text-xs text-console-text-dim">{ADMIN_SECTIONS_TEXTS.releaseNotesTitleLabel}</label>
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
            <label htmlFor="release-note-date" className="block text-xs text-console-text-dim">{ADMIN_SECTIONS_TEXTS.releaseNotesDateLabel}</label>
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
          <label htmlFor="release-note-version" className="block text-xs text-console-text-dim">{ADMIN_SECTIONS_TEXTS.releaseNotesVersionLabel}</label>
          <input
            id="release-note-version"
            type="text"
            maxLength={40}
            value={version}
            onChange={(event) => setVersion(event.target.value)}
            aria-describedby="release-note-version-hint"
            className="h-10 w-full max-w-48 rounded-md border border-white/15 bg-white/[0.04] px-3 text-sm text-white outline-none placeholder:text-console-text-faint focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20"
          />
          <p id="release-note-version-hint" className="text-xs text-console-text-dim">{ADMIN_SECTIONS_TEXTS.releaseNotesVersionHint}</p>
        </div>
        <div className="space-y-1">
          <label id="release-note-body-label" htmlFor="release-note-body" className="block text-xs text-console-text-dim">{ADMIN_SECTIONS_TEXTS.releaseNotesBodyLabel}</label>
          <ReleaseNoteEditor key={editorKey} id="release-note-body" value={null} onChange={setBodyRich} />
        </div>
        {null !== bodyRich ? (
          <section aria-labelledby="release-note-preview-label" className="space-y-1">
            <p id="release-note-preview-label" className="text-xs text-console-text-dim">{ADMIN_SECTIONS_TEXTS.releaseNotesPreview}</p>
            <RichText doc={bodyRich} variant="console" className="rounded-md border border-white/10 bg-white/[0.03] p-3 text-sm text-console-text" />
          </section>
        ) : null}
        <div className="flex justify-end">
          <Button type="submit" className="bg-console-accent text-console-surface hover:bg-console-accent-hover"
            disabled={!canSubmit}
            disabledReason={incomplete ? ADMIN_SECTIONS_TEXTS.releaseNotesSubmitMissing(missing) : undefined}
          >
            {create.isPending ? <Spinner className="size-4" /> : null}
            {ADMIN_SECTIONS_TEXTS.releaseNotesSubmit}
          </Button>
        </div>
      </form>

      {notes.isPending ? (
        <p className="flex items-center gap-2 text-sm text-console-text-dim"><Spinner className="size-4" /> {ADMIN_SECTIONS_TEXTS.loading}</p>
      ) : notes.isError ? (
        <p className="text-sm text-console-destructive">
          {ADMIN_SECTIONS_TEXTS.listReadError}{" "}
          <button type="button" className="underline" onClick={() => void notes.refetch()}>{ADMIN_SECTIONS_TEXTS.retry}</button>
        </p>
      ) : 0 === items.length ? (
        <EmptyHint variant="console">{ADMIN_SECTIONS_TEXTS.releaseNotesEmpty}</EmptyHint>
      ) : (
        <ul className="divide-y divide-white/10 rounded-xl border border-white/10 bg-white/[0.03]">
          {items.map((note) => (
            <ReleaseNoteRow
              key={note.id}
              note={note}
              publishing={publish.isPending && publish.variables === note.id}
              removing={remove.isPending && remove.variables === note.id}
              onPublish={() => publish.mutate(note.id)}
              onRemove={() => remove.mutate(note.id)}
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
        <ReleaseNoteHeading date={note.date} version={note.version} variant="console" />
        <p className="mt-0.5 flex items-center gap-2 text-lg font-semibold text-white">
          {note.title}
          {null === note.publishedAt ? (
            <span className="rounded bg-console-muted/20 px-1.5 py-0.5 text-xs font-semibold uppercase text-console-text">{ADMIN_SECTIONS_TEXTS.releaseNotesBadgeDraft}</span>
          ) : (
            <span className="rounded bg-console-success-tint/15 px-1.5 py-0.5 text-xs font-semibold uppercase text-console-success">{ADMIN_SECTIONS_TEXTS.releaseNotesBadgePublished}</span>
          )}
        </p>
        {null !== note.bodyRich ? (
          <RichText doc={note.bodyRich} variant="console" className="mt-2 text-sm text-console-text" />
        ) : (
          <p className="mt-2 whitespace-pre-line text-sm text-console-text">{note.body}</p>
        )}
      </div>
      <div className="flex shrink-0 gap-2">
        {null === note.publishedAt ? (
          <Button type="button" size="sm" className="bg-console-accent text-console-surface hover:bg-console-accent-hover" disabled={publishing} onClick={onPublish}>
            {publishing ? <Spinner className="size-3.5" /> : null}
            {ADMIN_SECTIONS_TEXTS.releaseNotesPublish}
          </Button>
        ) : null}
        <Button type="button" size="sm" variant="outline" className="border-console-destructive-edge/40 text-console-destructive hover:bg-console-destructive-surface/10" disabled={removing} onClick={onRemove}>
          {ADMIN_SECTIONS_TEXTS.releaseNotesDelete}
        </Button>
      </div>
    </li>
  );
}

import { Crop, ImagePlus, Trash2 } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { useMe } from "@/shared/session/queries";
import type { FfbbOrganisme, MeResponse } from "@/shared/session/api";
import { PendingMembersSection } from "@/features/auth/PendingMembersSection";
import { MembersSection } from "@/features/club/MembersSection";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { FichePage } from "@/shared/components/ui/fiche-page";
import { Input } from "@/shared/components/ui/input";
import { FullPageSpinner, Spinner } from "@/shared/components/ui/spinner";
import { useCredits } from "@/shared/credits/useCredits";
import { readableForeground } from "@/shared/lib/color";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";
import { extractPalette } from "@/shared/lib/palette";

import type { SubscriptionPlan, UsageDayHours } from "./api";
import { LogoCropper } from "./LogoCropper";
import { formatHours } from "./lib/venueStats";
import { useDeleteLogo, useDownloadClubExport, useFfbbImport, useResetClub, useSubscriptionPlans, useUpdateAppearance, useUploadLogo, useVenueUsageStats } from "./queries";
import { isManagementRole } from "@/shared/lib/roles";

const HEX = /^#[0-9a-fA-F]{6}$/;
const DEFAULT_ACCENT = "#3b82f6";

/** One accent picker (swatch + hex input + filled preview) for a given theme. */
function AccentField({ id, label, value, onChange }: { id: string; label: string; value: string; onChange: (v: string) => void }) {
  const valid = HEX.test(value);
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-xs text-muted-foreground">
        {label}
      </label>
      <div className="flex items-center gap-2">
        <input
          id={id}
          aria-label={`Couleur d'accent (${label})`}
          type="color"
          className="size-9 shrink-0 rounded border border-input bg-background"
          value={valid ? value : DEFAULT_ACCENT}
          onChange={(e) => onChange(e.target.value)}
        />
        <Input aria-label={`Couleur ${label} (hexadécimal)`} className="h-9 w-28 font-mono text-xs" value={value} placeholder="#3b82f6" onChange={(e) => onChange(e.target.value)} />
        {/* Filled swatch → the accent stays legible whatever its lightness. */}
        <div className="rounded-md px-3 py-2 text-sm font-medium" style={valid ? { backgroundColor: value, color: readableForeground(value) } : undefined}>
          Aa
        </div>
      </div>
    </div>
  );
}

function IdentitySection({
  accentColor,
  accentColorDark,
  accentPalette,
  logoUrl,
  clubName,
}: {
  accentColor: string | null;
  accentColorDark: string | null;
  accentPalette: string[] | null;
  logoUrl: string | null;
  clubName: string;
}) {
  const updateAppearance = useUpdateAppearance();
  const uploadLogo = useUploadLogo();
  const deleteLogo = useDeleteLogo();

  const [color, setColor] = useState(accentColor ?? DEFAULT_ACCENT);
  // Dark-theme accent defaults to the light one until the manager picks a distinct one.
  const [colorDark, setColorDark] = useState(accentColorDark ?? accentColor ?? DEFAULT_ACCENT);
  const [palette, setPalette] = useState<string[]>(accentPalette ?? []);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(logoUrl);
  const [cropFile, setCropFile] = useState<File | null>(null);

  const valid = HEX.test(color) && HEX.test(colorDark);
  const busy = uploadLogo.isPending || updateAppearance.isPending || deleteLogo.isPending;

  const onCropped = async (blob: Blob) => {
    const cropped = new File([blob], "logo.png", { type: "image/png" });
    setFile(cropped);
    setCropFile(null);
    const objectUrl = URL.createObjectURL(cropped);
    setPreview(objectUrl);
    const pal = await extractPalette(objectUrl);
    if (pal.length > 0) {
      setPalette(pal);
      setColor(pal[0]);
    }
  };

  const save = async () => {
    if (null !== file) {
      await uploadLogo.mutateAsync(file);
      setFile(null);
    }
    await updateAppearance.mutateAsync({ accentColor: color, accentColorDark: colorDark, ...(palette.length > 0 ? { accentPalette: palette } : {}) });
  };

  const removeLogo = () => {
    deleteLogo.mutate();
    setPreview(null);
    setFile(null);
    setPalette([]);
  };

  // Re-open the cropper on the CURRENT logo (fetch it back into a File).
  const recrop = async () => {
    if (null === preview) {
      return;
    }
    const res = await fetch(preview);
    const blob = await res.blob();
    setCropFile(new File([blob], "logo.png", { type: blob.type || "image/png" }));
  };

  return (
    <div className="space-y-5">
      <p className="text-sm text-muted-foreground">Logo et couleur d'accent du club. La couleur personnalise les boutons, liens et éléments actifs de toute l'interface.</p>
      {/* Logo — cropper when a new file is being framed, else preview + actions */}
        {null !== cropFile ? (
          <LogoCropper file={cropFile} onCropped={onCropped} onCancel={() => setCropFile(null)} />
        ) : (
          <div className="flex items-center gap-4">
            <button
              type="button"
              onClick={() => void recrop()}
              disabled={null === preview}
              title={null !== preview ? "Recadrer le logo" : undefined}
              className="flex size-16 items-center justify-center overflow-hidden rounded-full border border-border bg-muted text-lg font-bold text-muted-foreground enabled:cursor-pointer enabled:hover:opacity-90"
            >
              {null !== preview ? <img src={preview} alt="Logo" className="size-full object-cover" /> : clubName.trim().charAt(0).toUpperCase() || "C"}
            </button>
            <div className="flex flex-wrap items-center gap-2">
              <label className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 py-1.5 text-sm hover:bg-muted">
                <ImagePlus className="size-4" />
                Choisir un logo
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  className="hidden"
                  onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (undefined !== f) {
                      setCropFile(f);
                    }
                    e.target.value = "";
                  }}
                />
              </label>
              {null !== preview ? (
                <Button size="sm" variant="outline" onClick={() => void recrop()} disabled={busy}>
                  <Crop className="size-4" />
                  Recadrer
                </Button>
              ) : null}
              {null !== logoUrl ? (
                <Button size="sm" variant="ghost" className="text-destructive" onClick={removeLogo} disabled={busy}>
                  <Trash2 className="size-4" />
                  Retirer
                </Button>
              ) : null}
              <span className="text-xs text-muted-foreground">PNG / JPEG / WebP, ≤ 500 Ko.</span>
            </div>
          </div>
        )}

        {/* Suggested palette from the logo */}
        {palette.length > 0 ? (
          <div className="flex items-center gap-2">
            <span className="text-xs text-muted-foreground">Couleurs du logo :</span>
            {palette.map((hex) => (
              <button
                key={hex}
                type="button"
                title={`Utiliser ${hex}`}
                aria-label={`Utiliser ${hex}`}
                onClick={() => setColor(hex)}
                className="size-6 rounded-full border border-border"
                style={{ backgroundColor: hex }}
              />
            ))}
          </div>
        ) : null}

        {/* Accent — one colour per theme (light + dark), applied by useApplyClubTheme. */}
        <div>
          <p className="mb-2 text-xs text-muted-foreground">Couleur d'accent — une pour le thème clair, une pour le sombre. Elle habille boutons, liens et éléments actifs de toute l'interface.</p>
          <div className="flex flex-wrap gap-4">
            <AccentField id="accent-light" label="Thème clair" value={color} onChange={setColor} />
            <AccentField id="accent-dark" label="Thème sombre" value={colorDark} onChange={setColorDark} />
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button onClick={save} disabled={!valid || busy}>
            {busy ? <Spinner className="size-4" /> : null}
            Enregistrer
          </Button>
          {(null !== accentColor || null !== accentColorDark) ? (
            <Button
              variant="ghost"
              onClick={() => {
                // Reset the server AND the local pickers, else a subsequent Save
                // re-persists the old colours the fields still show.
                setColor(DEFAULT_ACCENT);
                setColorDark(DEFAULT_ACCENT);
                updateAppearance.mutate({ accentColor: null, accentColorDark: null });
              }}
              disabled={busy}
            >
              Réinitialiser les couleurs
            </Button>
          ) : null}
        </div>
    </div>
  );
}

function DangerSection() {
  const reset = useResetClub();
  const navigate = useNavigate();
  const [confirm, setConfirm] = useState(false);

  return (
    <div className="space-y-3">
      <p className="text-sm text-muted-foreground">
        Supprime définitivement toutes les données saisies (équipes, gymnases, coachs, contraintes, plannings) pour repartir de zéro. Le club et les
        comptes membres sont conservés.
      </p>
      <Button variant="destructive" onClick={() => setConfirm(true)} disabled={reset.isPending}>
        {reset.isPending ? <Spinner className="size-4" /> : <Trash2 className="size-4" />}
        Réinitialiser le club
      </Button>

      <ConfirmDialog
        open={confirm}
        title="Réinitialiser le club ?"
        description="Toutes les équipes, gymnases, coachs, contraintes et plannings seront définitivement supprimés. Cette action est irréversible."
        confirmLabel="Tout supprimer"
        onCancel={() => setConfirm(false)}
        onConfirm={() => {
          setConfirm(false);
          reset.mutate(undefined, { onSuccess: () => navigate("/wizard") });
        }}
      />
    </div>
  );
}

/** Une donnée FFBB en lecture seule — même gabarit que le Code FFBB. */
function ReadOnlyField({ label, value, mono }: { label: string; value: string | null; mono?: boolean }) {
  return (
    <div>
      <span className="mb-1 block text-xs text-muted-foreground">{label}</span>
      <p className={mono ? "font-mono" : undefined}>{value ?? "—"}</p>
    </div>
  );
}

/**
 * Informations du club — TOUT vient de la FFBB, rien ne se saisit ici (décision
 * fondateur 2026-08-04). Correspondant/président/salle principale ont été
 * SUPPRIMÉS : l'index organismes ne connaît ni personne physique ni lien
 * club→salle (cadrage api-ffbb-completion-club §1), et la saisie manuelle
 * n'était pas voulue. Le geste de correction est le ré-import.
 */
function ClubInfoSection({ club }: { club: NonNullable<MeResponse["club"]> }) {
  const ffbbImport = useFfbbImport();
  // Compact (retour fondateur) : un téléphone se reconnaît sans sous-titre —
  // les coordonnées s'empilent nues, comme sur les blocs Comité/Ligue.
  const contactLines = [
    [club.address, [club.postalCode, club.city].filter(Boolean).join(" ")].filter(Boolean).join(", ") || null,
    club.contactPhone,
  ].filter((line): line is string => null !== line);
  const website = safeHttpUrl(club.website);

  return (
    <div className="space-y-5">
      <div>
        <div className="mb-2 flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">Identité</h3>
          <Button size="sm" variant="outline" onClick={() => ffbbImport.mutate()} disabled={ffbbImport.isPending}>
            {ffbbImport.isPending ? <Spinner className="size-4" /> : null}
            Actualiser depuis la FFBB
          </Button>
        </div>
        <div className="grid grid-cols-2 gap-3 text-sm">
          <ReadOnlyField label="Code FFBB" value={club.ffbbClubCode} mono />
          <ReadOnlyField label="Ligue" value={club.league} />
          <ReadOnlyField label="Zone de vacances" value={club.schoolZone} />
          <ReadOnlyField label="Comité" value={club.committeeCode} mono />
        </div>
      </div>

      <div>
        <h3 className="mb-2 text-sm font-semibold">Contact du club</h3>
        {contactLines.length > 0 || null !== club.contactEmail || null !== website ? (
          <dl className="space-y-0.5 text-sm">
            {contactLines.map((line) => (
              <dd key={line}>{line}</dd>
            ))}
            {null !== club.contactEmail ? (
              <dd>
                <a href={`mailto:${club.contactEmail}`} className="text-accent underline underline-offset-2">
                  {club.contactEmail}
                </a>
              </dd>
            ) : null}
            {null !== website ? (
              <dd>
                <a href={website} target="_blank" rel="noreferrer" className="text-accent underline underline-offset-2">
                  {website}
                </a>
              </dd>
            ) : null}
          </dl>
        ) : (
          <p className="text-xs text-muted-foreground">Données FFBB non disponibles — essayez « Actualiser depuis la FFBB ».</p>
        )}
      </div>
    </div>
  );
}

/** Only allow http(s) links — an FFBB-sourced `javascript:`/`data:` URL must never reach href (XSS). */
function safeHttpUrl(url: string | null | undefined): string | null {
  if (!url) return null;
  try {
    const parsed = new URL(url);
    return parsed.protocol === "http:" || parsed.protocol === "https:" ? parsed.href : null;
  } catch {
    return null;
  }
}

/** One read-only FFBB contact block (Comité / Ligue).
 *  Pas d'étiquette « COMITÉ »/« LIGUE » et pas de troncature (retour fondateur
 *  2026-08-04) : la raison sociale complète — « COMITE DU RHONE… » — dit
 *  elle-même ce qu'elle est, mieux qu'un titre au-dessus d'un nom coupé. */
function ContactBlock({ data, fallbackName }: { data: FfbbOrganisme | null; fallbackName: string }) {
  const filled = data && (data.address || data.city || data.phone || data.email);
  const website = safeHttpUrl(data?.website);
  return (
    <div className="rounded-lg border border-border p-3">
      <div className="mb-2 flex items-start gap-2">
        {data?.logoUrl ? <img src={data.logoUrl} alt="" className="h-8 w-8 shrink-0 object-contain" /> : null}
        <p className="min-w-0 text-sm font-semibold leading-snug">{data?.name ?? fallbackName}</p>
      </div>
      {filled ? (
        <dl className="space-y-0.5 text-sm">
          {data.address ? <dd>{data.address}</dd> : null}
          {data.postalCode || data.city ? <dd>{[data.postalCode, data.city].filter(Boolean).join(" ")}</dd> : null}
          {data.phone ? <dd>{data.phone}</dd> : null}
          {data.email ? (
            <dd>
              <a href={`mailto:${data.email}`} className="text-accent underline underline-offset-2">
                {data.email}
              </a>
            </dd>
          ) : null}
          {website ? (
            <dd>
              <a href={website} target="_blank" rel="noreferrer" className="text-accent underline underline-offset-2">
                {website}
              </a>
            </dd>
          ) : null}
        </dl>
      ) : (
        <p className="text-xs text-muted-foreground">Données FFBB non disponibles.</p>
      )}
    </div>
  );
}

/** FFBB institutional contacts (lot C): Comité · Ligue, read-only.
 *  Plus de bloc « Club » (décision fondateur 2026-08-04) : cette section ne
 *  montre que la hiérarchie AU-DESSUS du club — ses propres coordonnées vivent
 *  dans « Informations du club », les dupliquer ici créait deux vérités. */
function ContactsFfbbSection({ club }: { club: NonNullable<MeResponse["club"]> }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <ContactBlock data={club.ffbbCommittee} fallbackName="Comité" />
      <ContactBlock data={club.ffbbLeague} fallbackName="Ligue" />
    </div>
  );
}

const DAY_LABELS: Record<number, string> = { 1: "Lundi", 2: "Mardi", 3: "Mercredi", 4: "Jeudi", 5: "Vendredi", 6: "Samedi", 7: "Dimanche" };
const DAY_SHORT: Record<number, string> = { 1: "Lun", 2: "Mar", 3: "Mer", 4: "Jeu", 5: "Ven", 6: "Sam", 7: "Dim" };

/** Le total d'heures d'un jour dans une liste byDay (0 si le jour n'y figure pas). */
function dayTotal(byDay: UsageDayHours[], day: number): number {
  return byDay.find((d) => d.day === day)?.total ?? 0;
}

/** « 01/09/2026 » depuis une date ISO, sans passer par Date (aucun décalage de fuseau). */
function frDate(iso: string): string {
  return iso.split("-").reverse().join("/");
}

/** Une cellule d'heures : « — » à zéro, pour que l'œil accroche l'usage réel. */
function HoursCell({ hours, strong, className }: { hours: number; strong?: boolean; className?: string }) {
  return (
    <td className={cn("py-1.5 text-right tabular-nums", strong ? "font-semibold" : "", className)}>
      {hours > 0 ? formatHours(hours) : <span className="text-muted-foreground">—</span>}
    </td>
  );
}

/** Trait de séparation après DIMANCHE : les jours d'un côté, les cumuls de l'autre. */
const SEPARATOR = "border-r-2 border-border pr-3";

interface UsageTableRow {
  key: string;
  label: string;
  byDay: UsageDayHours[];
  real: number;
  projected: number;
  total: number;
}

/**
 * Un tableau d'usage : lignes (gymnases ou niveaux), colonnes = jours + Réalisé /
 * À venir / Total, et une ligne TOTAL par jour visuellement marquée — le chiffre
 * qu'on pose sur la table de la mairie (« le lundi, on a 8 h »).
 */
function UsageTable({ caption, firstColHeader, rows, totalByDay, grandTotal, days }: {
  caption: string;
  firstColHeader: string;
  rows: UsageTableRow[];
  totalByDay: UsageDayHours[];
  grandTotal: { real: number; projected: number; total: number };
  days: number[];
}) {
  return (
    <div className="space-y-2">
      <h3 className="text-sm font-semibold">{caption}</h3>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[36rem] text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs text-muted-foreground">
              <th className="py-1.5 font-medium">{firstColHeader}</th>
              {days.map((d) => (
                <th key={d} className={cn("py-1.5 text-right font-medium", 7 === d ? SEPARATOR : "")} title={DAY_LABELS[d]}>{DAY_SHORT[d]}</th>
              ))}
              <th className="py-1.5 pl-3 text-right font-medium">Réalisé</th>
              <th className="py-1.5 text-right font-medium">À venir</th>
              <th className="py-1.5 text-right font-medium">Total</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.key} className="border-b border-border/50">
                <td className="py-1.5">{r.label}</td>
                {days.map((d) => <HoursCell key={d} hours={dayTotal(r.byDay, d)} className={7 === d ? SEPARATOR : ""} />)}
                <HoursCell hours={r.real} className="pl-3" />
                <HoursCell hours={r.projected} />
                <HoursCell hours={r.total} strong />
              </tr>
            ))}
            <tr className="border-t-2 border-border font-semibold">
              <td className="py-1.5">TOTAL</td>
              {days.map((d) => <HoursCell key={d} hours={dayTotal(totalByDay, d)} strong className={7 === d ? SEPARATOR : ""} />)}
              <HoursCell hours={grandTotal.real} strong className="pl-3" />
              <HoursCell hours={grandTotal.projected} strong />
              <HoursCell hours={grandTotal.total} strong />
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  );
}

/**
 * Statistiques d'utilisation des gymnases (P3-22) : deux tableaux (par gymnase,
 * par niveau) des heures ventilées PAR JOUR — Réalisé / À venir — pour NÉGOCIER
 * les créneaux avec la mairie. TOUT est calculé serveur (heures, ventilation,
 * libellés de niveau) ; le front n'agrège rien de métier. Sans planning en
 * vigueur, la section DIT pourquoi elle est vide.
 */
function VenueStatsSection({ me }: { me: MeResponse }) {
  const chosenId = me.seasonPlan?.chosenScheduleId ?? null;
  const season = me.seasons.find((s) => s.id === me.currentSeasonId);
  const [from, setFrom] = useState(season?.startDate ?? "");
  const [to, setTo] = useState(season?.endDate ?? "");
  const statsQuery = useVenueUsageStats(from || undefined, to || undefined, null !== chosenId);

  if (null === chosenId) {
    return <p className="text-sm text-muted-foreground">Les statistiques se calculent sur le planning en vigueur — validez d'abord le planning principal de la saison.</p>;
  }
  if (statsQuery.isLoading) {
    return <p className="text-sm text-muted-foreground">Chargement des statistiques…</p>;
  }
  if (statsQuery.isError || undefined === statsQuery.data) {
    return <p className="text-sm text-destructive">Les statistiques n'ont pas pu être chargées.</p>;
  }

  const data = statsQuery.data;
  // Colonnes lundi→DIMANCHE, toujours les sept (retour fondateur) : le dimanche reste
  // vide aujourd'hui mais les horaires de MATCH viendront l'occuper — une colonne qui
  // apparaît/disparaît selon les données déplacerait le tableau sous les yeux du lecteur.
  const days = [1, 2, 3, 4, 5, 6, 7];
  const venueRows: UsageTableRow[] = data.venues.map((v) => ({ key: v.venueId, label: v.name, byDay: v.byDay, real: v.real, projected: v.projected, total: v.total }));
  const levelRows: UsageTableRow[] = data.byLevel.map((l) => ({ key: l.level ?? "__none__", label: l.label, byDay: l.byDay, real: l.real, projected: l.projected, total: l.total }));

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label htmlFor="vus-from" className="mb-1 block text-xs text-muted-foreground">Du</label>
          <input id="vus-from" type="date" className="h-9 rounded-md border border-border bg-background px-2 text-sm" value={from} min={season?.startDate} max={to || season?.endDate} onChange={(e) => setFrom(e.target.value)} />
        </div>
        <div>
          <label htmlFor="vus-to" className="mb-1 block text-xs text-muted-foreground">Au</label>
          <input id="vus-to" type="date" className="h-9 rounded-md border border-border bg-background px-2 text-sm" value={to} min={from || season?.startDate} max={season?.endDate} onChange={(e) => setTo(e.target.value)} />
        </div>
        <p className="text-xs text-muted-foreground">Plage analysée : du {frDate(data.range.from)} au {frDate(data.range.to)}.</p>
      </div>

      {0 === venueRows.length ? (
        <EmptyHint>Le planning en vigueur ne place aucune séance sur cette plage.</EmptyHint>
      ) : (
        <>
          <UsageTable caption="Par gymnase" firstColHeader="Gymnase" rows={venueRows} totalByDay={data.totalByDay} grandTotal={data.grandTotal} days={days} />
          <UsageTable caption="Par niveau" firstColHeader="Niveau" rows={levelRows} totalByDay={data.totalByDay} grandTotal={data.grandTotal} days={days} />
        </>
      )}
    </div>
  );
}

/** RGPD — portabilité : export JSON complet du workspace du club (management). */
function ExportClubSection() {
  const exportDownload = useDownloadClubExport();
  return (
    <div className="space-y-3">
      <p className="text-sm text-muted-foreground">
        Téléchargez une copie complète des données du club (saisons, équipes, coachs, gymnases, contraintes,
        plannings, matchs…) au format JSON — conformité RGPD (portabilité).
      </p>
      <Button
        type="button"
        variant="outline"
        disabled={exportDownload.isPending}
        onClick={() => exportDownload.mutate()}
      >
        {exportDownload.isPending ? <Spinner className="size-4" /> : null}
        Exporter les données du club
      </Button>
    </div>
  );
}

/** Paliers triés pour l'affichage : Découverte d'abord, puis payants par cap
 *  d'équipes croissant (0 = illimité → en dernier). */
function sortPlans(plans: SubscriptionPlan[]): SubscriptionPlan[] {
  const rank = (p: SubscriptionPlan): number => ("decouverte" === p.code ? -1 : 0 === p.maxTeams ? Number.MAX_SAFE_INTEGER : p.maxTeams);
  return [...plans].sort((a, b) => rank(a) - rank(b));
}

/** Capacité en équipes d'un palier, en clair (convention 0 = illimité). */
function planCapacityLabel(plan: SubscriptionPlan): string {
  if ("decouverte" === plan.code) {
    return "Toutes vos équipes, sans limite";
  }
  return 0 === plan.maxTeams ? "Équipes illimitées" : `Jusqu'à ${plan.maxTeams} équipes`;
}

/**
 * P1-3 §4bis pt 5 — section « Offre » (management). Offre courante (nom du palier ;
 * pour un payant « X/Y équipes »), grille des paliers « sur demande » (bêta absente
 * du catalogue), et pour Découverte le solde de crédits. Rendue pour TOUTES les
 * offres — payant/bêta la voient aussi ; seuls les mécanismes de crédits
 * (badge/bandeau/coûts) sont réservés à Découverte.
 */
function OfferSection({ me }: { me: MeResponse }) {
  const plansQuery = useSubscriptionPlans();
  const credits = useCredits();
  const entitlements = me.club?.entitlements;

  return (
    <div className="space-y-4">
      {undefined !== entitlements ? (
        <div className="rounded-lg border border-accent/40 bg-accent/5 p-3">
          <p className="text-xs text-muted-foreground">Votre offre</p>
          <p className="text-base font-semibold">{entitlements.planName}</p>
          {null !== entitlements.maxTeams ? (
            <p className="text-sm text-muted-foreground">
              {entitlements.teamsUsed}/{entitlements.maxTeams} équipes
            </p>
          ) : null}
          {null !== credits ? (
            <p className="text-sm text-muted-foreground">
              Crédits gratuits : {credits.remaining}/{credits.max}
            </p>
          ) : null}
        </div>
      ) : null}

      {readFailed(plansQuery) ? (
        <p className="text-sm text-destructive">Les offres n'ont pas pu être chargées.</p>
      ) : readLoading(plansQuery) ? (
        <p className="text-sm text-muted-foreground">Chargement des offres…</p>
      ) : (
        <div className="grid gap-2 sm:grid-cols-2">
          {sortPlans(plansQuery.data ?? []).map((plan) => {
            const current = undefined !== entitlements && plan.code === entitlements.planCode;
            return (
              <div key={plan.id} className={cn("rounded-lg border p-3", current ? "border-accent bg-accent/5" : "border-border")}>
                <div className="flex items-center justify-between gap-2">
                  <p className="font-medium">{plan.name}</p>
                  {current ? <span className="rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-accent-foreground">Votre offre</span> : null}
                </div>
                <p className="text-sm text-muted-foreground">{planCapacityLabel(plan)}</p>
                {/* Aucun montant dans l'app (décision fondateur) : « sur demande » partout,
                    sauf le gratuit qui n'a pas de tarif. */}
                <p className="mt-1 text-sm font-medium text-accent">{"decouverte" === plan.code ? "Gratuit" : "Sur demande"}</p>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

function ClubHub({ me }: { me: MeResponse }) {
  const isAdmin = me.role === "admin";
  // Le gate backend management = owner|admin (SEC-07) — l'UI doit matcher,
  // sinon un owner ne voit jamais l'export RGPD (revue PR-2).
  const isManagement = isManagementRole(me.role);
  return (
    <FichePage>
      <h1 className="mb-1 border-l-[3px] border-accent pl-3 text-xl font-semibold">Gestion du club</h1>
      <p className="mb-4 text-sm text-muted-foreground">{me.club?.name ?? "—"}</p>
      <div className="space-y-3">
        {isManagement ? (
          <AccordionSection title="Offre" defaultOpen>
            <p className="mb-3 text-sm text-muted-foreground">Votre offre actuelle et les paliers disponibles.</p>
            <OfferSection me={me} />
          </AccordionSection>
        ) : null}
        {isAdmin ? (
          <AccordionSection title="Demandes d'adhésion" defaultOpen>
            <p className="mb-3 text-sm text-muted-foreground">Approuvez ou refusez les personnes qui souhaitent rejoindre votre club.</p>
            <PendingMembersSection />
          </AccordionSection>
        ) : null}
        {isManagement ? (
          <AccordionSection title="Membres">
            <p className="mb-3 text-sm text-muted-foreground">Gérez les rôles de vos membres, désactivez ou réactivez un accès. Un club garde toujours au moins un gestionnaire.</p>
            <MembersSection />
          </AccordionSection>
        ) : null}
        <AccordionSection title="Visuel">
          <IdentitySection
            accentColor={me.club?.accentColor ?? null}
            accentColorDark={me.club?.accentColorDark ?? null}
            accentPalette={me.club?.accentPalette ?? null}
            logoUrl={me.club?.logoUrl ?? null}
            clubName={me.club?.name ?? ""}
          />
        </AccordionSection>
        {isAdmin && me.club ? (
          <AccordionSection title="Informations du club">
            {/* Rendu pur des props (plus aucun formulaire) — pas de key de resync nécessaire. */}
            <ClubInfoSection club={me.club} />
          </AccordionSection>
        ) : null}
        {isAdmin && me.club ? (
          <AccordionSection title="Contacts FFBB">
            <p className="mb-3 text-sm text-muted-foreground">
              Coordonnées récupérées automatiquement depuis la FFBB (lecture seule).
            </p>
            <ContactsFfbbSection club={me.club} />
          </AccordionSection>
        ) : null}
        {isAdmin ? (
          <AccordionSection title="Statistiques des gymnases">
            <VenueStatsSection me={me} />
          </AccordionSection>
        ) : null}
        {isManagement ? (
          <AccordionSection title="Exporter les données">
            <ExportClubSection />
          </AccordionSection>
        ) : null}
        {isAdmin ? (
          <AccordionSection title="Réinitialiser le club">
            <DangerSection />
          </AccordionSection>
        ) : null}
      </div>
    </FichePage>
  );
}

export function ClubPage() {
  const { data: me, isLoading } = useMe();
  if (isLoading || undefined === me) {
    return <FullPageSpinner />;
  }
  return <ClubHub me={me} />;
}

import { Crop, ImagePlus, Trash2 } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { useMe } from "@/shared/session/queries";
import type { FfbbOrganisme, MeResponse } from "@/shared/session/api";
import { PendingMembersSection } from "@/features/auth/PendingMembersSection";
import { InvitationsSection } from "@/features/club/InvitationsSection";
import { MembersSection } from "@/features/club/MembersSection";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { AddressGeocodeField } from "@/shared/components/ui/address-geocode-field";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { FichePage } from "@/shared/components/ui/fiche-page";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { PageHeader } from "@/shared/components/ui/page-header";
import { FullPageSpinner, Spinner } from "@/shared/components/ui/spinner";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/shared/components/ui/table";
import { useCredits } from "@/shared/credits/useCredits";
import { readableForeground } from "@/shared/lib/color";
import { dayLabelLongCap, dayLabelShort } from "@/shared/lib/days";
import { PRODUCT_ACCENT } from "@/shared/lib/product";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";
import { extractPalette } from "@/shared/lib/palette";

import type { SubscriptionPlan, UsageDayHours } from "./api";
import { LogoCropper } from "./LogoCropper";
import { formatHours } from "./lib/venueStats";
import { useDeleteLogo, useDownloadClubExport, useFfbbImport, useResetClub, useSubscriptionPlans, useUpdateAppearance, useUpdateShortName, useUpdateSiege, useUploadLogo, useVenueUsageStats } from "./queries";
import { CLUB_TEXTS } from "./texts";
import { isManagementRole } from "@/shared/lib/roles";

const HEX = /^#[0-9a-fA-F]{6}$/;
// Repli du sélecteur de couleur = l'accent PRODUIT (maison unique `product.ts`, jamais un hex ici).
const DEFAULT_ACCENT = PRODUCT_ACCENT;

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
        <Input aria-label={`Couleur ${label} (hexadécimal)`} className="h-9 w-28 font-mono text-xs" value={value} placeholder={DEFAULT_ACCENT} onChange={(e) => onChange(e.target.value)} />
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
      <p className="text-sm text-muted-foreground">{CLUB_TEXTS.brandingIntro}</p>
      {/* Logo — cropper when a new file is being framed, else preview + actions */}
        {null !== cropFile ? (
          <LogoCropper file={cropFile} onCropped={onCropped} onCancel={() => setCropFile(null)} />
        ) : (
          <div className="flex items-center gap-4">
            <button
              type="button"
              onClick={() => void recrop()}
              disabled={null === preview}
              title={null !== preview ? CLUB_TEXTS.recropLogoTitle : undefined}
              className="flex size-16 items-center justify-center overflow-hidden rounded-full border border-border bg-muted text-lg font-bold text-muted-foreground enabled:cursor-pointer enabled:hover:opacity-90"
            >
              {null !== preview ? <img src={preview} alt="Logo" className="size-full object-cover" /> : clubName.trim().charAt(0).toUpperCase() || "C"}
            </button>
            <div className="flex flex-wrap items-center gap-2">
              <label className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 py-1.5 text-sm hover:bg-muted">
                <ImagePlus className="size-4" />
                {CLUB_TEXTS.chooseLogo}
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
                  {CLUB_TEXTS.recrop}
                </Button>
              ) : null}
              {null !== logoUrl ? (
                <Button size="sm" variant="ghost" className="text-destructive" onClick={removeLogo} disabled={busy}>
                  <Trash2 className="size-4" />
                  {CLUB_TEXTS.removeLogoLabel}
                </Button>
              ) : null}
              <span className="text-xs text-muted-foreground">{CLUB_TEXTS.logoFormatHint}</span>
            </div>
          </div>
        )}

        {/* Suggested palette from the logo */}
        {palette.length > 0 ? (
          <div className="flex items-center gap-2">
            <span className="text-xs text-muted-foreground">{CLUB_TEXTS.logoColorsLabel}</span>
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
          <p className="mb-2 text-xs text-muted-foreground">{CLUB_TEXTS.accentHint}</p>
          <div className="flex flex-wrap gap-4">
            <AccentField id="accent-light" label={CLUB_TEXTS.accentLight} value={color} onChange={setColor} />
            <AccentField id="accent-dark" label={CLUB_TEXTS.accentDark} value={colorDark} onChange={setColorDark} />
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button onClick={save} disabled={!valid || busy}>
            {busy ? <Spinner className="size-4" /> : null}
            {CLUB_TEXTS.save}
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
              {CLUB_TEXTS.resetColors}
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
      <p className="text-sm text-muted-foreground">{CLUB_TEXTS.dangerIntro}</p>
      <Button variant="destructive" onClick={() => setConfirm(true)} disabled={reset.isPending}>
        {reset.isPending ? <Spinner className="size-4" /> : <Trash2 className="size-4" />}
        {CLUB_TEXTS.resetClubButton}
      </Button>

      <ConfirmDialog
        open={confirm}
        title={CLUB_TEXTS.resetClubConfirmTitle}
        description={CLUB_TEXTS.resetClubConfirmDescription}
        confirmLabel={CLUB_TEXTS.deleteAllConfirmLabel}
        onCancel={() => setConfirm(false)}
        onConfirm={() => {
          setConfirm(false);
          reset.mutate(undefined, { onSuccess: () => navigate("/wizard") });
        }}
      />
    </div>
  );
}

/**
 * Le NOM COURT du club — la seule saisie de l'identité (tout le reste vient de la FFBB). Posé sous
 * le nom officiel. Vide = repli sur le nom complet. Enregistrer/Annuler en TEXTE (N1). L'erreur
 * serveur (format refusé) remonte en toast au niveau du hook (patron FRT-38 + errorMessage()).
 */
function ClubShortNameField({ shortName }: { shortName: string | null }) {
  const update = useUpdateShortName();
  const [value, setValue] = useState(shortName ?? "");
  const saved = shortName ?? "";
  const dirty = value.trim() !== saved;

  return (
    <div>
      <label htmlFor="club-short-name" className="mb-1 block text-xs text-muted-foreground">
        {CLUB_TEXTS.shortNameLabel}
      </label>
      <div className="flex flex-wrap items-center gap-2">
        <Input
          id="club-short-name"
          className="w-56"
          value={value}
          maxLength={20}
          placeholder={CLUB_TEXTS.shortNamePlaceholder}
          onChange={(e) => setValue(e.target.value)}
          disabled={update.isPending}
        />
        {dirty ? (
          <>
            <Button size="sm" onClick={() => update.mutate(value.trim())} disabled={update.isPending}>
              {update.isPending ? <Spinner className="size-4" /> : null}
              {CLUB_TEXTS.save}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setValue(saved)} disabled={update.isPending}>
              {CLUB_TEXTS.cancel}
            </Button>
          </>
        ) : null}
      </div>
      <p className="mt-1 text-xs text-muted-foreground">{CLUB_TEXTS.shortNameHint}</p>
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
 * Le SIÈGE du club — la seule saisie de la fiche. On pose un LIBELLÉ d'adresse ; le serveur le
 * re-géocode via la BAN et écrit adresse/coordonnées depuis son hit (jamais les coords du
 * client). Le voile d'enregistrement couvre l'attente ; l'échec 422/502 remonte en toast.
 */
function ClubSiegeSubsection({ club }: { club: NonNullable<MeResponse["club"]> }) {
  const updateSiege = useUpdateSiege();
  const located = null != club.latitude && null != club.longitude;
  return (
    <div>
      <h3 className="mb-2 text-sm font-semibold">{CLUB_TEXTS.siegeTitle}</h3>
      <p className="mb-2 text-xs text-muted-foreground">{CLUB_TEXTS.siegeHint}</p>
      <AddressGeocodeField
        address={club.address}
        located={located}
        latitude={club.latitude}
        longitude={club.longitude}
        placeholder={CLUB_TEXTS.siegeAddressPlaceholder}
        label={CLUB_TEXTS.siegeAddressLabel}
        statusWord={CLUB_TEXTS.siegeLocated}
        unlocatedStatus={CLUB_TEXTS.siegeUnlocated}
        onPick={(candidate) => updateSiege.mutate(candidate.label)}
      />
    </div>
  );
}

/**
 * Informations du club — presque TOUT vient de la FFBB (décision fondateur 2026-08-04) : la
 * SEULE saisie est le SIÈGE du club (adresse re-géocodée côté serveur), parce que la fédération
 * ne fournit pas d'adresse fiable pour estimer les trajets vers les adversaires. Le reste
 * (identité, contact) reste FFBB, corrigé par le ré-import. Correspondant/président/salle
 * principale restent SUPPRIMÉS (l'index organismes ne les connaît pas).
 */
function ClubInfoSection({ club }: { club: NonNullable<MeResponse["club"]> }) {
  const ffbbImport = useFfbbImport();
  // Compact (retour fondateur) : un téléphone se reconnaît sans sous-titre. L'adresse a QUITTÉ
  // ce bloc (elle vit désormais dans « Siège du club », la seule saisie de la fiche).
  const contactLines = [club.contactPhone].filter((line): line is string => null !== line);
  const website = safeHttpUrl(club.website);

  return (
    <div className="space-y-5">
      <div>
        <div className="mb-2 flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">{CLUB_TEXTS.identityTitle}</h3>
          <Button size="sm" variant="outline" onClick={() => ffbbImport.mutate()} disabled={ffbbImport.isPending}>
            {ffbbImport.isPending ? <Spinner className="size-4" /> : null}
            {CLUB_TEXTS.refreshFromFfbb}
          </Button>
        </div>
        <div className="space-y-3 text-sm">
          <ReadOnlyField label={CLUB_TEXTS.officialNameLabel} value={club.name} />
          <ClubShortNameField shortName={club.shortName} />
          <div className="grid grid-cols-2 gap-3">
            <ReadOnlyField label={CLUB_TEXTS.ffbbCodeLabel} value={club.ffbbClubCode} mono />
            <ReadOnlyField label={CLUB_TEXTS.leagueLabel} value={club.league} />
            <ReadOnlyField label={CLUB_TEXTS.schoolZoneLabel} value={club.schoolZone} />
            <ReadOnlyField label={CLUB_TEXTS.committeeLabel} value={club.committeeCode} mono />
          </div>
        </div>
      </div>

      <ClubSiegeSubsection club={club} />

      <div>
        <h3 className="mb-2 text-sm font-semibold">{CLUB_TEXTS.contactTitle}</h3>
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
          <p className="text-xs text-muted-foreground">{CLUB_TEXTS.ffbbUnavailableWithHint}</p>
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
        <p className="text-xs text-muted-foreground">{CLUB_TEXTS.ffbbUnavailable}</p>
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
    <TableCell className={cn("py-1.5 px-0 text-right tabular-nums", strong ? "font-semibold" : "", className)}>
      {hours > 0 ? formatHours(hours) : <span className="text-muted-foreground">—</span>}
    </TableCell>
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
      {/* Primitive partagée `Table` variant `inline` (lot 5 PR E) — conteneur NU (overflow-x-auto),
          neutralisation `px-0`/`normal-case`/`tracking-normal` des défauts de la primitive pour un
          rendu IDENTIQUE au tableau fait main (preflight Tailwind met déjà les cellules à padding 0). */}
      <Table variant="inline" className="min-w-[36rem] text-sm">
        <TableHeader>
          <TableRow className="border-b border-border text-left text-xs text-muted-foreground">
            <TableHead className="py-1.5 px-0 font-medium normal-case tracking-normal">{firstColHeader}</TableHead>
            {days.map((d) => (
              <TableHead key={d} className={cn("py-1.5 px-0 text-right font-medium normal-case tracking-normal", 7 === d ? SEPARATOR : "")} title={dayLabelLongCap(d)}>{dayLabelShort(d)}</TableHead>
            ))}
            <TableHead className="py-1.5 px-0 pl-3 text-right font-medium normal-case tracking-normal">{CLUB_TEXTS.usageReal}</TableHead>
            <TableHead className="py-1.5 px-0 text-right font-medium normal-case tracking-normal">{CLUB_TEXTS.usageProjected}</TableHead>
            <TableHead className="py-1.5 px-0 text-right font-medium normal-case tracking-normal">{CLUB_TEXTS.usageTotal}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((r) => (
            <TableRow key={r.key} className="border-b border-border/50">
              <TableCell className="py-1.5 px-0">{r.label}</TableCell>
              {days.map((d) => <HoursCell key={d} hours={dayTotal(r.byDay, d)} className={7 === d ? SEPARATOR : ""} />)}
              <HoursCell hours={r.real} className="pl-3" />
              <HoursCell hours={r.projected} />
              <HoursCell hours={r.total} strong />
            </TableRow>
          ))}
          {/* `last:border-t-2` : le `last:border-0` de `TableRow` effacerait sinon le trait de 2 px au-dessus du TOTAL. */}
          <TableRow className="border-t-2 border-border font-semibold last:border-t-2">
            <TableCell className="py-1.5 px-0">TOTAL</TableCell>
            {days.map((d) => <HoursCell key={d} hours={dayTotal(totalByDay, d)} strong className={7 === d ? SEPARATOR : ""} />)}
            <HoursCell hours={grandTotal.real} strong className="pl-3" />
            <HoursCell hours={grandTotal.projected} strong />
            <HoursCell hours={grandTotal.total} strong />
          </TableRow>
        </TableBody>
      </Table>
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
    return <p className="text-sm text-muted-foreground">{CLUB_TEXTS.statsNoPlan}</p>;
  }
  if (statsQuery.isLoading) {
    return <p className="text-sm text-muted-foreground">{CLUB_TEXTS.statsLoading}</p>;
  }
  if (statsQuery.isError || undefined === statsQuery.data) {
    return <p className="text-sm text-destructive">{CLUB_TEXTS.statsLoadError}</p>;
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
          <Input id="vus-from" type="date" className="w-40" value={from} min={season?.startDate} max={to || season?.endDate} onChange={(e) => setFrom(e.target.value)} />
        </div>
        <div>
          <label htmlFor="vus-to" className="mb-1 block text-xs text-muted-foreground">Au</label>
          <Input id="vus-to" type="date" className="w-40" value={to} min={from || season?.startDate} max={season?.endDate} onChange={(e) => setTo(e.target.value)} />
        </div>
        <p className="text-xs text-muted-foreground">Plage analysée : du {frDate(data.range.from)} au {frDate(data.range.to)}.</p>
      </div>

      {0 === venueRows.length ? (
        <EmptyHint>{CLUB_TEXTS.statsEmptyRange}</EmptyHint>
      ) : (
        <>
          <UsageTable caption={CLUB_TEXTS.usageByVenue} firstColHeader={CLUB_TEXTS.usageVenueCol} rows={venueRows} totalByDay={data.totalByDay} grandTotal={data.grandTotal} days={days} />
          <UsageTable caption={CLUB_TEXTS.usageByLevel} firstColHeader={CLUB_TEXTS.usageLevelCol} rows={levelRows} totalByDay={data.totalByDay} grandTotal={data.grandTotal} days={days} />
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
      <p className="text-sm text-muted-foreground">{CLUB_TEXTS.exportIntro}</p>
      <Button
        type="button"
        variant="outline"
        disabled={exportDownload.isPending}
        onClick={() => exportDownload.mutate()}
      >
        {exportDownload.isPending ? <Spinner className="size-4" /> : null}
        {CLUB_TEXTS.exportClubData}
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
        <div className="rounded-lg border border-accent/40 bg-surface-accent p-3">
          <p className="text-xs text-muted-foreground">{CLUB_TEXTS.yourOffer}</p>
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
        <LoadErrorHint>{CLUB_TEXTS.offersLoadError}</LoadErrorHint>
      ) : readLoading(plansQuery) ? (
        <p className="text-sm text-muted-foreground">{CLUB_TEXTS.offersLoading}</p>
      ) : (
        <div className="grid gap-2 sm:grid-cols-2">
          {sortPlans(plansQuery.data ?? []).map((plan) => {
            const current = undefined !== entitlements && plan.code === entitlements.planCode;
            return (
              <div key={plan.id} className={cn("rounded-lg border p-3", current ? "border-accent bg-surface-accent" : "border-border bg-card")}>
                <div className="flex items-center justify-between gap-2">
                  <p className="font-medium">{plan.name}</p>
                  {current ? <StatusPill variant="accent-solid">{CLUB_TEXTS.yourOffer}</StatusPill> : null}
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
  // Deep-link `?section=informations` (lien du bandeau « siège » de l'écran matchs) : ouvrir
  // d'emblée la section « Informations du club » (où vit le siège). Lu sur `window.location`
  // (ancrage au montage, non réactif) plutôt que `useSearchParams` — pas de dépendance Router.
  const openInformations = "informations" === new URLSearchParams(window.location.search).get("section");
  return (
    <FichePage>
      <PageHeader className="mb-4" title={CLUB_TEXTS.clubManagementTitle} screen="/club" subtitle={me.club?.name ?? "—"} />
      <div className="space-y-3">
        {isManagement ? (
          <AccordionSection title={CLUB_TEXTS.sectionOffer} defaultOpen>
            <p className="mb-3 text-sm text-muted-foreground">{CLUB_TEXTS.offerSectionHint}</p>
            <OfferSection me={me} />
          </AccordionSection>
        ) : null}
        {isAdmin ? (
          <AccordionSection title={CLUB_TEXTS.sectionMembershipRequests} defaultOpen>
            <p className="mb-3 text-sm text-muted-foreground">{CLUB_TEXTS.membershipRequestsHint}</p>
            <PendingMembersSection />
          </AccordionSection>
        ) : null}
        {isAdmin ? (
          <AccordionSection title={CLUB_TEXTS.sectionInvitations}>
            <p className="mb-3 text-sm text-muted-foreground">{CLUB_TEXTS.invitationsHint}</p>
            <InvitationsSection />
          </AccordionSection>
        ) : null}
        {isManagement ? (
          <AccordionSection title={CLUB_TEXTS.sectionMembers}>
            <p className="mb-3 text-sm text-muted-foreground">{CLUB_TEXTS.membersHint}</p>
            <MembersSection />
          </AccordionSection>
        ) : null}
        <AccordionSection title={CLUB_TEXTS.sectionVisual}>
          <IdentitySection
            accentColor={me.club?.accentColor ?? null}
            accentColorDark={me.club?.accentColorDark ?? null}
            accentPalette={me.club?.accentPalette ?? null}
            logoUrl={me.club?.logoUrl ?? null}
            clubName={me.club?.name ?? ""}
          />
        </AccordionSection>
        {isAdmin && me.club ? (
          <AccordionSection title={CLUB_TEXTS.sectionClubInfo} defaultOpen={openInformations}>
            {/* Rendu pur des props (plus aucun formulaire) — pas de key de resync nécessaire. */}
            <ClubInfoSection club={me.club} />
          </AccordionSection>
        ) : null}
        {isAdmin && me.club ? (
          <AccordionSection title={CLUB_TEXTS.sectionFfbbContacts}>
            <p className="mb-3 text-sm text-muted-foreground">{CLUB_TEXTS.ffbbContactsIntro}</p>
            <ContactsFfbbSection club={me.club} />
          </AccordionSection>
        ) : null}
        {isAdmin ? (
          <AccordionSection title={CLUB_TEXTS.sectionVenueStats}>
            <VenueStatsSection me={me} />
          </AccordionSection>
        ) : null}
        {isManagement ? (
          <AccordionSection title={CLUB_TEXTS.sectionExport}>
            <ExportClubSection />
          </AccordionSection>
        ) : null}
        {isAdmin ? (
          <AccordionSection title={CLUB_TEXTS.sectionReset}>
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

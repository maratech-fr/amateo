import { Check, Route } from "lucide-react";
import { useEffect, useRef } from "react";

import { useWorkingSeason } from "@/shared/session/queries";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Select } from "@/shared/components/ui/select";
import { readState } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";

import type { ImplicitRuleIntensity, ImplicitRuleKey, ImplicitRuleSetting, ImplicitRuleSettingPayload, VenueTravelRuleIntensity } from "../api";
import { PRODUCT_RULES, WELLBEING_RULES, isWellbeingKey } from "../lib/implicitRules";
import { useImplicitRuleSettings, useResetImplicitRuleSetting, useTravelRuleSetting, useUpdateImplicitRuleSetting, useUpdateTravelRuleSetting, useVenueTravelTimes } from "../queries";

/**
 * P2-28 — « les règles du système », remaniement de l'encart P4-55.
 *
 * Deux familles, la frontière est le MOTEUR, pas l'UI :
 *  - **Règles du produit** — LECTURE SEULE. Le solveur les pose d'office ; elles ne sont pas
 *    éditables côté moteur, un contrôle ici promettrait un réglage qui n'existe pas. Le texte
 *    est un CONTRAT (gelé par le test) : chaque ligne AFFIRME un comportement du solveur.
 *  - **Règles de bien-être** — RÉGLABLES (contrat moteur 2.7). Chacune porte une intensité
 *    (Obligatoire = HARD / Objectif = PREFERRED) et, pour deux d'entre elles, un seuil.
 *
 * ⚠ **Régime 1 strict** (`.claude/rules/frontend.md`) : ce panneau N'INVENTE aucune règle. Il
 * AFFICHE la collection RÉSOLUE par le serveur (toujours 4 entrées, défauts inclus) et POSTE le
 * choix du gestionnaire. Les libellés/descriptions sont de la PRÉSENTATION (précédent P4-55). Les
 * bornes de seuil offertes ci-dessous ne sont qu'une ergonomie de saisie : le SERVEUR reste le
 * seul juge (422), il n'y a donc pas de règle métier redérivée ici.
 *
 * Deux verrous gardent l'accord avec le moteur : le gel Vitest de ce fichier, et le test
 * sémantique `engine/tests/semantic/test_implicit_rules_are_still_applied.py`.
 */
/** Les deux crans d'intensité, libellés humains. Les valeurs techniques (HARD/PREFERRED) restent
 *  celles du contrat — jamais affichées telles quelles. */
const INTENSITY_CRANS: { value: ImplicitRuleIntensity; label: string }[] = [
  { value: "HARD", label: "Obligatoire" },
  { value: "PREFERRED", label: "Objectif" },
];

/** Les règles OPT-IN (P2-42) ont un cran de plus : elles naissent INACTIVES. Les quatre
 *  historiques s'appliquent dès qu'un club existe et n'ont donc pas d'état « éteint ». */
const OPT_IN_RULES: ReadonlySet<ImplicitRuleKey> = new Set<ImplicitRuleKey>(["maxConsecutiveDays"]);

function cransFor(ruleKey: ImplicitRuleKey): { value: ImplicitRuleIntensity; label: string }[] {
  return OPT_IN_RULES.has(ruleKey) ? [{ value: "OFF", label: "Inactive" }, ...INTENSITY_CRANS] : INTENSITY_CRANS;
}

/** Bornes OFFERTES pour chaque seuil (ergonomie de saisie ; le serveur reste juge, 422). Clés =
 *  le champ du payload, pas un enum métier. */
const THRESHOLD_BOUNDS: Record<"minRestDays" | "maxConsecutive" | "maxConsecutiveDays", { min: number; max: number; label: string }> = {
  minRestDays: { min: 1, max: 4, label: "Jours de repos minimum" },
  maxConsecutive: { min: 2, max: 6, label: "Créneaux consécutifs maximum" },
  maxConsecutiveDays: { min: 2, max: 5, label: "Jours d'affilée maximum" },
};

/** Unité humaine du seuil, pour le repère « Saison : … » (PR2). Pure présentation. */
const THRESHOLD_UNIT: Record<"minRestDays" | "maxConsecutive" | "maxConsecutiveDays", (n: number) => string> = {
  minRestDays: (n) => (n > 1 ? "jours" : "jour"),
  maxConsecutive: (n) => (n > 1 ? "créneaux" : "créneau"),
  maxConsecutiveDays: (n) => (n > 1 ? "jours" : "jour"),
};

/** Le seuil d'une règle est celui que le serveur a RÉSOLU (non-null) — on ne le devine pas. */
function thresholdOf(setting: ImplicitRuleSetting): { field: "minRestDays" | "maxConsecutive" | "maxConsecutiveDays"; value: number } | null {
  if (null !== setting.minRestDays) {
    return { field: "minRestDays", value: setting.minRestDays };
  }
  if (null !== setting.maxConsecutive) {
    return { field: "maxConsecutive", value: setting.maxConsecutive };
  }
  if (null !== setting.maxConsecutiveDays) {
    return { field: "maxConsecutiveDays", value: setting.maxConsecutiveDays };
  }
  return null;
}

/**
 * Compose le corps du PUT en PRÉSERVANT le seuil courant : le PUT n'accepte qu'un `intensity`
 * plus, optionnellement, un seuil ; l'omettre le ferait retomber au défaut. On renvoie donc
 * toujours le seuil résolu de la règle (ou la nouvelle valeur quand c'est lui qu'on change).
 */
function buildPayload(setting: ImplicitRuleSetting, intensity: ImplicitRuleIntensity, nextThreshold?: number): ImplicitRuleSettingPayload {
  const threshold = thresholdOf(setting);
  const payload: ImplicitRuleSettingPayload = { intensity };
  if (null !== threshold) {
    payload[threshold.field] = nextThreshold ?? threshold.value;
  }
  return payload;
}

/**
 * Le REPÈRE « Saison : … » d'une règle en mode période (PR2) : d'où part la copie du plan. Décision
 * fondateur : la valeur de la saison affichée en repère, rien de plus (pas de bouton « revenir à la
 * saison », pas d'indicateur calculé). Reprend les libellés d'intensité du panneau (jamais l'enum).
 */
function seasonReference(setting: ImplicitRuleSetting): string {
  const intensity = cransFor(setting.ruleKey).find((cran) => cran.value === setting.intensity)?.label ?? setting.intensity;
  const threshold = thresholdOf(setting);

  return null === threshold ? `Saison : ${intensity}` : `Saison : ${intensity}, ${threshold.value} ${THRESHOLD_UNIT[threshold.field](threshold.value)}`;
}

function range(min: number, max: number): number[] {
  return Array.from({ length: max - min + 1 }, (_, i) => min + i);
}

/**
 * Une règle réglable : intensité 2 crans + seuil optionnel + « Réinitialiser ». En saison
 * archivée (`readOnly`), tout est désactivé — le serveur rendrait 409, l'écran ne le laisse pas
 * tenter en aveugle.
 */
function WellbeingRuleRow({
  meta,
  setting,
  seasonSetting,
  readOnly,
  highlighted,
  onIntensity,
  onThreshold,
  onReset,
}: {
  meta: { ruleKey: ImplicitRuleKey; title: string; detail: string };
  setting: ImplicitRuleSetting;
  /** La valeur de saison, en repère — non-null UNIQUEMENT en mode période (d'où part la copie). */
  seasonSetting: ImplicitRuleSetting | null;
  readOnly: boolean;
  highlighted: boolean;
  onIntensity: (setting: ImplicitRuleSetting, intensity: ImplicitRuleIntensity) => void;
  onThreshold: (setting: ImplicitRuleSetting, value: number) => void;
  onReset: (ruleKey: ImplicitRuleKey) => void;
}) {
  const threshold = thresholdOf(setting);

  return (
    <div
      data-rule-key={meta.ruleKey}
      className={cn("flex flex-col gap-2 rounded-md border bg-card px-3 py-2", highlighted ? "border-accent ring-1 ring-accent" : "border-border")}
    >
      <div>
        <p className="text-sm font-medium text-foreground">{meta.title}</p>
        <p className="text-xs text-muted-foreground">{meta.detail}</p>
        {null !== seasonSetting ? <p className="text-xs italic text-muted-foreground">{seasonReference(seasonSetting)}</p> : null}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-label={`Intensité — ${meta.title}`} className="inline-flex overflow-hidden rounded-md border border-border">
          {cransFor(setting.ruleKey).map((cran) => {
            const active = setting.intensity === cran.value;
            return (
              <button
                key={cran.value}
                type="button"
                aria-label={`${cran.label} — ${meta.title}`}
                aria-pressed={active}
                disabled={readOnly}
                onClick={() => onIntensity(setting, cran.value)}
                className={cn(
                  "px-2.5 py-1 text-xs disabled:opacity-50",
                  active ? "bg-accent text-accent-foreground" : "bg-transparent text-muted-foreground hover:text-foreground",
                )}
              >
                {cran.label}
              </button>
            );
          })}
        </div>

        {null !== threshold ? (
          <label className="flex items-center gap-1 text-xs text-muted-foreground">
            {THRESHOLD_BOUNDS[threshold.field].label}
            <Select
              aria-label={`${THRESHOLD_BOUNDS[threshold.field].label} — ${meta.title}`}
              wrapperClassName="w-16"
              value={String(threshold.value)}
              disabled={readOnly}
              onChange={(e) => onThreshold(setting, Number(e.target.value))}
            >
              {range(THRESHOLD_BOUNDS[threshold.field].min, THRESHOLD_BOUNDS[threshold.field].max).map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </Select>
          </label>
        ) : null}

        {/* « Réinitialiser » n'apparaît que hors défaut (le GET porte `isDefault`) : rien à
            réinitialiser tant que la règle est au défaut. */}
        {!setting.isDefault ? (
          <Button size="sm" variant="ghost" className="ml-auto text-xs" disabled={readOnly} onClick={() => onReset(meta.ruleKey)}>
            Réinitialiser
          </Button>
        ) : null}
      </div>
    </div>
  );
}

/**
 * Le CONTENU de l'onglet « Base » de l'étape Contraintes : les règles IMMUABLES, posées d'office,
 * en lecture seule. Décision fondateur : un onglet à gauche d'« Horaires », sans titre de section
 * redondant (le nom de l'onglet porte déjà l'information), juste une phrase d'intro.
 */
export function ProductRulesPanel() {
  return (
    <div>
      <p className="mb-3 text-xs text-muted-foreground">Ces règles s'appliquent d'office, sans saisie. Le système les garantit à chaque génération.</p>
      <ul className="flex flex-col gap-2">
        {PRODUCT_RULES.map((rule) => (
          <li key={rule.id} className="text-sm">
            <span className="font-medium text-foreground">{rule.title}</span>
            <span className="text-muted-foreground"> — {rule.detail}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

const TRAVEL_INTENSITY_CRANS: { value: VenueTravelRuleIntensity; label: string }[] = [
  { value: "OFF", label: "Inactive" },
  { value: "PREFERRED", label: "Préféré" },
  { value: "MANDATORY", label: "Obligatoire" },
];

const TRAVEL_TOLERANCE_BOUNDS = { min: 0, max: 60 } as const;
const TRAVEL_DEFAULT_BOUNDS = { min: 1, max: 120 } as const;

function clampMinutes(raw: string, bounds: { min: number; max: number }, fallback: number): number {
  const n = Number.parseInt(raw, 10);
  if (Number.isNaN(n)) {
    return fallback;
  }
  return Math.min(bounds.max, Math.max(bounds.min, n));
}

/**
 * P2-53 RMM-8 — l'encart de la règle « Trajet entre gymnases », dans l'onglet Bien-être.
 *
 * ⚠ Régime 1 (`.claude/rules/frontend.md`) : le front N'INVENTE aucune règle. L'ACTIVATION possible
 * est DÉRIVÉE serveur-side de la présence de matrice (`ScheduleConstraintBuilder` — ≥1 ligne) : cet
 * encart n'apparaît QUE si la matrice servie porte au moins une ligne. Le cran, le battement toléré
 * et le temps par défaut ne sont pas redérivés non plus — ils sont LUS du backend
 * (`venue_travel_rule_setting`, résolu défauts PREFERRED/20/20) et POSTÉS au choix du gestionnaire.
 *
 * Trois réglages (décision fondateur 2026-09-30) : le cran Inactive/Préféré/Obligatoire, le
 * battement toléré (retranché du barème pour l'écart exigé) et le temps par défaut d'un couple sans
 * temps. Écriture management ; désactivé (lecture) sur une saison archivée, comme les règles
 * bien-être.
 */
export function TravelRuleNotice() {
  const { data: matrix = [] } = useVenueTravelTimes();
  const hasMatrix = matrix.length > 0;
  // Le levier n'est lu QUE si une matrice existe (l'encart n'apparaît pas sinon) : `enabled`
  // évite une requête inutile chez un club sans matrice.
  const settingQuery = useTravelRuleSetting(hasMatrix);
  const update = useUpdateTravelRuleSetting();
  const readOnly = true === useWorkingSeason()?.isReadonly;

  const intensity: VenueTravelRuleIntensity = settingQuery.data?.intensity ?? "PREFERRED";
  const tolerance = settingQuery.data?.toleranceMinutes ?? 20;
  const defaultMinutes = settingQuery.data?.defaultMinutes ?? 20;

  if (!hasMatrix) {
    return null;
  }

  const off = "OFF" === intensity;
  const commit = (patch: Partial<{ intensity: VenueTravelRuleIntensity; toleranceMinutes: number; defaultMinutes: number }>) =>
    update.mutate({ intensity, toleranceMinutes: tolerance, defaultMinutes, ...patch });

  const applyIntensity = (next: VenueTravelRuleIntensity) => {
    if (next !== intensity) {
      commit({ intensity: next });
    }
  };
  // Champs NON contrôlés (commit au blur, bornés) : leur `key` change avec la valeur serveur, ce qui
  // les re-sème après une écriture — sans setState dans un effet.
  const commitTolerance = (raw: string) => {
    const n = clampMinutes(raw, TRAVEL_TOLERANCE_BOUNDS, tolerance);
    if (n !== tolerance) {
      commit({ toleranceMinutes: n });
    }
  };
  const commitDefault = (raw: string) => {
    const n = clampMinutes(raw, TRAVEL_DEFAULT_BOUNDS, defaultMinutes);
    if (n !== defaultMinutes) {
      commit({ defaultMinutes: n });
    }
  };

  return (
    <div className="mb-3 flex flex-col gap-2 rounded-md border border-border bg-card px-3 py-2">
      <div className="flex flex-wrap items-center gap-2">
        <Route className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium text-foreground">Trajet entre gymnases</p>
        {off ? (
          <StatusPill variant="neutral">Inactive</StatusPill>
        ) : (
          <StatusPill variant="accent" icon={<Check className="size-3 text-accent" aria-hidden="true" />}>
            Active
          </StatusPill>
        )}
      </div>
      <p className="text-xs text-muted-foreground">
        Quand une même personne enchaîne deux séances le même jour dans deux gymnases différents, le planning vérifie qu'elle a le temps d'y aller — en voiture si le coach est véhiculé,
        à vélo ou en trottinette sinon. Cet encart n'apparaît que parce que vous avez renseigné des temps de trajet entre vos gymnases.
      </p>

      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs font-medium text-foreground">Niveau</span>
        <div role="group" aria-label="Niveau de la règle de trajet entre gymnases" className="inline-flex overflow-hidden rounded-md border border-border">
          {TRAVEL_INTENSITY_CRANS.map((cran) => {
            const active = intensity === cran.value;
            return (
              <button
                key={cran.value}
                type="button"
                aria-label={`${cran.label} — trajet entre gymnases`}
                aria-pressed={active}
                disabled={readOnly || update.isPending}
                onClick={() => applyIntensity(cran.value)}
                className={cn(
                  "px-2.5 py-1 text-xs disabled:opacity-50",
                  active ? "bg-accent text-accent-foreground" : "bg-transparent text-muted-foreground hover:text-foreground",
                )}
              >
                {cran.label}
              </button>
            );
          })}
        </div>
      </div>

      <div className="flex flex-wrap items-end gap-4">
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          <span className="font-medium text-foreground">Battement toléré (min)</span>
          <Input
            key={`tolerance-${tolerance}`}
            type="number"
            inputMode="numeric"
            aria-label="Battement toléré, en minutes"
            className="h-9 w-20"
            min={TRAVEL_TOLERANCE_BOUNDS.min}
            max={TRAVEL_TOLERANCE_BOUNDS.max}
            defaultValue={String(tolerance)}
            disabled={readOnly || off || update.isPending}
            onBlur={(e) => commitTolerance(e.target.value)}
          />
        </label>
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          <span className="font-medium text-foreground">Temps par défaut (min)</span>
          <Input
            key={`default-${defaultMinutes}`}
            type="number"
            inputMode="numeric"
            aria-label="Temps par défaut pour un couple de gymnases sans temps, en minutes"
            className="h-9 w-20"
            min={TRAVEL_DEFAULT_BOUNDS.min}
            max={TRAVEL_DEFAULT_BOUNDS.max}
            defaultValue={String(defaultMinutes)}
            disabled={readOnly || off || update.isPending}
            onBlur={(e) => commitDefault(e.target.value)}
          />
        </label>
      </div>

      <p className="text-xs text-muted-foreground">
        Le club accepte que le coach parte un peu avant la fin ou démarre un peu après l'heure : ce <em>battement toléré</em> est retranché du trajet exigé. Le <em>temps par défaut</em>{" "}
        s'applique à un couple de gymnases dont vous n'avez pas indiqué le temps. <em>Préféré</em> : le planning s'y tient quand il peut. <em>Obligatoire</em> : il ne dépassera jamais
        vos temps — au risque de rendre le planning infaisable si les enchaînements sont trop serrés. <em>Inactive</em> : le planning ne vérifie plus les trajets (vos temps sont
        conservés).
      </p>
    </div>
  );
}

/**
 * Le CONTENU de l'onglet « Bien-être » : les 4 règles RÉGLABLES (contrat moteur 2.7). Il ne crée
 * AUCUNE contrainte : il RÈGLE les `implicit_rule_settings`. Un deep-link `?rule=<ruleKey>` (depuis
 * un diagnostic du planning) ouvre cet onglet — géré par `ConstraintsStep` —, surligne la règle
 * visée et l'amène à l'écran. Pas de titre de section : l'onglet le porte déjà.
 */
export function WellbeingRulesPanel({ ruleTarget = null, schedulePlanId = null }: { ruleTarget?: string | null; schedulePlanId?: string | null }) {
  // PR2 — en période (`schedulePlanId` non-null) le panneau règle la COPIE du plan ; sinon la
  // saison (comportement historique). La portée entre dans les trois appels et dans le cache.
  const periodMode = null !== schedulePlanId;
  const settingsQuery = useImplicitRuleSettings(schedulePlanId);
  // La valeur de SAISON, en repère (« Saison : … ») quand on règle une période. En portée saison,
  // même clé de cache que `settingsQuery` (react-query dédoublonne) — donc aucun appel réseau en
  // plus ; on ne la LIT que `periodMode`.
  const seasonQuery = useImplicitRuleSettings(null);
  const update = useUpdateImplicitRuleSetting(schedulePlanId);
  const reset = useResetImplicitRuleSetting(schedulePlanId);
  const readOnly = true === useWorkingSeason()?.isReadonly;

  const settings = settingsQuery.data ?? [];
  const byKey = new Map(settings.map((s) => [s.ruleKey, s]));
  const seasonByKey = new Map((seasonQuery.data ?? []).map((s) => [s.ruleKey, s]));
  const state = readState(settingsQuery);

  // Amène la LIGNE ciblée à l'écran (centrée), une seule fois. La ligne n'existe qu'une fois les
  // règles RENDUES : tant que la lecture est en vol (row absente), on ne consomme pas — l'effet
  // retente quand la donnée arrive (dép. `state`). rAF + `scrollIntoView` optionnel (absent en
  // jsdom) : même patron que l'atterrissage `edit=` (P4-95).
  const consumedRuleRef = useRef<string | null>(null);
  useEffect(() => {
    if (!isWellbeingKey(ruleTarget) || consumedRuleRef.current === ruleTarget) {
      return;
    }
    const el = document.querySelector(`[data-rule-key="${ruleTarget}"]`);
    if (null === el) {
      return;
    }
    consumedRuleRef.current = ruleTarget;
    requestAnimationFrame(() => el.scrollIntoView?.({ block: "center", behavior: "smooth" }));
  }, [ruleTarget, state]);

  const applyIntensity = (setting: ImplicitRuleSetting, intensity: ImplicitRuleIntensity) => {
    if (setting.intensity === intensity) {
      return;
    }
    update.mutate({ ruleKey: setting.ruleKey, body: buildPayload(setting, intensity) });
  };
  const applyThreshold = (setting: ImplicitRuleSetting, value: number) => {
    update.mutate({ ruleKey: setting.ruleKey, body: buildPayload(setting, setting.intensity, value) });
  };
  const applyReset = (ruleKey: ImplicitRuleKey) => reset.mutate(ruleKey);

  return (
    <div>
      {periodMode ? (
        <p className="mb-3 rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-foreground">
          Ces réglages ne valent que pour cette période — copiés du planning de saison à sa création.
        </p>
      ) : null}
      <p className="mb-3 text-xs text-muted-foreground">
        Réglez chacune sur « Obligatoire » (toujours respectée) ou « Objectif » (respectée quand c'est possible). Une règle en Objectif peut être dépassée — chaque dépassement est signalé
        au planning (« assouplie par vous »).
      </p>

      {"failed" === state ? (
        <LoadErrorHint onRetry={() => void settingsQuery.refetch()}>Impossible de lire les réglages des règles de bien-être.</LoadErrorHint>
      ) : "loading" === state ? (
        <p className="text-xs text-muted-foreground">Lecture des réglages…</p>
      ) : (
        <div className="flex flex-col gap-2">
          {WELLBEING_RULES.map((meta) => {
            const setting = byKey.get(meta.ruleKey);
            return undefined === setting ? null : (
              <WellbeingRuleRow
                key={meta.ruleKey}
                meta={meta}
                setting={setting}
                seasonSetting={periodMode ? (seasonByKey.get(meta.ruleKey) ?? null) : null}
                readOnly={readOnly}
                highlighted={ruleTarget === meta.ruleKey}
                onIntensity={applyIntensity}
                onThreshold={applyThreshold}
                onReset={applyReset}
              />
            );
          })}
        </div>
      )}
    </div>
  );
}

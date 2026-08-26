import { Check, Route } from "lucide-react";
import { useEffect, useRef } from "react";

import { useWorkingSeason } from "@/shared/session/queries";
import { Button } from "@/shared/components/ui/button";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Select } from "@/shared/components/ui/select";
import { readState } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";

import type { ImplicitRuleIntensity, ImplicitRuleKey, ImplicitRuleSetting, ImplicitRuleSettingPayload, VenueTravelRuleIntensity } from "../api";
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
export interface ImplicitRule {
  id: string;
  title: string;
  detail: string;
}

/**
 * Les règles POSÉES D'OFFICE, lecture seule. L'ordre compte : du plus visible sur la grille au
 * plus subtil, puis les deux garanties de saisie honorée.
 *
 * ⚠ Le texte est un CONTRAT avec le moteur (`engine/app/solver/constraints.py`). Deux
 * formulations corrigées en le rédigeant : un coach PEUT encadrer deux équipes à la fois DANS LE
 * MÊME gymnase (D-14), et « une séance par jour » n'a AUCUNE exception (P4-79).
 */
export const PRODUCT_RULES: ImplicitRule[] = [
  {
    id: "venue-capacity",
    title: "Un gymnase ne dépasse jamais sa capacité",
    detail: "Sur un même créneau, un gymnase accueille au plus le nombre d'équipes que vous lui avez donné. Cette capacité se règle sur l'écran Gymnases, créneau par créneau.",
  },
  {
    id: "coach-two-venues",
    title: "Un coach n'est jamais dans deux gymnases à la fois",
    detail:
      "Deux équipes au même moment dans deux gymnases différents, c'est physiquement impossible : le solveur ne le proposera pas. En revanche, deux équipes au même moment dans le MÊME gymnase sont autorisées — le coach est présent une fois et surveille deux groupes.",
  },
  {
    id: "coach-player",
    title: "Une personne ne peut pas encadrer et jouer en même temps",
    detail: "Quand un coach est aussi joueur dans une autre équipe, ses deux rôles ne peuvent pas tomber sur le même créneau.",
  },
  {
    id: "team-overlap",
    title: "Une équipe n'a jamais deux séances en même temps",
    detail: "Une même équipe ne peut pas être placée sur deux créneaux qui se chevauchent.",
  },
  {
    id: "one-session-per-day",
    title: "Au plus une séance par jour et par équipe",
    detail: "Deux créneaux le même jour pour la même équipe ne sont jamais proposés.",
  },
  {
    id: "reservations-honored",
    title: "Vos réservations, indisponibilités et gymnases imposés sont toujours honorés",
    detail: "Ce que vous avez fixé vous-même — un créneau réservé, un coach indisponible, un gymnase imposé — n'est jamais remis en cause par le solveur.",
  },
  {
    id: "team-minimum-target",
    title: "Chaque équipe vise son minimum de séances",
    detail: "Le solveur cherche à donner à chaque équipe son nombre de séances par semaine. C'est une cible, pas une loi : quand le gymnase manque, une séance peut sauter — et le planning vous le dit.",
  },
];

/**
 * La PRÉSENTATION des 4 règles réglables (libellés humains + description). La clé EST celle du
 * contrat moteur ; ici on ne fait que la nommer pour un non-technicien.
 */
export const WELLBEING_RULES: { ruleKey: ImplicitRuleKey; title: string; detail: string }[] = [
  { ruleKey: "coachRestDay", title: "Chaque coach garde un jour de repos", detail: "Au moins le nombre de jours choisi sans séance entre le lundi et le vendredi (les week-ends ne comptent pas)." },
  { ruleKey: "salarieDistribution", title: "Au moins un salarié présent chaque jour ouvré", detail: "Sur chaque jour de la semaine où le club tourne, au moins un coach salarié encadre une séance." },
  { ruleKey: "maxConsecutiveSessions", title: "Jamais trop de créneaux dos-à-dos", detail: "Un même coach n'enchaîne pas plus que le nombre choisi de créneaux d'affilée — qu'il les encadre ou qu'il y joue." },
  {
    ruleKey: "maxConsecutiveDays",
    title: "Jamais plusieurs jours d'affilée",
    detail:
      "Une même équipe ne s'entraîne pas le nombre de jours choisi à la suite. Attention : demander du repos peut coûter une séance à une équipe dont les créneaux disponibles se suivent.",
  },
  { ruleKey: "ageAscending", title: "Les jeunes avant les grands", detail: "Sur un même gymnase et un même jour, les catégories d'âge se placent du plus jeune au plus âgé." },
];

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

export function isWellbeingKey(ruleKey: string | null): ruleKey is ImplicitRuleKey {
  return null !== ruleKey && WELLBEING_RULES.some((r) => r.ruleKey === ruleKey);
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
              className="h-8 w-16"
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
          <Button size="sm" variant="ghost" className="ml-auto h-8 text-xs" disabled={readOnly} onClick={() => onReset(meta.ruleKey)}>
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

const TRAVEL_INTENSITY_LABEL: Record<VenueTravelRuleIntensity, string> = {
  PREFERRED: "Préféré",
  MANDATORY: "Obligatoire",
};

/**
 * P2-53 RMM-8 — l'entrée de la règle « Trajet entre gymnases », dans l'onglet Base.
 *
 * ⚠ Régime 1 (`.claude/rules/frontend.md`) : le front N'INVENTE aucune règle. L'ACTIVATION est
 * DÉRIVÉE serveur-side de la présence de matrice (`ScheduleConstraintBuilder` — ≥1 ligne active
 * `travelTime`) : cette entrée n'apparaît QUE si la matrice servie porte au moins une ligne. Et
 * l'INTENSITÉ n'est pas redérivée non plus — elle est LUE du backend (`venue_travel_rule_setting`,
 * résolu défaut PREFERRED) et POSTÉE au choix du gestionnaire.
 *
 * PR-4 (levier Obligatoire) : la lecture seule devient un vrai réglage Préféré/Obligatoire, patron
 * exact de l'intensité des passerelles (`TeamLinksSection`) — la copie DIT l'effet ET le risque
 * (« Obligatoire peut rendre le planning infaisable »). Écriture management ; désactivé (lecture)
 * sur une saison archivée, comme les règles bien-être.
 */
export function TravelRuleNotice() {
  const { data: matrix = [] } = useVenueTravelTimes();
  const hasMatrix = matrix.length > 0;
  // Le levier n'est lu QUE si une matrice existe (l'entrée n'apparaît pas sinon) : `enabled`
  // évite une requête inutile chez un club sans matrice.
  const settingQuery = useTravelRuleSetting(hasMatrix);
  const update = useUpdateTravelRuleSetting();
  const readOnly = true === useWorkingSeason()?.isReadonly;

  if (!hasMatrix) {
    return null;
  }

  const intensity: VenueTravelRuleIntensity = settingQuery.data?.intensity ?? "PREFERRED";
  const applyIntensity = (next: VenueTravelRuleIntensity) => {
    if (next !== intensity) {
      update.mutate(next);
    }
  };

  return (
    <div className="mt-2 flex flex-col gap-2 rounded-md border border-border bg-card px-3 py-2">
      <div className="flex flex-wrap items-center gap-2">
        <Route className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium text-foreground">Trajet entre gymnases</p>
        <span className="inline-flex items-center gap-1 rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent">
          <Check className="size-3" aria-hidden="true" />
          Actif
        </span>
      </div>
      <p className="text-xs text-muted-foreground">
        Le planning cherche à enchaîner des gymnases dont le trajet reste dans les temps que vous avez indiqués (en voiture ou à pied selon le coach). Elle s'est activée parce que vous
        avez renseigné les temps de trajet.
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <label htmlFor="travel-rule-intensity" className="text-xs font-medium text-foreground">
          Niveau
        </label>
        {readOnly ? (
          <span className="text-xs text-muted-foreground">{TRAVEL_INTENSITY_LABEL[intensity]}</span>
        ) : (
          <Select
            id="travel-rule-intensity"
            aria-label="Niveau de la règle de trajet entre gymnases"
            className="h-9 w-40"
            value={intensity}
            disabled={update.isPending}
            onChange={(e) => applyIntensity(e.target.value as VenueTravelRuleIntensity)}
          >
            <option value="PREFERRED">{TRAVEL_INTENSITY_LABEL.PREFERRED}</option>
            <option value="MANDATORY">{TRAVEL_INTENSITY_LABEL.MANDATORY}</option>
          </Select>
        )}
      </div>
      {/* Patron exact des passerelles (TeamLinksSection) : UNE ligne dit les DEUX niveaux, le
          risque d'Obligatoire compris — pour que la conséquence soit lue AVANT de basculer. */}
      <p className="text-xs text-muted-foreground">
        <em>Préféré</em> : une préférence souple — le planning s'y tient quand il peut. <em>Obligatoire</em> : il ne dépassera jamais vos temps de trajet — au risque de rendre le
        planning infaisable si les enchaînements sont trop serrés.
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

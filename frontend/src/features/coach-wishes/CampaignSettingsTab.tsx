import { isActionableWeek } from "@/features/cockpit/lib/date";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Spinner } from "@/shared/components/ui/spinner";

import type { CampaignSettings } from "./useCampaignSettings";
import { TeamPicker } from "./TeamPicker";
import { frDate } from "./wishSections";

/**
 * Onglet « Réglages » de la fenêtre doléances (ex-moitié Réglages de `CampaignDialog`) : choix
 * des semaines, des équipes et de la date limite de la collecte. L'état et la logique vivent dans
 * `useCampaignSettings` (porté par le conteneur, qui tient aussi le pied de modale « Créer /
 * Enregistrer ») ; ce composant n'en est que le rendu.
 *
 * P2-63 PR 4 (Q8) — la collecte n'existe QU'APRÈS la naissance du planning : sans planning sur la
 * période, le réglage invite à le créer d'abord (geste « Adapter », `onRequestPlanning`) plutôt
 * que d'offrir un choix de semaines vide. Les semaines offertes DÉRIVENT des plannings.
 */
export function CampaignSettingsTab({ settings, onRequestPlanning }: { settings: CampaignSettings; onRequestPlanning?: () => void }) {
  const { availableWeeks, weeks, toggleWeek, today, pickerTeams, ineligibleIds, tiers, teamIds, setTeamIds, deadline, setDeadline, failed, hasPlannings, planningsLoading } = settings;

  if (planningsLoading) {
    return (
      <div className="flex justify-center py-8">
        <Spinner className="size-6" />
      </div>
    );
  }

  if (!hasPlannings) {
    return (
      <NoticeBanner tone="muted">
        <p>
          Créez d'abord le planning des vacances : la collecte de doléances couvre les semaines de ce
          planning. Adaptez la période pour le créer, puis revenez ouvrir la collecte.
        </p>
        {onRequestPlanning ? (
          <Button size="sm" onClick={onRequestPlanning}>
            Adapter cette période
          </Button>
        ) : null}
      </NoticeBanner>
    );
  }

  return (
    <>
      <fieldset>
        <legend className="text-sm font-medium">Semaines</legend>
        <div className="mt-1 space-y-1">
          {0 === availableWeeks.length ? (
            <EmptyHint>Aucune semaine disponible sur cette période.</EmptyHint>
          ) : (
            availableWeeks.map((w) => (
              <label key={w.monday} className="flex items-center gap-2 text-sm">
                {/* Le marqueur vit DANS le nom accessible : `aria-label` écrase le
                    contenu du label, donc un marqueur posé à côté n'est jamais annoncé et
                    un lecteur d'écran entend des semaines indistinctes (revue #344). */}
                <input
                  type="checkbox"
                  className="size-4 accent-[var(--accent)]"
                  checked={weeks.has(w.monday)}
                  onChange={() => toggleWeek(w.monday)}
                  aria-label={`Semaine du ${frDate(w.startDate)}${isActionableWeek(w, today) ? "" : " (révolue)"}`}
                />
                Semaine du {frDate(w.startDate)} au {frDate(w.endDate)}
                {isActionableWeek(w, today) ? null : <span className="text-xs italic text-muted-foreground">révolue</span>}
              </label>
            ))
          )}
        </div>
      </fieldset>

      <TeamPicker teams={pickerTeams} ineligibleIds={ineligibleIds} tiers={tiers} selected={teamIds} onChange={setTeamIds} />

      <label className="mt-4 flex items-center gap-2 text-sm font-medium">
        À renvoyer avant le
        <Input type="date" min={today} className="w-40" value={deadline} onChange={(e) => setDeadline(e.target.value)} aria-label="Date limite" />
      </label>

      {failed ? <p className="mt-3 text-sm text-destructive">Enregistrement impossible. Vérifiez les semaines et équipes choisies.</p> : null}
    </>
  );
}

import { isActionableWeek } from "@/features/cockpit/lib/date";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";

import type { CampaignSettings } from "./useCampaignSettings";
import { TeamPicker } from "./TeamPicker";

const frDate = (iso: string): string => {
  const [y, m, d] = iso.split("-");
  return `${d}/${m}/${y}`;
};

/**
 * Onglet « Réglages » de la fenêtre doléances (ex-moitié Réglages de `CampaignDialog`) : choix
 * des semaines, des équipes et de la date limite de la collecte. L'état et la logique vivent dans
 * `useCampaignSettings` (porté par le conteneur, qui tient aussi le pied de modale « Créer /
 * Enregistrer ») ; ce composant n'en est que le rendu. Déplacement VERBATIM de `CampaignDialog`.
 */
export function CampaignSettingsTab({ settings }: { settings: CampaignSettings }) {
  const { availableWeeks, weeks, toggleWeek, today, pickerTeams, ineligibleIds, tiers, teamIds, setTeamIds, deadline, setDeadline, failed } = settings;

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

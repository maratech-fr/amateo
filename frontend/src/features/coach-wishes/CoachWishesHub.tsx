import { useEffect, useRef, useState } from "react";

import type { CalendarEntry } from "@/features/cockpit/api";
import { useWorkingSeason } from "@/shared/session/queries";
import { Button } from "@/shared/components/ui/button";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Modal } from "@/shared/components/ui/modal";
import { FullPageSpinner, Spinner } from "@/shared/components/ui/spinner";
import { TabPanel, Tabs } from "@/shared/components/ui/tabs";
import { readState } from "@/shared/lib/readState";

import type { CoachWishCampaign } from "./campaignApi";
import { useCoachWishCampaigns } from "./campaignQueries";
import { useCoachWishes } from "./queries";
import { CampaignSettingsTab } from "./CampaignSettingsTab";
import { SollicitationTab } from "./SollicitationTab";
import { useCampaignSettings } from "./useCampaignSettings";
import { WishesTab } from "./WishesTab";

/**
 * La fenêtre UNIQUE des doléances d'une période de vacances (fusion #10, 2026-10-09) : trois
 * onglets — « Doléances » (la todo), « Sollicitation » (le suivi de collecte, seulement si une
 * campagne existe) et « Réglages » (création/paramétrage de la collecte). Titre
 * « Doléances des coachs — {période} », `size="lg"`, ouverte depuis le cockpit (carte radar) ET
 * le wizard (bandeau période).
 *
 * La fenêtre RÉSOUT elle-même sa campagne (pas de prop `campaign`) : le wizard ne câble rien de
 * plus que `mother`/`weekFilter`. On attend que les deux lectures (campagnes + doléances) soient
 * POSÉES avant de décider l'onglet d'ouverture — spinner pendant, `LoadErrorHint` + réessayer en
 * cas d'échec, JAMAIS un « pas de campagne » crédible dérivé d'un échec de lecture (règle
 * `readState` : un échec ne se rend pas comme un vide).
 */
export function CoachWishesHub({
  mother,
  weekFilter,
  source,
  onClose,
  onRequestPlanning,
}: {
  mother: CalendarEntry;
  weekFilter: string | null;
  source: "wizard" | "cockpit";
  onClose: () => void;
  /** P2-63 PR 4 — « Adapter cette période » depuis l'état « sans planning » (geste du cockpit). */
  onRequestPlanning?: () => void;
}) {
  const season = useWorkingSeason();
  const campaignsQuery = useCoachWishCampaigns();
  const wishesQuery = useCoachWishes(mother.id);

  const title = `Doléances des coachs — ${mother.title}`;

  const campaignsState = readState(campaignsQuery);
  const wishesState = readState(wishesQuery);

  // Échec d'abord : la décision fondateur interdit de retomber sur « pas de campagne » quand la
  // lecture a simplement échoué (sinon l'onglet Sollicitation disparaîtrait et l'ouverture
  // tomberait sur Réglages à tort).
  if ("failed" === campaignsState || "failed" === wishesState) {
    return (
      <Modal label="Doléances des coachs" title={title} size="lg" onClose={onClose}>
        <LoadErrorHint
          onRetry={() => {
            void campaignsQuery.refetch();
            void wishesQuery.refetch();
          }}
        >
          Les doléances n'ont pas pu être chargées.
        </LoadErrorHint>
      </Modal>
    );
  }

  if ("loading" === campaignsState || "loading" === wishesState) {
    return (
      <Modal label="Doléances des coachs" title={title} size="lg" onClose={onClose}>
        <FullPageSpinner />
      </Modal>
    );
  }

  const existing = (campaignsQuery.data ?? []).find((c) => c.calendarEntryId === mother.id) ?? null;
  const wishes = wishesQuery.data ?? [];

  return <CoachWishesHubReady mother={mother} weekFilter={weekFilter} source={source} season={season} existing={existing} wishesCount={wishes.length} title={title} onClose={onClose} onRequestPlanning={onRequestPlanning} />;
}

/** Libellé compté de l'onglet « Sollicitation » (décision fondateur 2026-10-09 : « 1 coach n'a
 *  pas répondu sur 30, ce n'est pas pareil que 28 sans réponse sur 30 »). N = coachs sans réponse,
 *  M = total des coachs de la campagne. N = 0 → « tous ont répondu ». Ce texte EST le nom
 *  accessible de l'onglet (la primitive `Tabs` rend le libellé comme texte du bouton). */
function sollicitationLabel(campaign: CoachWishCampaign): string {
  const total = campaign.coaches.length;
  const notResponded = campaign.coaches.filter((c) => null === c.respondedAt).length;
  return 0 === notResponded ? "Sollicitation · tous ont répondu" : `Sollicitation · ${notResponded}/${total} en attente`;
}

/** Onglet d'ouverture — décidé UNE fois, lectures déjà POSÉES (décision 3). */
function initialTab(source: "wizard" | "cockpit", existing: CoachWishCampaign | null, wishesCount: number, weeksNeedAttention: boolean): string {
  if ("wizard" === source) {
    return "doleances";
  }
  if (null !== existing) {
    return weeksNeedAttention ? "reglages" : "doleances";
  }
  return wishesCount > 0 ? "doleances" : "reglages";
}

function CoachWishesHubReady({
  mother,
  weekFilter,
  source,
  season,
  existing,
  wishesCount,
  title,
  onClose,
  onRequestPlanning,
}: {
  mother: CalendarEntry;
  weekFilter: string | null;
  source: "wizard" | "cockpit";
  season: { startDate: string; endDate: string } | null;
  existing: CoachWishCampaign | null;
  wishesCount: number;
  title: string;
  onClose: () => void;
  onRequestPlanning?: () => void;
}) {
  // Le focus suit la bascule programmatique (après création) : une ref + un effet (interdits
  // pendant le rendu, autorisés ici) plutôt qu'un état, qu'aucun rendu ne devrait porter.
  const focusTabAfterSwitch = useRef(false);

  const settings = useCampaignSettings({ entry: mother, season, existing, onCreated: handleCreated });
  // Lectures déjà posées ici : l'onglet d'ouverture se calcule une fois, sans risque de cache vide.
  const [activeTab, setActiveTab] = useState<string>(() => initialTab(source, existing, wishesCount, settings.weeksNeedAttention));

  function handleCreated() {
    // ⚠ On EMPORTE le focus avec l'onglet (revue #346) : le bouton qui vient d'être pressé se
    // retrouve dans un panneau démonté, le navigateur rend alors le focus à `<body>` — et le
    // piège à focus comme Échap, qui écoutent sur le panneau de la modale, cessent d'agir.
    setActiveTab("sollicitation");
    focusTabAfterSwitch.current = true;
  }

  useEffect(() => {
    if (focusTabAfterSwitch.current) {
      focusTabAfterSwitch.current = false;
      document.getElementById(`doleances-tab-${activeTab}`)?.focus();
    }
  });

  const campaign = settings.campaign;
  const tabs = [
    { id: "doleances", label: "Doléances" },
    ...(null !== campaign ? [{ id: "sollicitation", label: sollicitationLabel(campaign) }] : []),
    { id: "reglages", label: "Réglages" },
  ];

  return (
    <Modal
      label="Doléances des coachs"
      title={title}
      size="lg"
      onClose={onClose}
      // Pied épinglé pour l'onglet RÉGLAGES seulement — c'est lui qui porte le geste
      // « Créer / Enregistrer ». Les onglets Doléances et Sollicitation gèrent leurs propres
      // actions dans leur contenu, ils n'ont pas de pied de modale.
      footer={
        "reglages" === activeTab ? (
          <>
            <Button variant="ghost" size="sm" onClick={onClose}>
              Fermer
            </Button>
            <Button size="sm" disabled={!settings.canSave || settings.saving} onClick={settings.save}>
              {settings.saving ? <Spinner className="size-4" /> : null}
              {null === campaign ? "Créer la collecte" : "Enregistrer"}
            </Button>
          </>
        ) : undefined
      }
    >
      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} ariaLabel="Sections des doléances" idPrefix="doleances" />

      {/* Seul le panneau ACTIF est monté (patron A11Y-23) : la todo-list et la liste de coachs
          portent chacune des lectures lourdes, et l'état de réglages vit dans `useCampaignSettings`
          (porté ici), donc survit au démontage du panneau. */}
      {"doleances" === activeTab ? (
        <TabPanel tabId="doleances" idPrefix="doleances" active className="pt-3">
          <WishesTab mother={mother} weekFilter={weekFilter} />
        </TabPanel>
      ) : null}

      {null !== campaign && "sollicitation" === activeTab ? (
        <TabPanel tabId="sollicitation" idPrefix="doleances" active className="pt-3">
          <SollicitationTab
            campaign={campaign}
            onEmailSaved={(coachId, email) => settings.setCampaign((c) => (null === c ? c : { ...c, coaches: c.coaches.map((k) => (k.coachId === coachId ? { ...k, email } : k)) }))}
            onCampaignRefreshed={settings.setCampaign}
          />
        </TabPanel>
      ) : null}

      {"reglages" === activeTab ? (
        <TabPanel tabId="reglages" idPrefix="doleances" active className="pt-3">
          <CampaignSettingsTab settings={settings} onRequestPlanning={onRequestPlanning} />
        </TabPanel>
      ) : null}
    </Modal>
  );
}

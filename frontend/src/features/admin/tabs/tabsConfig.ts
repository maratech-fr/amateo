import { Activity, AlertTriangle, Building2, Presentation, Server, type LucideIcon } from "lucide-react";

import { ADMIN_DASHBOARD_TEXTS } from "../texts/dashboard";

export interface TabSubDef {
  id: string;
  label: string;
}

export interface TabDef {
  id: string;
  label: string;
  icon?: LucideIcon;
  subTabs?: TabSubDef[];
}

/**
 * 5 onglets de la console superadmin (réorganisation PR1). Ordre fondateur :
 * Clubs (défaut) · Statistiques · Santé · Incidents · Exploitation. « Silence quand tout
 * va bien » : Santé et Incidents portent une pastille d'alerte (calculée au niveau page).
 * Incidents regroupe les quatre journaux en sous-onglets (signalements par défaut).
 */
export const ADMIN_TABS: TabDef[] = [
  { id: "clubs", label: ADMIN_DASHBOARD_TEXTS.tabClubs, icon: Building2 },
  { id: "statistiques", label: ADMIN_DASHBOARD_TEXTS.tabStatistiques, icon: Activity },
  { id: "sante", label: ADMIN_DASHBOARD_TEXTS.tabSante, icon: Server },
  {
    id: "incidents",
    label: ADMIN_DASHBOARD_TEXTS.tabIncidents,
    icon: AlertTriangle,
    subTabs: [
      { id: "signalements", label: ADMIN_DASHBOARD_TEXTS.subSignalements },
      { id: "errors", label: ADMIN_DASHBOARD_TEXTS.subErrors },
      { id: "messenger", label: ADMIN_DASHBOARD_TEXTS.subMessenger },
      { id: "audit", label: ADMIN_DASHBOARD_TEXTS.subAudit },
    ],
  },
  { id: "exploitation", label: ADMIN_DASHBOARD_TEXTS.tabExploitation, icon: Presentation },
];

export const DEFAULT_TAB = "clubs";
export const DEFAULT_SUBTAB = "signalements";
export const STORAGE_KEY = "admin-dashboard-tab";

const TAB_IDS = new Set(ADMIN_TABS.map((t) => t.id));
const INCIDENTS_SUB_IDS = new Set(
  ADMIN_TABS.find((t) => t.id === "incidents")?.subTabs?.map((s) => s.id) ?? [],
);

/**
 * Report des anciens liens (`?tab=` ou `localStorage`) vers les 5 nouveaux onglets : la
 * réorganisation ne doit casser aucun favori ni aucun onglet mémorisé.
 */
const LEGACY_TAB_MAP: Record<string, string> = {
  "vue-densemble": "statistiques",
  infrastructure: "sante",
  jobs: "sante",
  referentiels: "sante",
  demos: "clubs",
  signalements: "incidents",
  journaux: "incidents",
};

export function isValidTab(id: string | null): id is string {
  return id !== null && TAB_IDS.has(id);
}

export function isValidSubTab(id: string | null): id is string {
  return id !== null && INCIDENTS_SUB_IDS.has(id);
}

/** Résout un id d'onglet (canonique ou ancien) vers un des 5 onglets, ou null si inconnu. */
function canonicalTab(id: string | null): string | null {
  if (id === null) return null;
  if (TAB_IDS.has(id)) return id;
  return LEGACY_TAB_MAP[id] ?? null;
}

/** Résout l'onglet actif : param URL → localStorage → défaut, en reportant les anciens ids. */
export function resolveActiveTab(urlTab: string | null, stored: string | null): string {
  return canonicalTab(urlTab) ?? canonicalTab(stored) ?? DEFAULT_TAB;
}

/**
 * Résout le sous-onglet Incidents actif. Hors d'Incidents → défaut (signalements). Dans
 * Incidents : un sous-onglet valide (y compris celui d'un ancien lien « journaux ») est
 * conservé, tout autre cas retombe sur signalements.
 */
export function resolveActiveSubTab(urlTab: string | null, urlSub: string | null): string {
  if (canonicalTab(urlTab) !== "incidents") return DEFAULT_SUBTAB;
  if (isValidSubTab(urlSub)) return urlSub;
  return DEFAULT_SUBTAB;
}

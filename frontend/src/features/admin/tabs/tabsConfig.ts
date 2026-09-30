import { Activity, Building2, Database, History, MessageSquareWarning, Presentation, Server, Workflow, type LucideIcon } from "lucide-react";

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

/** 8 onglets principaux de la console superadmin. */
export const ADMIN_TABS: TabDef[] = [
  { id: "vue-densemble", label: "Vue d'ensemble", icon: Activity },
  { id: "infrastructure", label: "Infrastructure", icon: Server },
  { id: "jobs", label: "Jobs", icon: Workflow },
  { id: "referentiels", label: "Référentiels", icon: Database },
  { id: "clubs", label: "Comptes clubs", icon: Building2 },
  { id: "demos", label: "Démos", icon: Presentation },
  { id: "signalements", label: "Signalements", icon: MessageSquareWarning },
  {
    id: "journaux",
    label: "Journaux",
    icon: History,
    subTabs: [
      { id: "audit", label: "Audit" },
      { id: "messenger", label: "Échecs async" },
      { id: "errors", label: "Erreurs système" },
    ],
  },
];

export const DEFAULT_TAB = "vue-densemble";
export const DEFAULT_SUBTAB = "audit";
export const STORAGE_KEY = "admin-dashboard-tab";

const TAB_IDS = new Set(ADMIN_TABS.map((t) => t.id));
const JOURNAUX_SUB_IDS = new Set(
  ADMIN_TABS.find((t) => t.id === "journaux")?.subTabs?.map((s) => s.id) ?? [],
);

export function isValidTab(id: string | null): id is string {
  return id !== null && TAB_IDS.has(id);
}

export function isValidSubTab(id: string | null): id is string {
  return id !== null && JOURNAUX_SUB_IDS.has(id);
}

/** Résout l'onglet actif : param URL → localStorage → défaut. */
export function resolveActiveTab(urlTab: string | null, stored: string | null): string {
  if (isValidTab(urlTab)) return urlTab;
  if (isValidTab(stored)) return stored;
  return DEFAULT_TAB;
}

/** Résout le sous-onglet Journaux actif : param URL → défaut. */
export function resolveActiveSubTab(urlSub: string | null): string {
  if (isValidSubTab(urlSub)) return urlSub;
  return DEFAULT_SUBTAB;
}
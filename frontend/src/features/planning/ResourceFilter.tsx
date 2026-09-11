import { Check, ChevronDown } from "lucide-react";
import { useEffect, useId, useRef, useState } from "react";

import { cn } from "@/shared/lib/utils";

import type { GridResourceGroup } from "./lib/grid";
import type { ViewMode } from "./store";

const LABELS: Record<ViewMode, string> = {
  gymnase: "Gymnases",
  coach: "Coachs",
  equipe: "Équipes",
  jour: "Jours",
  // P3-20 : la vue « club » se filtre par ÉQUIPE — le libellé le dit.
  club: "Équipes",
};

interface ResourceFilterProps {
  viewMode: ViewMode;
  /** Grouped resources — equipe view carries rank headers, other views one flat null-label group. */
  groups: GridResourceGroup[];
  selected: string[];
  onToggle: (id: string) => void;
  onClear: () => void;
}

export function ResourceFilter({ viewMode, groups, selected, onToggle, onClear }: ResourceFilterProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  // `useId` et non un id littéral : ce composant est monté DEUX fois dans la même page
  // (modale doléances — coachs et équipes), et deux `aria-controls` identiques
  // désigneraient le même panneau.
  const panelId = useId();
  const triggerRef = useRef<HTMLButtonElement>(null);
  const wrapperRef = useRef<HTMLDivElement>(null);

  // Échap ferme la puce et rend le focus au déclencheur (WCAG 2.1.2). Patron REPRIS de
  // `shared/components/ui/listbox.tsx:74-81 / :203-219` : listener keydown NATIF sur le
  // wrapper (pas le handler React synthétique), + `stopPropagation()`. Pourquoi ce couple :
  // `CoachWishesModal.tsx:114-115` héberge DEUX de ces puces dans un `Modal` dont
  // `useModalA11y` (`shared/lib/useModalA11y.ts:76-80`) écoute Échap en NATIF sur le panel.
  // Le wrapper est un DESCENDANT du panel : un listener natif bouillonnant y tire AVANT
  // celui du panel, et `stopPropagation()` empêche Échap de fermer AUSSI la fenêtre. Un
  // handler React synthétique (délégué à la racine) arriverait trop tard. Le clic-voile,
  // lui, ne restitue pas le focus (geste souris, comme `Listbox`) — voile conservé à l'identique.
  useEffect(() => {
    if (!open) {
      return;
    }
    const wrapper = wrapperRef.current;
    if (null === wrapper) {
      return;
    }
    const onKeyDown = (event: KeyboardEvent): void => {
      if ("Escape" === event.key) {
        event.preventDefault();
        event.stopPropagation();
        setOpen(false);
        triggerRef.current?.focus();
      }
    };
    wrapper.addEventListener("keydown", onKeyDown);
    return () => wrapper.removeEventListener("keydown", onKeyDown);
  }, [open]);

  if (groups.every((g) => 0 === g.resources.length)) {
    return null;
  }

  const needle = query.trim().toLowerCase();
  const filteredGroups = groups
    .map((g) => ({ ...g, resources: g.resources.filter((r) => r.label.toLowerCase().includes(needle)) }))
    .filter((g) => g.resources.length > 0);
  const count = selected.length;
  // Un filtre POSÉ doit se voir. Sans état visuel distinct, « Gymnases : 3 sélectionnés »
  // et « Gymnases : tous » portaient exactement le même habillage : une grille filtrée se
  // lisait comme une grille complète, et le gestionnaire concluait sur ce qu'il ne voyait
  // pas (P4-43). C'est ce silence, plus que la taille du bouton, qui le rendait invisible.
  const active = count > 0;
  const summary = active ? `${count} sélectionné${count > 1 ? "s" : ""}` : "tous";

  return (
    <div ref={wrapperRef} className="relative inline-block">
      {/* Motif « disclosure » : `aria-expanded` + `aria-controls`. Pas de
          `aria-haspopup="listbox"` — le panneau porte un champ de recherche et des boutons
          bascules, pas des `option` ; l'annoncer en listbox promettrait à l'AT une
          navigation qui n'existe pas. */}
      <button
        ref={triggerRef}
        type="button"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((o) => !o)}
        className={cn(
          "flex h-8 items-center gap-2 rounded-md border bg-background px-3 text-sm",
          // ⚠ L'état actif ne porte AUCUN fond teinté, et son survol n'en pose pas non plus.
          // Mesuré : `text-accent` sur `bg-accent/10` tombe à 4.18:1 en thème clair, sur
          // `bg-muted` à 4.37:1 — sous les 4.5:1 que WCAG 1.4.3 exige d'un texte normal
          // (14 px medium n'est pas du « grand texte »). Même `accent/05` échoue (4.47:1).
          // Sur le fond nu : 4.77:1 en clair, 7.41:1 en sombre. La distinction se fait donc
          // par la bordure, la couleur du texte, la graisse — et le libellé lui-même.
          active ? "border-accent font-medium text-accent hover:ring-1 hover:ring-accent/50" : "border-border text-foreground hover:bg-muted",
        )}
      >
        <span className={cn("font-medium", active ? "" : "text-muted-foreground")}>{LABELS[viewMode]} :</span>
        <span>{summary}</span>
        <ChevronDown className={cn("size-3.5", active ? "" : "text-muted-foreground")} />
      </button>

      {open ? (
        <>
          {/* Voile de fermeture — `aria-hidden` sur un élément FOCUSABLE est une violation
              axe (`aria-hidden-focus`) : il faut aussi le sortir de l'ordre de tabulation,
              sinon le clavier atterrit sur un bouton que rien n'annonce. */}
          <button type="button" aria-hidden tabIndex={-1} className="fixed inset-0 z-50 cursor-default" onClick={() => setOpen(false)} />
          <div id={panelId} className="absolute z-[60] mt-1 w-72 rounded-md border border-border bg-card shadow-md">
            <div className="border-b border-border p-2">
              <input
                // eslint-disable-next-line jsx-a11y/no-autofocus -- search field inside a just-opened popover; focusing it is the expected behaviour
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                // A11Y-10 : un `placeholder` N'EST PAS un nom accessible — il disparaît
                // à la première frappe et NVDA/VoiceOver annoncent « zone de texte »,
                // point. Le nom porte AUSSI le périmètre (« Rechercher parmi les
                // gymnases ») : le champ reçoit le focus à l'ouverture, donc c'est la
                // première chose entendue — et deux filtres coexistent dans la même
                // page (modale doléances : coachs ET équipes), où « Rechercher » seul
                // ne dirait pas lequel des deux parle.
                aria-label={`Rechercher parmi les ${LABELS[viewMode].toLowerCase()}`}
                placeholder="Rechercher…"
                className="h-8 w-full rounded-md border border-input bg-background px-2 text-sm outline-none focus:ring-2 focus:ring-ring"
              />
            </div>
            <ul className="max-h-64 overflow-y-auto p-1">
              {count > 0 ? (
                <li>
                  <button type="button" onClick={onClear} className="w-full rounded px-2 py-1 text-left text-xs text-muted-foreground hover:bg-muted">
                    Tout effacer ({count})
                  </button>
                </li>
              ) : null}
              {filteredGroups.map((group, gi) => (
                <li key={group.label ?? `flat-${gi}`}>
                  {null !== group.label ? (
                    <p className="px-2 pb-0.5 pt-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group.label}</p>
                  ) : null}
                  <ul>
                    {group.resources.map((resource) => {
                      // `isSelected` et non `active` : ce dernier nomme désormais l'état du
                      // BOUTON déclencheur (au moins une ressource cochée). Deux `active`
                      // imbriqués, et un style ajouté ici plus tard lirait le mauvais.
                      const isSelected = selected.includes(resource.id);
                      return (
                        <li key={resource.id}>
                          <button
                            type="button"
                            onClick={() => onToggle(resource.id)}
                            className="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-muted"
                          >
                            <Check className={cn("size-4 shrink-0 text-accent", isSelected ? "" : "invisible")} />
                            <span className="truncate">{resource.label}</span>
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                </li>
              ))}
              {0 === filteredGroups.length ? <li className="px-2 py-1 text-xs text-muted-foreground">Aucun résultat</li> : null}
            </ul>
          </div>
        </>
      ) : null}
    </div>
  );
}

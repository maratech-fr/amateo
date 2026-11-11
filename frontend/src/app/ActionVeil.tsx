import { useIsFetching, useIsMutating } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

import { abortLongActions } from "@/shared/lib/longActionAbort";
import { useNavTransition } from "@/shared/stores/navTransitionStore";
import { toast } from "@/shared/stores/toastStore";

import "./ActionVeil.css";

/**
 * Le VOILE BLOQUANT (lot C PR-2). Entre deux clics, quand ça charge, l'écran devient
 * inclicable — « pour bloquer les impatients » : sans lui, l'app n'a pas le temps de suivre et le
 * gestionnaire voit apparaître n'importe quoi.
 *
 * Décisions tranchées (fondateur) :
 *  - Blocage à 0 ms (inert + overlay) pour `Enregistrement` et `Traitement long` — ils protègent un
 *    geste RÉELLEMENT parti, dont le blocage immédiat mange le 2ᵉ clic ; voile visible seulement à
 *    ~250 ms, donc un clic de 90 ms est mangé sans que rien ne clignote. ⚠ Le `Changement de page`,
 *    lui, ne bloque qu'à 250 ms — quand le voile est VISIBLE (voir `blocking`, GO fondateur
 *    2026-08-21) : sur un chargement rien n'est parti, rien à protéger avant que l'utilisateur ne
 *    VOIE pourquoi il est bloqué — sinon des frappes sont mangées en silence.
 *  - Trois contextes : `Enregistrement` · `Changement de page` · `Traitement long`, priorité
 *    long > enregistrement > page.
 *  - Global par défaut, exemptions NOMMÉES par `meta.veil` (`false` = jamais ; `"long"` = long).
 *  - `Changement de page` = un premier chargement (query sans données en cache) QUI SUIT une
 *    TRANSITION d'étape/vue déclenchée par le gestionnaire — jamais un refetch, et surtout JAMAIS
 *    le simple montage d'un écran (règle corrigée le 2026-08-21, GO fondateur : le blocage à 0 ms
 *    sert à manger le 2ᵉ clic d'un geste DÉJÀ parti ; une arrivée n'a rien lancé, et comme le voile
 *    est invisible sous 250 ms, geler un formulaire déjà peint mange les frappes SANS retour visuel
 *    — pire que pas de voile). Déclencheur : `shared/stores/navTransitionStore`.
 *  - Deux échappatoires : au bout de 10 s les contextes COURTS préviennent ET relâchent (une panne
 *    réseau ne doit pas geler l'app jusqu'au F5), le contexte LONG ne relâche JAMAIS au chrono
 *    (relâcher autoriserait un second déplacement concurrent) — il a un bouton « Abandonner ».
 *
 * Monté dans `Providers` (scope react-query, pas routeur : les étapes du wizard sont du zustand,
 * invisibles de `useNavigation`). Le `<Toaster />` reste FRÈRE, hors du wrapper inert : les toasts
 * d'erreur et l'avertissement de relâche doivent rester lisibles.
 *
 * Échelle des z-index (mini-barème) : barre de navigation `z-50` (`app/RootShell.tsx`) < voile
 * `z-[60]`. Le voile passe au-dessus de tout ce qui vit dans l'app.
 */

const REVEAL_MS = 250; // le voile n'apparaît qu'au-delà (les clics, eux, sont bloqués dès 0 ms)
const NAV_SETTLE_MS = 300; // fenêtre « page » : grâce après une transition (démarrage des requêtes + anti-voile-fantôme)
const RELEASE_MS = 10_000; // contextes courts seulement : prévenir + relâcher
const ROTATE_MS = 2_600; // rotation des phrases (contextes courts)
const LONG_STEP_MS = [5_000, 12_000, 25_000] as const; // paliers du traitement long (le 4ᵉ persiste)

type Context = "long" | "saving" | "loading";

const SAVING_PHRASES = [
  "Enregistrement en cours…",
  "On note ça au tableau.",
  "Encore une seconde — rien n'est perdu.",
  "Toujours en cours. L'écran vous rend la main dès que c'est fait.",
];
const LOADING_PHRASES = [
  "Chargement…",
  "On sort le matériel.",
  "Encore un instant.",
  "Toujours en cours — la connexion traîne un peu.",
];
// 4 PALIERS (pas une boucle : le dernier PERSISTE — le bout-en-bout dépasse 30 s sur un club dense,
// une phrase qui revient ferait croire au plantage).
const LONG_PALIERS = [
  "Le système vérifie votre déplacement…",
  "Il rejoue chaque règle sur le planning entier : coachs, gymnases, catégories.",
  "Sur un club chargé, ça peut demander une minute. Personne ne perd sa place.",
  "Toujours en cours. Rien n'est perdu — vous pouvez abandonner ce déplacement.",
];
// Phrases STABLES pour le lecteur d'écran (annoncées UNE fois). La rotation, elle, est décorative
// (aria-hidden) — précédent maison AUD-FRT-23/24 : martelée dans le live, elle couvrirait la page.
const STABLE_TEXT: Record<Context, string> = {
  saving: "Enregistrement en cours, veuillez patienter.",
  loading: "Chargement de la page en cours.",
  long: "Vérification de votre déplacement par le système en cours.",
};

const STABLE_ID = "action-veil-stable";

/** Tableau tactique décoratif : une flèche se trace, un joueur se replace. SVG + CSS pur. */
function TacticalScene() {
  return (
    <svg data-testid="veil-scene" aria-hidden="true" viewBox="0 0 120 90" className="mx-auto h-24 w-32">
      {/* Demi-terrain en filigrane */}
      <g fill="none" stroke="var(--muted-foreground)" strokeWidth="1.5" opacity=".35">
        <rect x="6" y="6" width="108" height="78" rx="4" />
        <path d="M60 6v78" />
        <circle cx="60" cy="45" r="12" />
        <path d="M6 30h16v30H6z" />
        <path d="M114 30h-16v30h16z" />
      </g>
      {/* Flèche de jeu qui se trace */}
      <path
        className="av-arrow"
        d="M34 62 C 46 40, 62 40, 82 30"
        fill="none"
        stroke="var(--accent)"
        strokeWidth="2.5"
        strokeLinecap="round"
        strokeDasharray="130"
      />
      <path className="av-arrowhead" d="M82 30l-9 1 4 8z" fill="var(--accent)" />
      {/* Joueur qui se replace le long de la flèche */}
      <circle className="av-player" cx="34" cy="62" r="5" fill="var(--accent)" />
    </svg>
  );
}

export function ActionVeil({ children }: { children: React.ReactNode }) {
  // false !== veil : voilé PAR DÉFAUT (tout ce qui n'est pas exempté `veil:false`).
  // ⚠ `saving` EXCLUT les mutations EN PAUSE (P5-14) : `networkMode: "online"` (défaut) → hors ligne,
  // une mutation ne DÉMARRE pas et part `isPaused: true`. Rien ne continue en arrière-plan, c'est
  // garé jusqu'au retour du réseau — la voiler bloquerait l'écran pour un geste qui n'est jamais
  // parti, puis toasterait « l'action continue en arrière-plan » (faux). Le bandeau hors-ligne
  // (`app/OfflineBanner.tsx`) porte l'état des gestes en attente.
  // ⚠ TRADE-OFF ASSUMÉ : le contexte `long` (verdict moteur), lui, N'exclut PAS les pauses. Un
  // déplacement sous verdict qui serait garé (réseau coupé en plein vol) doit RESTER sous le régime
  // bouton-Abandonner — jamais relâché en silence, sinon un second déplacement concurrent pourrait
  // partir par-dessus le premier au retour du réseau.
  const longPending = useIsMutating({ predicate: (m) => "long" === m.options.meta?.veil });
  const savingCount = useIsMutating({ predicate: (m) => false !== m.options.meta?.veil && !m.state.isPaused });
  // Un PREMIER chargement seulement (pas de données en cache) — jamais un refetch d'arrière-plan.
  const firstLoads = useIsFetching({ predicate: (q) => false !== q.options.meta?.veil && undefined === q.state.data });

  // « Changement de page » — RÈGLE CORRIGÉE (GO fondateur 2026-08-21). Un premier chargement ne
  // voile QUE s'il suit une TRANSITION déclenchée par le gestionnaire (changement d'étape/vue),
  // JAMAIS sur le simple montage d'un écran : le blocage anti-double-clic sert à manger le 2ᵉ clic
  // d'un geste déjà parti ; une arrivée n'a rien lancé. Et comme le voile est invisible sous
  // 250 ms, geler un formulaire déjà peint mange les frappes SANS retour visuel — le pire échec.
  const navToken = useNavTransition((s) => s.token);
  const seenNavToken = useRef(navToken); // au montage : la valeur courante → une arrivée n'ouvre RIEN
  const [navWindow, setNavWindow] = useState(false);
  useEffect(() => {
    if (navToken === seenNavToken.current) {
      return; // pas de nouveau geste (couvre le tout premier rendu)
    }
    seenNavToken.current = navToken;
    setNavWindow(true);
  }, [navToken]);
  // La fenêtre se referme au SETTLE (plus aucun premier chargement en vol), après un court délai. Ce
  // délai fait DEUX choses : il laisse les requêtes déclenchées par le geste DÉMARRER (elles partent
  // au tick suivant, `firstLoads` est encore 0 à l'instant du geste), et il GARANTIT que la fenêtre
  // ne peut pas rester armée si le geste ne lance aucune requête — sinon un voile fantôme resterait.
  useEffect(() => {
    if (!navWindow || firstLoads > 0) {
      return;
    }
    const t = setTimeout(() => setNavWindow(false), NAV_SETTLE_MS);
    return () => clearTimeout(t);
  }, [navWindow, firstLoads]);

  const context: Context | null =
    longPending > 0 ? "long" : savingCount > 0 ? "saving" : navWindow && firstLoads > 0 ? "loading" : null;
  const busy = null !== context;

  const [released, setReleased] = useState(false);
  const [showPanel, setShowPanel] = useState(false);
  const [rot, setRot] = useState(0);
  const [palier, setPalier] = useState(0);

  // Une fois relâché (10 s), on le reste JUSQU'AU SETTLE — sinon le voile reviendrait et clignoterait.
  const active = busy && !released;

  // MOMENT du blocage (`inert` + overlay) — asymétrie VOULUE (GO fondateur 2026-08-21) :
  //  - `enregistrement` et `long` bloquent dès 0 ms (`active`) : ils protègent un geste RÉELLEMENT
  //    parti, et le blocage immédiat mange son 2ᵉ clic — c'est leur raison d'être.
  //  - `page` (changement d'étape/vue) ne bloque qu'à 250 ms, quand le voile devient VISIBLE
  //    (`showPanel`) : sur un CHARGEMENT rien n'est parti, aucune double-soumission à empêcher, donc
  //    rien à protéger avant que l'utilisateur ne VOIE pourquoi. Effet : transition < 250 ms → la
  //    saisie rentre normalement, aucun `inert` ; transition lente → l'écran se fige ET le montre —
  //    plus une seule frappe mangée en silence, ni à l'arrivée ni sur une transition.
  //  ⚠ Ne JAMAIS fondre les deux régimes en un seul : le NR `ActionVeil.test` garde l'asymétrie.
  const blocking = active && ("loading" !== context || showPanel);

  // Focus : on SUIT le dernier élément réellement focalisé (focusin), pour le mémoriser À
  // L'OUVERTURE du voile — l'`inert` (React 19.2, natif) déplace le focus vers le body SANS émettre
  // de focusin, donc la réf garde bien l'élément d'AVANT. Restauré à la levée (settle, relâche,
  // abandon). Aucun accès aux refs en phase de rendu (interdit par le lint) : tout vit en effet.
  const lastFocus = useRef<HTMLElement | null>(null);
  const restoreTarget = useRef<HTMLElement | null>(null);
  useEffect(() => {
    const onFocusIn = (event: FocusEvent) => {
      const target = event.target;
      if (target instanceof HTMLElement && target !== document.body) {
        lastFocus.current = target;
      }
    };
    document.addEventListener("focusin", onFocusIn);
    return () => document.removeEventListener("focusin", onFocusIn);
  }, []);
  // Clé sur `blocking`, pas `active` : le focus n'est déplacé que quand l'`inert` s'applique
  // réellement (donc à 250 ms pour le contexte page). Sauver/restaurer autour de CE moment.
  useEffect(() => {
    if (blocking) {
      restoreTarget.current = lastFocus.current;
      return;
    }
    const target = restoreTarget.current;
    restoreTarget.current = null;
    if (null !== target && target.isConnected) {
      target.focus();
    }
  }, [blocking]);

  // Phase 1 : le voile devient VISIBLE à 250 ms. Pour `enregistrement`/`long` les clics sont déjà
  // bloqués depuis 0 ms ; pour `page`, le blocage COMMENCE ici (voir `blocking`). Le reset vit dans
  // le cleanup — pas de setState synchrone dans le corps d'un effet (lint du dépôt).
  useEffect(() => {
    if (!active) {
      return;
    }
    const t = setTimeout(() => setShowPanel(true), REVEAL_MS);
    return () => {
      clearTimeout(t);
      setShowPanel(false);
    };
  }, [active]);

  // Relâche à 10 s — CONTEXTES COURTS uniquement. Le long ne relâche jamais au chrono. Le drapeau se
  // réarme au settle via le cleanup (quand `busy`/`context` retombent).
  useEffect(() => {
    if (!busy || "long" === context) {
      return;
    }
    const t = setTimeout(() => {
      setReleased(true);
      toast.info("Ça dure plus que prévu — l'action continue en arrière-plan, l'écran vous rend la main.");
    }, RELEASE_MS);
    return () => {
      clearTimeout(t);
      setReleased(false);
    };
  }, [busy, context]);

  // Rotation des phrases (contextes courts).
  useEffect(() => {
    if (!active || ("saving" !== context && "loading" !== context)) {
      return;
    }
    const t = setInterval(() => setRot((n) => (n + 1) % 4), ROTATE_MS);
    return () => {
      clearInterval(t);
      setRot(0);
    };
  }, [active, context]);

  // Paliers du traitement long (le dernier persiste — pas de boucle).
  useEffect(() => {
    if (!active || "long" !== context) {
      return;
    }
    const timers = LONG_STEP_MS.map((ms, i) => setTimeout(() => setPalier(i + 1), ms));
    return () => {
      timers.forEach(clearTimeout);
      setPalier(0);
    };
  }, [active, context]);

  // En contexte long, le focus va au bouton d'abandon dès l'apparition du voile.
  const abandonRef = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if ("long" === context && showPanel) {
      abandonRef.current?.focus();
    }
  }, [context, showPanel]);

  const isLong = "long" === context;
  const visibleText = "loading" === context ? LOADING_PHRASES[rot] : isLong ? LONG_PALIERS[palier] : SAVING_PHRASES[rot];

  return (
    <>
      {/* `display:contents` : transparent à la mise en page. `inert` natif (React 19.2) : pas de
          ref manuelle. Le contenu derrière est neutralisé dès que ça bloque. */}
      <div data-testid="veil-content" style={{ display: "contents" }} inert={blocking || undefined}>
        {children}
      </div>
      {blocking
        ? createPortal(
            <div
              data-testid="action-veil"
              // L'overlay capte les clics tant que ça BLOQUE (voir `blocking`) : pour
              // `enregistrement`/`long`, dès la phase 0 transparente (le clic est capté avant même
              // que le voile ne s'affiche) ; pour `page`, seulement à 250 ms, une fois VISIBLE.
              // Scrim OPAQUE (bg-background/60) en phase 1, JAMAIS de blur (le flou signalerait
              // « cliquable pour fermer », ce qui serait faux ici).
              className={`fixed inset-0 z-[60] flex items-center justify-center p-4 transition-colors ${showPanel ? "bg-background/60" : "bg-transparent"}`}
            >
              {showPanel && null !== context ? (
                <div
                  className="relative w-full max-w-sm rounded-2xl border border-border bg-card p-6 text-center shadow-lg"
                  {...(isLong
                    ? { role: "dialog", "aria-modal": true, "aria-labelledby": STABLE_ID }
                    : { role: "status", "aria-live": "polite", "aria-busy": true })}
                >
                  <TacticalScene />
                  {/* SEULE la phrase stable vit dans la région live (annoncée une fois). */}
                  <p id={STABLE_ID} className="sr-only">
                    {STABLE_TEXT[context]}
                  </p>
                  {/* Rotation / paliers : décor visuel, aria-hidden (pas de martèlement lecteur d'écran). */}
                  <p aria-hidden="true" className="mt-4 text-sm font-medium text-foreground">
                    {visibleText}
                  </p>
                  {isLong ? (
                    <button
                      ref={abandonRef}
                      type="button"
                      onClick={abortLongActions}
                      className="mt-5 inline-flex items-center justify-center rounded-lg border border-border bg-background px-4 py-2 text-sm font-medium text-foreground hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    >
                      Abandonner ce déplacement
                    </button>
                  ) : null}
                </div>
              ) : null}
            </div>,
            document.body,
          )
        : null}
    </>
  );
}

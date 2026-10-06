import js from '@eslint/js'
import pluginQuery from '@tanstack/eslint-plugin-query'
import globals from 'globals'
import jsxA11y from 'eslint-plugin-jsx-a11y'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'
import { defineConfig, globalIgnores } from 'eslint/config'

// WCAG 2.2 AA guardrail. jsx-a11y's recommended set runs on every frontend change.
// A single knob — A11Y_LEVEL — drives whether violations warn or block the build.
// PR2 fixed the known audit violations (A11Y-01/03/05/06), so it is now 'error'
// (blocking). Flip back to 'warn' only to temporarily unblock a large refactor.
const A11Y_LEVEL = 'error'

// We ONLY re-severity the rules recommended actually turns ON, and we PRESERVE each
// rule's tuned options — blindly remapping every key would (a) force on the rules
// recommended deliberately sets to 'off' (e.g. the deprecated label-has-for, which
// double-flags correctly htmlFor/id-associated labels) and (b) drop the option
// objects on tuned rules.
const jsxA11yRules = Object.fromEntries(
  Object.entries(jsxA11y.flatConfigs.recommended.rules).flatMap(([rule, config]) => {
    const severity = Array.isArray(config) ? config[0] : config
    if (severity === 'off' || severity === 0) {
      return [] // keep recommended's disabled rules disabled
    }
    const options = Array.isArray(config) ? config.slice(1) : []
    return [[rule, [A11Y_LEVEL, ...options]]]
  }),
)

// Banned migration anti-patterns (frontend-strategy §3) — GLOBAL, tous fichiers.
const bannedSyntax = [
  {
    selector: "MemberExpression[object.name='ReactDOM'][property.name='render']",
    message: 'ReactDOM.render is removed in React 19 — use createRoot().render().',
  },
  {
    selector: "Property[key.name='onSuccess'][parent.parent.callee.name='useQuery']",
    message: 'onSuccess was removed from useQuery in TanStack Query v5 — use useEffect on data, or select (useMutation.onSuccess is still valid).',
  },
]

// Uniformité des sélecteurs (série « uniformité des écrans » PR 3/7, GO fondateur 2026-10-01).
// Portée : les écrans PRODUIT (src/features/** + src/app/**) — JAMAIS src/features/admin/**
// (palette console, décision UXC-12) ni src/shared/components/ui/** (le foyer des primitives, hors
// du glob de portée : c'est là que vit le SEUL `<select>` natif légitime et la className interne).
const selectorSyntax = [
  {
    // Un `<select>` natif brut : la primitive Select l'habille (flèche, focus, disabled) de façon
    // uniforme — et TeamSelect/VenueSelect portent le sélecteur riche d'une équipe / d'un gymnase.
    selector: "JSXOpeningElement[name.name='select']",
    message: 'Utilisez la primitive Select (ou TeamSelect/VenueSelect pour une équipe/un gymnase).',
  },
  {
    // La largeur d'un sélecteur vit sur la BOÎTE (wrapperClassName), pas sur le contrôle intérieur :
    // une classe w-/min-w-/max-w-/flex-/shrink-/grow-/basis- en className cible le `<select>`/`<button>`
    // interne (toujours `w-full`), jamais la boîte que la ligne flex mesure — même piège que le Listbox.
    // AST volontairement PRÉCIS : className LITTÉRALE seulement ; un `cn(...)`/variable n'est pas couvert
    // (choix documenté — ces cas restent à la revue, cf. rapport PR 3/7).
    selector:
      "JSXOpeningElement[name.name=/^(Listbox|TeamSelect|VenueSelect|Select)$/] > JSXAttribute[name.name='className'] > Literal[value=/(^|\\s)(w-|min-w-|max-w-|flex(\\s|-|$)|shrink(\\s|-|$)|grow(\\s|-|$)|basis-)/]",
    message: "La largeur d'un sélecteur passe par wrapperClassName (la className vise le contrôle intérieur).",
  },
]

// Uniformité des HAUTEURS (série « uniformité des écrans » PR 7/7, GO fondateur 2026-10-01, décision
// B sur captures A/B : 36 px = `h-9` pour TOUT contrôle posé dans une ligne). La hauteur d'un contrôle
// partagé vient de la PRIMITIVE (`h-9` par défaut) ou d'une variante NOMMÉE (`compact` → `h-8` pour un
// tableau dense ; `size="icon-sm"` → 36 px) — jamais une classe `h-8`/`h-10`/`h-11` LITTÉRALE forcée en
// className (qui remélangeait les hauteurs d'une même ligne). Même modèle que la règle de largeur
// (PR 3/7) : admin exempté, shared/ hors du glob (c'est le foyer des primitives). Le `Literal`
// DESCENDANT (combinateur espace) attrape aussi un `h-8` niché dans un `cn("h-8", …)` ; comme pour la
// largeur, un `className={variable}` ou un `className={`h-8 …`}` (template literal) n'est PAS couvert
// par l'AST — choix documenté, ces cas rares restent à la revue.
const heightSyntax = [
  {
    selector:
      "JSXOpeningElement[name.name=/^(Button|Input|Select|Listbox|TeamSelect|VenueSelect)$/] > JSXAttribute[name.name='className'] Literal[value=/(^|[^\\w-])h-(7|8|10|11)([^\\w-]|$)/]",
    message:
      "La hauteur d'un contrôle vient de la primitive (h-9 par défaut) ou d'une variante nommée (prop `compact` → h-8 ; `size=\"icon-sm\"` → 36 px) — jamais une classe h-7/h-8/h-10/h-11 littérale en className. `h-7` (28 px) contournait la norme 36 px (UXC-29, audit 2026-10-03) ; série « uniformité des écrans », PR 7/7.",
  },
]

export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
      pluginQuery.configs['flat/recommended'],
      jsxA11y.flatConfigs.recommended,
    ],
    languageOptions: {
      globals: globals.browser,
    },
    rules: {
      // WCAG guardrail (severity via A11Y_LEVEL above).
      ...jsxA11yRules,
      // Our design-system controls are custom components, not native <input>/<select>;
      // tell label-has-associated-control so nested `<label>…<Input/></label>` is seen
      // as correctly associated instead of a false "label without control".
      'jsx-a11y/label-has-associated-control': [A11Y_LEVEL, { controlComponents: ['Input', 'Select', 'TeamSelect'] }],
      // shadcn/ui + router export constants (buttonVariants, router) alongside components.
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
      // Banned migration anti-patterns (frontend-strategy §3) — voir `bannedSyntax`.
      'no-restricted-syntax': ['error', ...bannedSyntax],
    },
  },
  // Uniformité des sélecteurs — écrans produit seulement (voir `selectorSyntax`). Ce bloc REMPLACE
  // `no-restricted-syntax` pour src/features/** + src/app/** (le flat config ne fusionne pas les
  // options d'une règle-tableau) : il REPREND donc `bannedSyntax` pour ne rien perdre, puis ajoute
  // les deux règles de sélecteur. Admin exempté ; shared/ hors du glob (donc naturellement exempté).
  {
    files: ['src/features/**/*.{ts,tsx}', 'src/app/**/*.{ts,tsx}'],
    ignores: ['src/features/admin/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-syntax': ['error', ...bannedSyntax, ...selectorSyntax, ...heightSyntax],
    },
  },
  // AUD-FRT-21 — GELER la direction des dépendances : `shared/` est la couche du DESSOUS,
  // elle ne remonte pas vers `features/`. L'audit du 2026-08-19 a compté 8 remontées dans
  // 6 fichiers, installées une par une sans que rien ne les voie : c'est la SILENCE de la
  // dérive qui est le problème, pas les 8 cas.
  //
  // Cette règle a d'abord empêché la 9e remontée pendant que les 8 existantes restaient en
  // dette explicite ; une exception qu'on doit écrire est une exception qu'on voit, et qui
  // coûte assez cher pour qu'on préfère la corriger.
  // Résorbée le 2026-08-22 (P4-123) : la session est descendue dans `shared/session/`, le
  // statut dans `shared/lib/`, `DeletionImpact` dans `shared/api/`, et le flux SSE est remonté
  // chez `features/planning/`. La règle est désormais TOTALE — plus aucune exception de prod.
  {
    files: ['src/shared/**/*.{ts,tsx}'],
    ignores: [
      // Les TESTS de shared/ peuvent lire les features : la contrainte porte sur le graphe de
      // dépendances LIVRÉ, pas sur ce qu'un test a le droit d'observer. Un helper partagé
      // (`days`, `time`) gagne à être vérifié contre l'usage RÉEL qu'en font planning/wizard —
      // l'interdire pousserait à re-décrire l'usage dans le test, donc à le laisser dériver.
      '**/*.test.{ts,tsx}',
      // Dette AUD-FRT-21 résorbée le 2026-08-22 (P4-123) — aucune ligne à AJOUTER ici.
    ],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            {
              group: ['@/features/*', '../features/*', '../../features/*', '../../../features/*'],
              message:
                "shared/ est SOUS features/ : il ne doit pas en dépendre. Ce qui est partagé descend dans shared/ ; ce qui appartient à une feature reste chez elle. (AUD-FRT-21 — si tu crois avoir un cas légitime, c'est probablement que le code partagé est mal placé.)",
            },
          ],
        },
      ],
    },
  },
])

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
      // Banned migration anti-patterns (frontend-strategy §3).
      'no-restricted-syntax': [
        'error',
        {
          selector: "MemberExpression[object.name='ReactDOM'][property.name='render']",
          message: 'ReactDOM.render is removed in React 19 — use createRoot().render().',
        },
        {
          selector: "Property[key.name='onSuccess'][parent.parent.callee.name='useQuery']",
          message: 'onSuccess was removed from useQuery in TanStack Query v5 — use useEffect on data, or select (useMutation.onSuccess is still valid).',
        },
      ],
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

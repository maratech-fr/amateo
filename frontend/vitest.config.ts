import { readFileSync } from 'node:fs'
import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config'

// P4-166 (B3) — le plancher de couverture vit dans la maison UNIQUE `coverage-floor.json`
// (racine du dépôt), jamais en dur ici. La couverture ne se collecte que sous `--coverage`
// (script `test:coverage` / job CI `frontend-coverage`) ; `npm run test` reste sans.
const coverageFloorUrl = new URL('../coverage-floor.json', import.meta.url)
const frontendFloor = (
  JSON.parse(readFileSync(coverageFloorUrl, 'utf-8')) as { frontend: number | null }
).frontend
if (frontendFloor === null || frontendFloor === undefined) {
  throw new Error(
    'plancher frontend non mesuré : renseigne la clé `frontend` de coverage-floor.json (P4-166, B3)',
  )
}

export default mergeConfig(viteConfig, defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    exclude: ['tests/e2e/**', 'node_modules/**'],
    // P4-116 (AUD-FRT-25) — le plafond par test passe de 5 s (défaut Vitest) à 15 s.
    //
    // ⚠ **Ce n'est pas un pansement sur des tests lents, et la mesure le dit.** Le cas le plus
    // lourd — `PeriodStructure.test.tsx` › « déplacer un créneau réservé » — met **5,2 s sans
    // aucune charge concurrente** : il rend une grille hebdomadaire entière (7 jours × créneaux
    // de 15 min, des centaines de cellules) et chacun de ses quatre gestes la re-rend. C'est du
    // travail réel ; l'alléger reviendrait à ne plus tester le vrai écran. Sous contention (la
    // CI a 2 vCPU, et deux suites lancées en parallèle suffisent en local), quatre autres
    // tests d'écran franchissent le même plafond.
    //
    // ⚑ **Ce qu'un timeout garde, et ce qu'il ne garde pas.** Son rôle est de transformer un
    // test PENDU en échec — pas de mesurer une performance. À 5 s il ne faisait plus ça : il
    // rendait rouges des tests corrects selon la charge de la machine, c'est-à-dire qu'il
    // produisait du bruit là où on attend un signal. À 15 s il fait toujours son travail (un
    // test pendu échoue), sans dépendre de qui d'autre tourne.
    testTimeout: 15_000,
    // Et pour que la lenteur reste VISIBLE maintenant que le plafond a bougé : le seuil de
    // signalement passe de 300 ms (défaut — qui surligne à peu près tous les tests d'écran, donc
    // ne signale plus rien) à 3 s. Un test au-delà est colorié dans le rapport : c'est là qu'on
    // regarde si le scénario a dérivé, avant qu'il n'atteigne le plafond.
    slowTestThreshold: 3_000,
    // P4-166 (B2/B3/B4) — mesure de couverture. Ne s'active que sous `--coverage`
    // (script `test:coverage`, job CI `frontend-coverage`) ; `npm run test` reste sans.
    coverage: {
      provider: 'v8',
      include: ['src/**'],
      // B4 — exclusions DÉCLARÉES : tests, setup, stories, déclarations, bootstrap.
      exclude: [
        'src/**/*.test.ts*',
        'src/test/**',
        '**/*.stories.*',
        'src/**/*.d.ts',
        'src/main.tsx',
        'src/vite-env.d.ts',
      ],
      reporter: ['text', 'text-summary', 'json-summary', 'lcov'],
      reportsDirectory: 'coverage',
      // Cliquet (B3) : le plancher lu de coverage-floor.json rougit le job si la
      // couverture de lignes descend en dessous. Remonter le plancher = même PR.
      thresholds: { lines: frontendFloor },
    },
  },
}))

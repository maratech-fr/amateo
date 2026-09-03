import { readFileSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Garde du cliquet de couverture (P4-166, décision B3).
 *
 * Le plancher de couverture par zone vit dans un fichier UNIQUE versionné à la racine
 * du dépôt, `coverage-floor.json` — une seule maison, lue par la config Vitest
 * (`thresholds.lines`) et remontée dans la MÊME PR quand une mesure s'améliore. Ce test
 * garde la maison côté frontend : le fichier existe, est du JSON, et la clé `frontend`
 * est un entier 0-100 (jamais `null` : le frontend EST mesuré). Les clés `backend`/
 * `engine` sont gardées par leurs propres zones (engine : `test_coverage_floor.py`).
 *
 * Chemin relatif au fichier — `frontend/src/test/` → trois niveaux au-dessus = racine du
 * dépôt (et `/app/coverage-floor.json` dans l'image tooling, où le Dockerfile le copie).
 * On ancre sur `__dirname` (chemin de fichier nu, injecté par Vitest) plutôt que sur
 * `import.meta.url` : sous `--coverage`, ce dernier n'est pas toujours de schéma `file:`.
 */
const COVERAGE_FLOOR_PATH = path.resolve(__dirname, '../../../coverage-floor.json')

function readFloors(): Record<string, unknown> {
  return JSON.parse(readFileSync(COVERAGE_FLOOR_PATH, 'utf-8')) as Record<string, unknown>
}

describe('cliquet de couverture — coverage-floor.json (B3)', () => {
  it('le fichier existe et est du JSON', () => {
    expect(() => readFloors()).not.toThrow()
  })

  it('la clé `frontend` est un entier 0-100 (jamais null : le frontend est mesuré)', () => {
    const floors = readFloors()
    expect(floors).toHaveProperty('frontend')
    const frontendFloor = floors.frontend
    expect(Number.isInteger(frontendFloor)).toBe(true)
    expect(frontendFloor as number).toBeGreaterThanOrEqual(0)
    expect(frontendFloor as number).toBeLessThanOrEqual(100)
  })
})

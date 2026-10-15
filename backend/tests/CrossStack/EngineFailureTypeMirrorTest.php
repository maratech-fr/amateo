<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\MessageHandler\GenerateScheduleHandler;
use App\Service\ScheduleDiagnosticsRecorder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P5-14 PR-2 — LE GARDE qui rend opposable le miroir DÉCLARÉ `frontend/src/features/planning/lib/
 * serviceFailure.ts` : sa constante `SERVICE_FAILURE_TYPES` distingue « le service de calcul est
 * en panne » de « votre planning est infaisable ». Sans ce garde, la liste dérive le jour où
 * quelqu'un ajoute un type d'échec côté handler, et l'écran classe une PANNE en « infaisable »
 * (ou l'inverse) sans que rien ne rougisse.
 *
 * Source de vérité backend : les types que {@see GenerateScheduleHandler}
 * ENREGISTRE LUI-MÊME quand le rail de génération échoue AVANT ou PENDANT l'appel moteur (via
 * `recordSingle(...)` ou `failSchedule(...)` avec un TYPE littéral) — par opposition à ce que le
 * MOTEUR répond. On lit donc directement le source du handler et on le compare, DANS LES DEUX SENS,
 * à la liste du front :
 *   · un type ajouté au handler et absent du front → ROUGE ;
 *   · un type du front qui n'existe plus côté handler → ROUGE.
 *
 * ⚠ EXCLUSIONS (patron `FrontRederivationRegistryTest::EXEMPTIONS`) : le handler enregistre aussi
 * des types qui ne sont PAS des pannes de service. Chacun est exclu UN PAR UN avec sa raison, et le
 * test vérifie que l'exclusion reste JUSTIFIÉE (le type existe encore côté handler) — sinon on
 * garderait une porte ouverte qui ne protège plus rien.
 *   · `overlay_entry_missing` — échec DOMAINE (la période a été supprimée entre la mise en file et
 *     le run) : pas une indisponibilité du service, il route vers l'affichage de causes existant.
 *
 * ⚠ `engine_failed` n'apparaît PAS ici et c'est voulu : il est enregistré par
 * {@see ScheduleDiagnosticsRecorder} (le moteur a RÉPONDU « failed » = infaisable),
 * pas par le handler. Il ne peut donc pas entrer dans l'ensemble dérivé du handler ; s'il figurait
 * au front, la comparaison le rougirait comme « type du front absent du handler ».
 *
 * Régime : garde de FRONTIÈRE statique (lit le source front depuis PHP, comme
 * `FrontRederivationRegistryTest` / `TsUnionsMatchPhpEnumsTest`) — groupe `contract` / job
 * `engine-semantics` (required check de `main`), PAS un step `blocking-tests` : aucun invariant
 * runtime multi-tenant, rien à booter.
 */
#[Group('contract')]
final class EngineFailureTypeMirrorTest extends TestCase
{
    private const string HANDLER = __DIR__ . '/../../src/MessageHandler/GenerateScheduleHandler.php';

    private const string FRONT = __DIR__ . '/../../../frontend/src/features/planning/lib/serviceFailure.ts';

    /**
     * Types enregistrés par le handler mais qui ne sont PAS des pannes de service (un par un,
     * avec sa raison). Chacun DOIT rester présent côté handler (sinon exclusion périmée).
     *
     * @var array<string, string>
     */
    private const array EXCLUSIONS = [
        'overlay_entry_missing' => 'échec DOMAINE : la période a été supprimée entre la mise en file et le run — pas une panne du service, et « réessayez dans quelques minutes » ne la recréerait pas ; route vers l\'affichage de causes existant',
        'club_mismatch' => 'garde de défense en profondeur (message club_id ≠ schedule club_id, INJOIGNABLE sous RLS, GenerateScheduleHandler:109) : un mixup d\'identité déterministe, pas une indisponibilité transitoire — « réessayez » ne le corrigerait pas ; hors écran B',
        'engine_rejected' => 'refus de CONTRAT du moteur (HTTP 422 Pydantic : un champ du payload hors contrat) : déterministe, « réessayez dans quelques minutes » ne corrige rien — il route vers l\'affichage du champ en cause, pas vers l\'écran de panne de service (P4-340)',
    ];

    public function testFrontListMirrorsHandlerServiceFailureTypesBothWays(): void
    {
        $handlerTypes = $this->handlerRecordedTypes();
        $frontTypes = $this->frontServiceFailureTypes();

        // Garde anti-faux-vert : un parse muet (regex qui ne matche plus rien) ne doit pas rendre
        // le test trivialement vert. Les deux ensembles sont non vides.
        self::assertNotEmpty($handlerTypes, 'Aucun type d\'échec extrait du handler — le motif de lecture a dû casser.');
        self::assertNotEmpty($frontTypes, 'Aucun type extrait de SERVICE_FAILURE_TYPES — le motif de lecture front a dû casser.');

        $expected = array_values(array_filter($handlerTypes, static fn (string $type): bool => !isset(self::EXCLUSIONS[$type])));
        sort($expected);
        sort($frontTypes);

        self::assertSame($expected, $frontTypes, \sprintf(
            "La liste front SERVICE_FAILURE_TYPES a DÉRIVÉ de ce que le handler enregistre.\n"
            . "  Attendu (types de PANNE du handler, hors exclusions) : [%s]\n"
            . "  Front SERVICE_FAILURE_TYPES                          : [%s]\n\n"
            . 'Un type ajouté au handler doit entrer au front (sinon une panne est classée « infaisable »), '
            . 'et un type retiré du handler doit quitter le front. Si le nouveau type N\'EST PAS une panne de '
            . 'service, l\'exclure dans self::EXCLUSIONS avec sa raison.',
            implode(', ', $expected),
            implode(', ', $frontTypes),
        ));
    }

    public function testExclusionsStayJustified(): void
    {
        $handlerTypes = $this->handlerRecordedTypes();
        $stale = [];
        foreach (self::EXCLUSIONS as $type => $reason) {
            if ('' === trim($reason)) {
                $stale[] = \sprintf('%s : exclusion SANS raison', $type);

                continue;
            }
            if (!\in_array($type, $handlerTypes, true)) {
                $stale[] = \sprintf('%s : exclu mais le handler ne l\'enregistre plus — retirer l\'exclusion', $type);
            }
        }

        self::assertSame([], $stale, \sprintf(
            "Exclusions périmées :\n  - %s\n\n"
            . 'Une exclusion doit porter une raison ET rester justifiée (le type existe encore côté handler).',
            implode("\n  - ", $stale),
        ));
    }

    /**
     * Les types littéraux passés à `recordSingle($schedule, '<type>', …)` ou
     * `failSchedule($schedule, '<type>', …)` dans le handler. Le `recordSingle($schedule, $type, …)`
     * interne à `failSchedule` (argument VARIABLE, pas un littéral entre quotes) n'est pas capturé.
     *
     * @return list<string>
     */
    private function handlerRecordedTypes(): array
    {
        $source = (string) file_get_contents(self::HANDLER);
        preg_match_all('/->(?:recordSingle|failSchedule)\\(\\$schedule,\\s*\'([a-z_]+)\'/', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Les littéraux de la constante `SERVICE_FAILURE_TYPES = [...] as const` du front.
     *
     * @return list<string>
     */
    private function frontServiceFailureTypes(): array
    {
        $source = (string) file_get_contents(self::FRONT);
        if (1 !== preg_match('/SERVICE_FAILURE_TYPES\s*=\s*\[([^\]]*)\]/', $source, $block)) {
            return [];
        }
        preg_match_all('/"([a-z_]+)"/', $block[1], $matches);

        return array_values(array_unique($matches[1]));
    }
}

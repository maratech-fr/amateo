<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\TeamTagResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-88 — CÔTÉ BACKEND de la parité mécanique du groupement « nom de tag → équipes ».
 *
 * Le foyer FRONT `shared/lib/tagTeamIds.ts::buildTagTeamIds` (utilisé par
 * `applicableConstraints` ET `PeriodConstraints`) est le miroir client de la résolution
 * `TeamTagResolver`. `teamIdsByTagName` en est le foyer serveur PUR. Les MÊMES cas
 * (`tagTeamIds.parity.json`) les traversent : changer le groupement d'un seul côté rougit
 * ce côté-là. C'est la redérivation qui a ouvert P4-88 (un panneau annonçant une contrainte
 * CLUB+tag sur une équipe non taguée) — désormais opposable.
 *
 * Ce module figure au registre `FrontRederivationRegistryTest`.
 */
#[Group('contract')]
final class TagTeamIdsMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/shared/lib/tagTeamIds.parity.json';

    public function testBackendGroupingMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            /** @var array<string, list<string>> $expected */
            $expected = $case['expected'];
            foreach ($expected as $name => $ids) {
                sort($ids);
                $expected[$name] = $ids;
            }
            ksort($expected);

            $actual = TeamTagResolver::teamIdsByTagName($case['tags'], $case['assignments']);
            ksort($actual);

            self::assertSame($expected, $actual, \sprintf(
                "PARITÉ ROMPUE (« %s ») : le groupement backend diverge du foyer front.\n"
                . 'Front `buildTagTeamIds` et backend `TeamTagResolver::teamIdsByTagName` doivent coïncider sur tagTeamIds.parity.json.',
                (string) $case['name'],
            ));
        }
    }

    /**
     * P2-29 (lot tags PR 3) — la lecture des clés de ciblage : `targetTag` legacy ≡
     * `targetTags:[x]`, `excludeTags` en union soustraite. Front
     * `targetTagNames`/`excludeTagNames` ⇄ backend `TeamTagResolver::targetTagNames`/`excludeTagNames`.
     */
    public function testTagTargetingKeysMatchTheSharedCases(): void
    {
        foreach ($this->tagNameCases() as $case) {
            self::assertSame($case['expectedTargets'], TeamTagResolver::targetTagNames($case['config']), \sprintf(
                'PARITÉ ROMPUE (« %s ») : `targetTagNames` diverge du foyer front `targetTagNames`.',
                (string) $case['name'],
            ));
            self::assertSame($case['expectedExcludes'], TeamTagResolver::excludeTagNames($case['config']), \sprintf(
                'PARITÉ ROMPUE (« %s ») : `excludeTagNames` diverge du foyer front `excludeTagNames`.',
                (string) $case['name'],
            ));
        }
    }

    /**
     * P2-29 (lot tags PR 3) — l'algèbre « (∩ targetSets) − (∪ excludeSets) ». Front
     * `intersectMinusExclude` ⇄ backend `TeamTagResolver::intersectMinusExclude`. Le tri final
     * fait partie du contrat (il ordonne l'expansion par équipe du payload).
     */
    public function testIntersectMinusExcludeMatchesTheSharedCases(): void
    {
        foreach ($this->intersectCases() as $case) {
            $expected = $case['expected'];
            sort($expected);

            self::assertSame($expected, TeamTagResolver::intersectMinusExclude($case['targetSets'], $case['excludeSets']), \sprintf(
                "PARITÉ ROMPUE (« %s ») : « (∩ targetSets) − (∪ excludeSets) » diverge du foyer front.\n"
                . 'Front `intersectMinusExclude` et backend `TeamTagResolver::intersectMinusExclude` doivent coïncider sur tagTeamIds.parity.json.',
                (string) $case['name'],
            ));
        }
    }

    /** @return list<array{name: string, tags: list<array{id: string, name: string}>, assignments: list<array{teamId: string, tagId: string}>, expected: array<string, list<string>>}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{name: string, tags: list<array{id: string, name: string}>, assignments: list<array{teamId: string, tagId: string}>, expected: array<string, list<string>>}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'tagTeamIds.parity.json ne porte plus aucun cas.');

        return $list;
    }

    /** @return list<array{name: string, config: array<string, mixed>, expectedTargets: list<string>, expectedExcludes: list<string>}> */
    private function tagNameCases(): array
    {
        /** @var list<array{name: string, config: array<string, mixed>, expectedTargets: list<string>, expectedExcludes: list<string>}> $list */
        $list = $this->decoded()['tagNameCases'] ?? [];
        self::assertNotEmpty($list, 'tagTeamIds.parity.json ne porte plus aucun cas de lecture de clés (tagNameCases).');

        return $list;
    }

    /** @return list<array{name: string, targetSets: non-empty-list<list<string>>, excludeSets: list<list<string>>, expected: list<string>}> */
    private function intersectCases(): array
    {
        /** @var list<array{name: string, targetSets: non-empty-list<list<string>>, excludeSets: list<list<string>>, expected: list<string>}> $list */
        $list = $this->decoded()['intersectCases'] ?? [];
        self::assertNotEmpty($list, 'tagTeamIds.parity.json ne porte plus aucun cas d\'algèbre (intersectCases).');

        return $list;
    }

    /** @return array<string, mixed> */
    private function decoded(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

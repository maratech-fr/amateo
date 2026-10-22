<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ReservationGroupOccupancy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CÔTÉ BACKEND de la parité mécanique de « cette case est-elle BLOC-COMPLÈTE ? » (#12, retours
 * Rillieux ; décision fondateur 2026-10-13 « groupe mutualisé = une équipe »).
 *
 * `wizard/lib/blockOccupancy.ts::completeBlockOnCase` (front — décide l'affichage « Complet · Groupe … »
 * et empêche le faux « 2/1 » au wizard) et la règle (b) réciproque de
 * `ReservationGroupOccupancy` (backend — `reservedSetMatchesABlock`, un bloc réservé occupe la case en
 * entier, aucune autre équipe ne s'y ajoute) sont deux implémentations INÉVITABLES de la MÊME règle (le
 * wizard doit refléter l'occupation sans aller-retour réseau à chaque rendu de grille). Ici, les MÊMES
 * cas (`blockOccupancy.parity.json`, dans l'arbre front) traversent les DEUX implémentations : changer
 * la règle d'un seul côté rougit ce côté-là.
 *
 * Portée VOLONTAIREMENT bornée au cas RÉCIPROQUE (ensemble réservé == membres d'un bloc) : c'est la
 * seule dérivation dont le wizard a besoin pour « Complet · Groupe … ». Le plafond PAR ÉQUIPE (règle d)
 * reste au serveur ; l'élection d'un bloc MAXIMAL sur des blocs IMBRIQUÉS/co-présents (que le wizard ne
 * peut pas produire — règle (a) exige une case vide) est gardée ailleurs, contre le VRAI moteur, par
 * {@see NestedBlockOccupantFoldParityTest}.
 *
 * Ce module figure au registre {@see FrontRederivationRegistryTest} (miroir déclaré + parité).
 *
 * Régime : garde de FRONTIÈRE statique PURE (lit un fichier de cas du front depuis PHP, comme
 * {@see HolidayWorkweekMirrorParityTest}) — groupe `contract` / job `engine-semantics`, PAS un step
 * `blocking-tests` (aucun invariant runtime multi-tenant ; aucun appel moteur).
 */
#[Group('contract')]
final class ReservationBlockOccupancyMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/wizard/lib/blockOccupancy.parity.json';

    public function testBackendMatchingBlockMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            $label = (string) $case['label'];
            $reservedSet = array_fill_keys(array_map(strval(...), $case['reservedTeamIds']), true);

            $blocks = [];
            foreach ($case['blocks'] as $block) {
                $blocks[(string) $block['id']] = array_fill_keys(array_map(strval(...), $block['teamIds']), true);
            }

            self::assertSame(
                $case['expectedCompleteBlockId'],
                ReservationGroupOccupancy::matchingBlock($reservedSet, $blocks),
                \sprintf(
                    "PARITÉ ROMPUE (« %s ») : le backend ne rend pas le verdict partagé.\n"
                    . "Front `completeBlockOnCase` et backend `ReservationGroupOccupancy::matchingBlock` doivent\n"
                    . 'coïncider sur blockOccupancy.parity.json — sinon le wizard affiche « 2/1 » là où le serveur voit UN groupe (ou l\'inverse).',
                    $label,
                ),
            );
        }
    }

    /** @return list<array{label: string, reservedTeamIds: list<string>, blocks: list<array{id: string, teamIds: list<string>}>, expectedCompleteBlockId: string|null}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $out = [];
        /** @var list<array<string, mixed>> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'blockOccupancy.parity.json ne porte plus aucun cas.');
        foreach ($list as $case) {
            /* @var array{name: string, reservedTeamIds: list<string>, blocks: list<array{id: string, teamIds: list<string>}>, expectedCompleteBlockId: string|null} $case */
            $out[] = [
                'label' => (string) $case['name'],
                'reservedTeamIds' => $case['reservedTeamIds'],
                'blocks' => $case['blocks'],
                'expectedCompleteBlockId' => $case['expectedCompleteBlockId'],
            ];
        }

        return $out;
    }
}

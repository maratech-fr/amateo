<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use PHPUnit\Framework\Assert;

/**
 * Ce que le lot des déclarations communes déplace, posé sur le décor des demandes au moteur : les
 * groupes d'équipes (étiquettes dérivées de la catégorie, du sexe et du niveau), les règles de club qui
 * les ciblent sous leurs trois formes, et le jour de match enregistré d'une équipe sans habitude.
 * S'emploie AVEC `EngineRequestDecorTrait` (il en lit les identifiants et `erConstraint`).
 *
 * Les équipes passent par l'ORM, comme une saisie : c'est l'écriture réelle qui pose leurs étiquettes
 * aujourd'hui. Groupes obtenus (catégories du catalogue FFBB, `Basketball/CategoryCatalog`) :
 *   - U13 F, départemental : JEUNE, U13, FEMININE, DEPARTEMENTAL, COMPETITION ;
 *   - U15 M, régional : JEUNE, U15, MASCULINE, REGIONAL, COMPETITION ;
 *   - SM1, senior masculin régional : ADULTE, SENIOR, MASCULINE, REGIONAL, COMPETITION ; jour de
 *     match enregistré 5 (samedi, compté de 0), sans habitude de match.
 *
 * Consommé par `Integration/EngineRequestReferenceTest` (références 7 et 8) et
 * `Integration/Command/EngineRequestsRecordCommandTest`.
 */
trait EngineRequestGroupRulesDecorTrait
{
    private const string ER_CATEGORY_U13 = 'ca7e0000-0000-4000-8000-000000000011';
    private const string ER_CATEGORY_U15 = 'ca7e0000-0000-4000-8000-000000000012';
    private const string ER_CATEGORY_SENIOR = 'ca7e0000-0000-4000-8000-000000000013';

    /** Règle de saison `targetTags` à deux noms : JEUNE ∩ MASCULINE = U15. */
    private const string ER_RULE_TWO_TAGS = 'c0570000-0000-4000-8000-000000000007';
    /** Règle de saison `excludeTags` seule : toutes les équipes sauf JEUNE = SM1. */
    private const string ER_RULE_EXCLUDE = 'c0570000-0000-4000-8000-000000000008';
    /** Règle de saison `targetTag`, ancienne forme : U13. */
    private const string ER_RULE_LEGACY_TAG = 'c0570000-0000-4000-8000-000000000009';
    /** Règle de groupe datée de la fermeture : JEUNE ∩ FEMININE = U13. */
    private const string ER_RULE_PERIOD_GROUP = 'c0570000-0000-4000-8000-000000000010';

    /** Catégories du catalogue, puis chaque équipe y est rangée avec son sexe et son niveau. */
    private function seedEngineRequestTeamGroups(): void
    {
        $em = $this->erEm();
        $sportId = $em->getRepository(SportCategory::class)->find(self::ER_CATEGORY)?->getSportId();
        Assert::assertIsString($sportId, 'la catégorie du décor de base doit exister');

        foreach ([
            [self::ER_CATEGORY_U13, 'U13', 12, 13, 60],
            [self::ER_CATEGORY_U15, 'U15', 14, 15, 70],
            [self::ER_CATEGORY_SENIOR, 'Senior', 22, 99, 80],
        ] as [$id, $name, $ageMin, $ageMax, $matchMinutes]) {
            $em->persist((new SportCategory)->setId($id)->setClubId(self::ER_CLUB)->setSportId($sportId)
                ->setName($name)->setAgeMin($ageMin)->setAgeMax($ageMax)->setMatchMinutes($matchMinutes)->setWarmupMinutes(30));
        }
        $em->flush();

        foreach ([
            [self::ER_TEAM_U13, self::ER_CATEGORY_U13, Gender::F, TeamLevel::DEPARTEMENTAL, null],
            [self::ER_TEAM_U15, self::ER_CATEGORY_U15, Gender::M, TeamLevel::REGIONAL, null],
            [self::ER_TEAM_SM1, self::ER_CATEGORY_SENIOR, Gender::M, TeamLevel::REGIONAL, 5],
        ] as [$teamId, $categoryId, $gender, $level, $matchDay]) {
            $team = $em->getRepository(Team::class)->find($teamId);
            Assert::assertInstanceOf(Team::class, $team);
            $team->setSportCategoryId($categoryId)->setGender($gender)->setLevel($level)->setMatchDay($matchDay);
        }
        $em->flush();
    }

    /** Les trois règles de club de la saison qui ciblent un groupe, une par forme de ciblage. */
    private function seedEngineRequestSeasonGroupRules(): void
    {
        $this->erConstraint(7, ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['targetTags' => ['JEUNE', 'MASCULINE'], 'maxStartTime' => '19:30']);
        $this->erConstraint(8, ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['excludeTags' => ['JEUNE'], 'minStartTime' => '19:00']);
        $this->erConstraint(9, ConstraintScope::CLUB, null, ConstraintFamily::DAY, ConstraintRuleType::PREFERRED, ['targetTag' => 'U13', 'preferredDays' => [1, 4]]);
        $this->erEm()->flush();
    }

    /** Une règle de groupe datée, attachée à la fermeture du gymnase Sud (`seedEngineRequestPeriod`). */
    private function seedEngineRequestPeriodGroupRule(): void
    {
        $this->erConstraint(10, ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['targetTags' => ['JEUNE', 'FEMININE'], 'minStartTime' => '18:00'], self::ER_ENTRY);
        $this->erEm()->flush();
    }

    /**
     * Les lignes de contrainte de la demande qui viennent d'une règle de groupe, `id règle => [équipes]`.
     * Témoin positif : une référence figée sur une demande où la règle n'a touché personne (groupe
     * introuvable = règle sautée en silence) ne prouverait rien.
     *
     * @param array<string, mixed> $body
     * @param list<string>         $ruleIds
     *
     * @return array<string, list<string>>
     */
    private function groupRuleRows(array $body, array $ruleIds): array
    {
        $rows = array_fill_keys($ruleIds, []);
        Assert::assertIsArray($body['constraints'] ?? null, 'la demande porte ses contraintes');
        foreach ($body['constraints'] as $row) {
            Assert::assertIsArray($row);
            $id = (string) ($row['id'] ?? '');
            foreach ($ruleIds as $ruleId) {
                if (str_starts_with($id, $ruleId . ':')) {
                    $rows[$ruleId][] = substr($id, \strlen($ruleId) + 1);
                }
            }
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\MatchConstraint;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Service\MatchPlacementPayloadBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes *constraint semantics* + *backend↔engine contract* (§7.1).
 *
 * P4-272 ③ : le bloc top-level `clubRules` émis au solveur (`/place-matches`) est
 * EXACTEMENT la copie des `MatchConstraint` scope CLUB stockées (club+saison) — ni
 * plus, ni moins, et JAMAIS celles d'un autre club. C'est la promesse « une règle
 * saisie est honorée par le moteur » à la source : si le payload ne porte pas la
 * règle, aucune sémantique aval ne peut la faire respecter.
 *
 * Falsifié dans les DEUX sens :
 *  - une règle STOCKÉE voyage telle quelle (un builder qui l'ometrait échouerait) ;
 *  - le payload N'INVENTE RIEN : il émet EXACTEMENT la règle stockée (bornes,
 *    jours, type), et une règle de scope non-CLUB (④/⑤) NE FUIT PAS ;
 *  - un AUTRE club ne fuit pas (RLS : le GUC scope le club courant).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubRulePayloadParityTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private MatchPlacementPayloadBuilder $builder;

    /**
     * Sens 1 + « n'invente rien » — la règle CLUB stockée est EXACTEMENT ce que le
     * payload émet (type, jours, bornes, une borne nulle restant nulle).
     */
    public function testStoredClubRuleIsEmittedVerbatim(): void
    {
        [$club, $season] = $this->seed();
        // « Pas après 21h » le samedi : HARD, jours [6], min nul, max 21:00.
        $this->clubRule($club, $season, ConstraintRuleType::HARD, [6], null, '21:00');
        $this->em->flush();

        $rules = $this->clubRulesOf($club, $season->getId());

        self::assertSame(
            [['ruleType' => 'HARD', 'daysOfWeek' => [6], 'kickoffMin' => null, 'kickoffMax' => '21:00']],
            $rules,
            'le payload émet EXACTEMENT la règle CLUB stockée',
        );
    }

    /**
     * Sens 2 — une règle de scope non-CLUB (réservée ④/⑤) NE FUIT PAS dans le bloc
     * `clubRules` : seule la règle CLUB voyage.
     */
    public function testNonClubScopedRuleDoesNotLeakIntoTheBlock(): void
    {
        [$club, $season] = $this->seed();
        $this->clubRule($club, $season, ConstraintRuleType::PREFERRED, [3], '09:00', '18:00');
        // Une règle scope TEAM ne doit PAS atteindre le bloc club-level.
        $team = new MatchConstraint;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setScope(ConstraintScope::TEAM);
        $team->setScopeTargetId($this->uuid());
        $team->setRuleType(ConstraintRuleType::HARD);
        $team->setDaysOfWeek([6]);
        $team->setKickoffMax(new DateTimeImmutable('20:00'));
        $this->em->persist($team);
        $this->em->flush();

        $rules = $this->clubRulesOf($club, $season->getId());

        self::assertSame(
            [['ruleType' => 'PREFERRED', 'daysOfWeek' => [3], 'kickoffMin' => '09:00', 'kickoffMax' => '18:00']],
            $rules,
            'seule la règle scope CLUB voyage — le scope TEAM ne fuit pas',
        );
    }

    /**
     * RLS — un autre club ne fuit pas : la règle du club B est invisible dans le
     * payload du club A (GUC posé sur A).
     */
    public function testAnotherClubRuleDoesNotLeak(): void
    {
        [$clubA, $seasonA] = $this->seed();
        [$clubB, $seasonB] = $this->seed();
        $this->clubRule($clubB, $seasonB, ConstraintRuleType::HARD, [1], null, '19:00');
        $this->em->flush();

        // GUC sur A : le payload de A ne doit porter AUCUNE règle (B est invisible).
        $this->scopeGucToClub($clubA->getId());
        $rules = $this->clubRulesOf($clubA, $seasonA->getId());

        self::assertSame([], $rules, 'la règle du club B ne fuit pas dans le payload du club A');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
    }

    /**
     * @return list<array{ruleType: string, daysOfWeek: list<int>, kickoffMin: string|null, kickoffMax: string|null}>
     */
    private function clubRulesOf(Club $club, string $seasonId): array
    {
        $result = $this->builder->build($club, $seasonId);
        /** @var list<array{ruleType: string, daysOfWeek: list<int>, kickoffMin: string|null, kickoffMax: string|null}> $rules */
        $rules = $result['payload']['clubRules'];

        return $rules;
    }

    /**
     * @param list<int> $days
     */
    private function clubRule(Club $club, Season $season, ConstraintRuleType $type, array $days, ?string $min, ?string $max): MatchConstraint
    {
        $rule = new MatchConstraint;
        $rule->setClubId($club->getId());
        $rule->setSeasonId($season->getId());
        $rule->setScope(ConstraintScope::CLUB);
        $rule->setRuleType($type);
        $rule->setDaysOfWeek($days);
        $rule->setKickoffMin(null === $min ? null : new DateTimeImmutable($min));
        $rule->setKickoffMax(null === $max ? null : new DateTimeImmutable($max));
        $this->em->persist($rule);

        return $rule;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club Rule Parity Club');
        $club->setSlug('club-rule-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('CRP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('club-rule-parity-' . $uid . '@test.com');
        $user->setFirstName('C');
        $user->setLastName('R');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\PlaceTravelTime;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTravelRuleSetting;
use App\Enum\SeasonStatus;
use App\Enum\VenueTravelRuleIntensity;
use App\Enum\VenueTravelTimeSource;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * NR d'axe *constraint semantics* (lot 5, PR 2, décision de l'orchestrateur du 2026-11-24 sur le §3.1, E1) : la RÈGLE de
 * trajet (`VenueTravelRuleSetting` : intensité, tolérance, temps par défaut) est FROIDE, le TEMPS de trajet est LIBRE.
 * L'empreinte du plan — celle que l'écran compare au `snapshotHash` d'une version pour dire « périmé »
 * (`SchedulePlanProvisioner::structureHashOfPlan`) — suit donc la règle, matrice saisie ou non, et ignore la matrice,
 * y compris le PREMIER temps saisi, qui allume aujourd'hui le bloc `implicitRules.travelTime` de la demande.
 *
 * Le piège : la demande n'émet la règle que lorsqu'une matrice existe ; une empreinte lue sur ce bloc périmerait le
 * planning au premier temps saisi (libre), et une empreinte qui retire ce bloc ne verrait plus la règle changer
 * (froide). Angle jumeau, cité : `Integration/EngineRequestKeySourcesTest::testATravelTimeIsFree` (même vérité, clé par
 * clé, sur la demande figée) ; par l'écran : `la-boucle-de-travail-du-gestionnaire.feature` (« je saisis puis corrige
 * le trajet »).
 */
#[Group('phase1')]
#[Group('integration')]
final class TravelRuleFingerprintTest extends KernelTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private string $clubId;

    private string $seasonId;

    private string $planId;

    /** @var list<Venue> */
    private array $venues = [];

    /** Changer la règle de trajet (obligatoire → souhaitée, ou sa tolérance) change l'empreinte, matrice saisie ou non. */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testChangingTheTravelRuleChangesTheFingerprint(bool $withMatrix): void
    {
        $rule = (new VenueTravelRuleSetting)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setIntensity(VenueTravelRuleIntensity::MANDATORY);
        $this->em->persist($rule);
        if ($withMatrix) {
            $this->addTravelTime(15);
        }
        $this->em->flush();
        $mandatory = $this->fingerprint();

        $rule->setIntensity(VenueTravelRuleIntensity::PREFERRED);
        $this->em->flush();
        $preferred = $this->fingerprint();
        self::assertNotSame($mandatory, $preferred, 'la règle de trajet passée d\'obligatoire à souhaitée périme le planning : elle est froide');

        $rule = $this->em->find(VenueTravelRuleSetting::class, $rule->getId());
        self::assertInstanceOf(VenueTravelRuleSetting::class, $rule);
        $rule->setToleranceMinutes($rule->getToleranceMinutes() + 10);
        $this->em->flush();
        self::assertNotSame($preferred, $this->fingerprint(), 'la tolérance de la règle de trajet périme aussi le planning');
    }

    /** Saisir un temps de trajet, le premier compris (matrice vide au départ), puis le corriger, ne change pas l'empreinte. */
    public function testEnteringTheFirstTravelTimeLeavesTheFingerprint(): void
    {
        $this->em->persist((new VenueTravelRuleSetting)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setIntensity(VenueTravelRuleIntensity::MANDATORY));
        $this->em->flush();
        $before = $this->fingerprint();
        self::assertSame([], $this->request()['venueTravelTimes'] ?? [], 'témoin : la matrice est vide au départ');

        $travel = $this->addTravelTime(12);
        $this->em->flush();
        self::assertNotSame([], $this->request()['venueTravelTimes'] ?? [], 'témoin : le premier temps saisi part bien au moteur');
        self::assertSame($before, $this->fingerprint(), 'saisir le premier temps de trajet ne périme rien : il est libre');

        $travel = $this->em->find(PlaceTravelTime::class, $travel->getId());
        self::assertInstanceOf(PlaceTravelTime::class, $travel);
        $travel->setDrivingMinutes(25);
        $this->em->flush();
        self::assertSame($before, $this->fingerprint(), 'le corriger ne périme rien non plus');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $club = (new Club)->setName('Trajet ' . $uid)->setSlug('trajet-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->clubId = $club->getId();
        $this->scopeGucToClub($this->clubId);
        $season = (new Season)->setClubId($this->clubId)->setName('2025-2026')->setStartDate(new DateTimeImmutable('2025-09-01'))
            ->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        $this->seasonId = $season->getId();
        $this->venues = [$this->newVenue($this->clubId, $this->seasonId, 'Gymnase Nord'), $this->newVenue($this->clubId, $this->seasonId, 'Gymnase Sud')];
        $this->em->persist((new Team)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setName('U15')
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(2));
        $this->em->flush();
        $this->planId = SeasonPlanOf::id(self::getContainer(), $this->seasonId);
    }

    protected function tearDown(): void
    {
        if (isset($this->clubId)) {
            $this->cachePool()->invalidateTags([ScheduleConstraintBuilder::cacheTag($this->clubId)]);
        }
        parent::tearDown();
    }

    /** Le temps de trajet entre les deux gymnases du décor, saisi à la main. */
    private function addTravelTime(int $minutes): PlaceTravelTime
    {
        $travel = (new PlaceTravelTime)->setClubId($this->clubId)
            ->setPlaces($this->venues[0]->getPlaceId(), $this->venues[1]->getPlaceId())
            ->setDrivingMinutes($minutes)->setDrivingSource(VenueTravelTimeSource::MANUAL);
        $this->em->persist($travel);

        return $travel;
    }

    /** L'empreinte du plan telle que l'écran la compare au `snapshotHash`, cache de la demande purgé. */
    private function fingerprint(): string
    {
        $this->em->clear();
        $this->cachePool()->invalidateTags([ScheduleConstraintBuilder::cacheTag($this->clubId)]);
        $hash = self::getContainer()->get(SchedulePlanProvisioner::class)->structureHashOfPlan($this->planId);
        self::assertIsString($hash, 'le plan a une empreinte');

        return $hash;
    }

    /** @return array<string, mixed> la demande du plan, cache purgé */
    private function request(): array
    {
        $this->em->clear();
        $this->cachePool()->invalidateTags([ScheduleConstraintBuilder::cacheTag($this->clubId)]);

        return self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForPlan($this->planId);
    }

    private function cachePool(): TagAwareAdapterInterface
    {
        $pool = self::getContainer()->get('cache.schedule');
        self::assertInstanceOf(TagAwareAdapterInterface::class, $pool);

        return $pool;
    }
}

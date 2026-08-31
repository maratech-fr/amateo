<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Enum\SeasonStatus;
use App\Service\OverlayManager;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P2-51 — purge des réglages ancrés au plan de période : supprimer une période emporte SES blocs
 * de mutualisation (parent + membres, colonne `schedule_plan_id` dénormalisée), et RIEN du socle.
 * Extension du chemin `OverlayManager::purgePlanAnchoredSettings` au bloc (patron du groupe).
 */
#[Group('integration')]
final class OverlayManagerBlockPurgeTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testDeletingAPeriodPlanTakesItsBlocksAndSparesTheBase(): void
    {
        [$club, $season] = $this->seed();
        $planId = $this->uuid();

        $periodBlock = $this->block($club, $season, $planId);
        $baseBlock = $this->block($club, $season, null);
        $this->em->flush();

        self::getContainer()->get(OverlayManager::class)->purgePlanAnchoredSettings($planId);
        $this->em->flush();
        $this->em->clear();

        // Le bloc de période part ENTIER — parent et membres.
        self::assertNull($this->em->getRepository(SharedTrainingBlock::class)->find($periodBlock), 'le bloc de période est emporté avec son plan');
        self::assertCount(0, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $periodBlock]), 'ses membres partent avec lui');

        // Le bloc SOCLE (ancre NULL) survit : NULL = « partagé par la saison », pas orphelin.
        self::assertNotNull($this->em->getRepository(SharedTrainingBlock::class)->find($baseBlock), 'le bloc socle survit à la purge de la période');
        self::assertCount(2, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $baseBlock]), 'ses membres restent');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function block(Club $club, Season $season, ?string $planId): string
    {
        $block = (new SharedTrainingBlock)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)->setCommonSessions(1);
        $this->em->persist($block);
        foreach ([$this->uuid(), $this->uuid()] as $teamId) {
            $this->em->persist((new SharedTrainingBlockTeam)->setClubId($club->getId())->setSeasonId($season->getId())
                ->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($teamId));
        }

        return $block->getId();
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('STB purge')->setSlug('stbp-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true)->setFfbbClubCode('SBP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

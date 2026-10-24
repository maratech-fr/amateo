<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Export\ScheduleExportDataProvider;
use App\Service\BrandAssets;
use App\Service\PdfGenerator;
use App\Service\ProductIdentity;
use App\Service\SchedulePlanProvisioner;
use App\Storage\LogoStorage;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Chantier E — SYNCHRO du nom de fichier, côté BACK. Le worker écrit le PDF sous
 * `schedule-<id>-<scope>-coaches.pdf` QUAND le gestionnaire a coché « Afficher le nom des
 * coachs », et `schedule-<id>-<scope>.pdf` sinon. Ce suffixe `-coaches` n'est pas cosmétique :
 * la page qui attend l'export le COMPARE (`frontend/.../queries.ts` `scopeToken`,
 * `url.endsWith(scopeToken)`) pour ne pas saisir la version SANS coachs rendue pour une autre
 * demande du même planning. Un suffixe qui dériverait d'un côté ferait expirer le polling de
 * l'autre — d'où ce garde, miroir exact de `frontend/.../lib/exportScopeToken.test.ts` (le même
 * jeton côté front, falsifié des deux sens là-bas).
 *
 * On exerce le VRAI chemin : `generate()` rend l'URL publique qui se termine par le nom du
 * fichier. Le worker Puppeteer est doublé (MockHttpClient : « success »), le reste est réel.
 */
#[Group('integration')]
final class PdfExportFilenameTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testFilenameCarriesTheCoachSuffixOnlyWhenOptedIn(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->makeSchedule($club, $season);
        $generator = $this->generator();

        $optedIn = $generator->generate($schedule, null, true)['pdf'];
        $optedOut = $generator->generate($schedule, null, false)['pdf'];

        self::assertStringEndsWith('-all-coaches.pdf', $optedIn, 'coché → le nom de fichier porte -coaches, que le polling front attend (scopeToken)');
        self::assertStringEndsWith('-all.pdf', $optedOut, 'décoché → le suffixe de portée seul');
        self::assertStringNotContainsString('-coaches', $optedOut, 'décoché → JAMAIS -coaches (sinon le run décoché saisirait la version cochée, rendue une seconde plus tôt)');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function generator(): PdfGenerator
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode(['success' => true])));
        $container = self::getContainer();

        return new PdfGenerator(
            $this->em,
            $http,
            $container->get(ScheduleExportDataProvider::class),
            $container->get(SchedulePlanProvisioner::class),
            $container->get(LogoStorage::class),
            $container->get(BrandAssets::class),
            $container->get(ProductIdentity::class),
        );
    }

    private function makeSchedule(Club $club, Season $season): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Socle');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('EXP Filename Club');
        $club->setSlug('exp-fn-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('EXP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

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

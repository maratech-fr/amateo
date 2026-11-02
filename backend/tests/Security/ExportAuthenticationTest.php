<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\PdfGenerator;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Chemin d'abus « export anonyme » (axe *auth & memberships*, charte des parcours §5) : les TROIS
 * routes d'export — `POST /api/schedules/{id}/export-pdf` (rail async), `/export-xlsx` (rail sync)
 * et `POST /api/fixtures/export-pdf` (les matchs pour les parents, rail sync) — sont derrière le
 * firewall JWT. Demander l'export SANS session est refusé 401, ET rien n'est produit : le planning
 * visé n'est pas mis en file (son `pdfExportStatus` ne passe JAMAIS à « pending »), aucun fichier
 * de matchs n'apparaît dans le répertoire de rendus.
 *
 * Témoin de falsification : les 401. Le firewall `access_control` catch-all `^/api →
 * IS_AUTHENTICATED_FULLY` (`config/packages/security.yaml`) court-circuite les deux contrôleurs
 * AVANT leur corps ; ouvrir l'une des routes en PUBLIC_ACCESS fait tomber le 401 → rouge.
 * L'assertion d'effet (« pdfExportStatus inchangé ») est une ceinture : elle lit l'ÉTAT APRÈS
 * la requête (jamais le seul code retour, `verify-effect-not-call-site`) et prouve qu'aucun
 * `ExportPdfMessage` n'a pu être enfilé — le contrôleur pose `pending` + flush JUSTE avant le
 * dispatch, donc un statut inchangé atteste que le corps n'a pas tourné. Cette ceinture est,
 * elle, doublée par la RLS (sans JWT, aucun GUC → le `find` du contrôleur ne verrait rien de
 * toute façon) : seul le 401 falsifie ce test, comme documenté ici.
 *
 * Filet CITÉ de `backend/features/l-export-du-planning.feature:13` (« L'export est refusé sans
 * session »), écrit AVANT que la refonte Behat par parcours ne replie ce scénario. Angle voisin,
 * cross-référencé : `Integration/Controller/ExportScheduleTest::testExportXlsxRequiresAuth` ne
 * couvre QUE le XLSX (pas le PDF) et ne vérifie PAS l'effet ; export = domaine sensible (charte
 * §1), les deux angles coexistent.
 */
#[Group('phase1')]
#[Group('security')]
final class ExportAuthenticationTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * Les DEUX routes d'export refusent 401 sans session, et le planning visé n'est pas enfilé.
     */
    public function testBothExportRoutesAreRefusedWithoutSessionAndEnqueueNothing(): void
    {
        [$club, $schedule] = $this->seedScheduleOf('exp');
        $scheduleId = $schedule->getId();
        self::assertNull($schedule->getPdfExportStatus(), 'pré-condition : le planning seedé n\'est pas en file');

        foreach (['export-pdf', 'export-xlsx'] as $route) {
            // Requête ANONYME : aucun cookie JWT ni Bearer n'est posé.
            $this->client->request('POST', '/api/schedules/' . $scheduleId . '/' . $route, [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], '{}');
            self::assertResponseStatusCodeSame(401, $route . ' : l\'export sans session est refusé par le firewall JWT');
        }

        // Effet : le planning n'a PAS été mis en file (le corps du contrôleur n'a pas tourné).
        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $reloaded = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $reloaded);
        self::assertNull(
            $reloaded->getPdfExportStatus(),
            'rien enfilé : une requête refusée ne doit jamais poser « pending » sur le planning',
        );
    }

    /**
     * P4-357 — le rendu PDF ne se télécharge plus par une URL statique publique mais par la route
     * AUTHENTIFIÉE `GET /api/schedules/{id}/export-pdf/{filename}` : sans session, le firewall JWT
     * refuse 401 et RIEN n'est servi (le corps n'est pas un PDF). Témoin : le 401 — ouvrir la
     * route en PUBLIC_ACCESS le ferait tomber et exposerait le fichier à un anonyme. Le refus
     * cross-tenant d'un club AUTHENTIFIÉ sur la même route (404 byte-identique) + la robustesse du
     * nom de fichier sont gardés par `Security/ExportPdfDownloadIsolationTest` (angle voisin,
     * export = domaine sensible, charte §1).
     */
    public function testTheRenderedPdfIsNotServedWithoutASession(): void
    {
        [, $schedule] = $this->seedScheduleOf('dl');
        $scheduleId = $schedule->getId();

        // Requête ANONYME sur l'URL de téléchargement : aucun cookie JWT ni Bearer.
        $this->client->request('GET', \sprintf('/api/schedules/%s/export-pdf/schedule-%s-all.pdf', $scheduleId, $scheduleId));

        self::assertResponseStatusCodeSame(401, 'le téléchargement du rendu sans session est refusé par le firewall JWT');
        self::assertStringStartsNotWith(
            '%PDF',
            (string) $this->client->getResponse()->getContent(),
            'rien n’est servi : une requête refusée ne doit jamais rendre les octets d’un PDF',
        );
    }

    /**
     * La liste des matchs d'un club (dates, lieux) ne sort pas sans session : 401, et aucun fichier
     * `matchs-*` n'est écrit. Témoin : le 401 — ouvrir la route en PUBLIC_ACCESS le fait tomber.
     */
    public function testTheMatchesExportIsRefusedWithoutSessionAndWritesNothing(): void
    {
        $before = glob(PdfGenerator::outputDir() . '/matchs-*') ?: [];

        // Requête ANONYME : aucun cookie JWT ni Bearer n'est posé.
        $this->client->request('POST', '/api/fixtures/export-pdf', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{"from":"2026-11-09","to":"2026-11-15","teamId":null}');

        self::assertResponseStatusCodeSame(401, 'l\'export des matchs sans session est refusé par le firewall JWT');
        self::assertStringStartsNotWith('%PDF', (string) $this->client->getResponse()->getContent());
        self::assertSame($before, glob(PdfGenerator::outputDir() . '/matchs-*') ?: [], 'une requête refusée n\'écrit aucun fichier');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Un club + saison (plan SEASON) + une version COMPLETED liée à son plan, comme la prod au
     * POST — seedés sous le GUC du club (RLS WITH CHECK).
     *
     * @return array{0: Club, 1: Schedule}
     */
    private function seedScheduleOf(string $prefix): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('EXP-AUTH ' . $prefix);
        $club->setSlug('exp-auth-' . $prefix . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('EXA' . strtoupper(substr(md5($prefix . $uid), 0, 8)));
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

        self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $this->em->flush();

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Export');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return [$club, $schedule];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlock;
use App\Service\EngineClient;
use App\Service\RequestIdContext;
use App\Tests\Integration\Api\RecapRefusesASecondSessionSameDayTest;
use App\Tests\OneSessionPerDayDecorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * NR (axes planning lifecycle et constraint semantics) : deux séances le même jour pour une équipe, chevauchement ou non, sont
 * impossibles. Mutualiser (fiche, glisser-déposer), déplacer, poser, déplacer un groupe et échanger le refusent avant le moteur,
 * en saison comme en période, rien n'est écrit. Un groupe mutualisé compte comme une équipe : chacun de ses membres porte la règle.
 * Les séances engagées dans le geste ne comptent pas : rester sur son jour reste légal.
 *
 * Jumeau VOULU (point sensible manipulation de planning) : {@see RecapRefusesASecondSessionSameDayTest} (les réservations, que le
 * geste laisse passer et que le Récap arrête).
 *
 * @phpstan-import-type Decor from OneSessionPerDayDecorTrait
 */
#[Group('phase1')]
#[Group('integration')]
final class OneSessionPerDayGesturesTest extends WebTestCase
{
    use OneSessionPerDayDecorTrait;

    private const string ACCEPT = '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private int $engineCalls = 0;

    /** @return iterable<string, array{bool}> */
    public static function layers(): iterable
    {
        yield 'saison' => [false];
        yield 'période' => [true];
    }

    /** Déplacer sa séance du jeudi vers le mardi où elle s'entraîne déjà : refusé, la séance reste au jeudi. */
    #[DataProvider('layers')]
    public function testMovingOntoADayAlreadyTrainedIsRefused(bool $period): void
    {
        $ctx = $this->decor($period);
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $thursday = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->post($ctx, '/api/schedule-slots/' . $thursday->getId() . '/move', ['dayOfWeek' => self::TUESDAY, 'venueId' => $ctx['jdr'], 'startTime' => '20:30']);

        $this->assertRefused('U15', 'mardi');
        $this->assertStillOnDay($ctx, $thursday->getId(), self::THURSDAY);
    }

    /** Une séance de groupe vaut une séance : l'équipe du groupe, déjà là le mardi, n'y reçoit pas sa séance seule. */
    public function testASharedSessionCountsAsTheTeamsSessionOfTheDay(): void
    {
        $ctx = $this->decor(false);
        $block = $this->group($ctx, [$ctx['u15'], $ctx['u18']]);
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00', blockId: $block);
        $this->session($ctx, $ctx['u18'], $ctx['armand'], self::TUESDAY, '18:00', blockId: $block);
        $solo = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->post($ctx, '/api/schedule-slots/' . $solo->getId() . '/move', ['dayOfWeek' => self::TUESDAY, 'venueId' => $ctx['jdr'], 'startTime' => '20:30']);

        $this->assertRefused('U15', 'mardi');
        $this->assertStillOnDay($ctx, $solo->getId(), self::THURSDAY);
    }

    /** TÉMOIN : changer d'heure ou de gymnase dans son propre jour reste légal, le moteur juge. */
    #[DataProvider('layers')]
    public function testMovingWithinTheSameDayStaysLegal(bool $period): void
    {
        $ctx = $this->decor($period);
        $tuesday = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');

        $this->post($ctx, '/api/schedule-slots/' . $tuesday->getId() . '/move', ['dayOfWeek' => self::TUESDAY, 'venueId' => $ctx['jdr'], 'startTime' => '20:30']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->engineCalls, 'la garde laisse le moteur juger');
    }

    /** TÉMOIN : évincer sa PROPRE séance du jour libère le jour : l'échange reste légal. */
    public function testEvictingItsOwnSessionOfThatDayStaysLegal(): void
    {
        $ctx = $this->decor(false);
        $own = $this->session($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30');
        $thursday = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->post($ctx, '/api/schedule-slots/' . $thursday->getId() . '/move', ['dayOfWeek' => self::TUESDAY, 'venueId' => $ctx['jdr'], 'startTime' => '20:30', 'evictSlotId' => $own->getId()]);

        self::assertResponseStatusCodeSame(200);
    }

    /** TÉMOIN : une AUTRE équipe présente ce jour-là ne gêne pas : la règle est par équipe. */
    public function testAnotherTeamOnThatDayIsNoObstacle(): void
    {
        $ctx = $this->decor(false);
        $this->session($ctx, $ctx['u18'], $ctx['armand'], self::TUESDAY, '18:00');
        $thursday = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->post($ctx, '/api/schedule-slots/' . $thursday->getId() . '/move', ['dayOfWeek' => self::TUESDAY, 'venueId' => $ctx['jdr'], 'startTime' => '20:30']);

        self::assertResponseStatusCodeSame(200);
    }

    /** La garde de case reste la première : même case, le motif reste « a déjà une séance ». */
    public function testTheCaseGuardKeepsSpeakingFirst(): void
    {
        $ctx = $this->decor(false);
        $this->session($ctx, $ctx['u15'], $ctx['jdr'], self::TUESDAY, '20:30');
        $thursday = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->post($ctx, '/api/schedule-slots/' . $thursday->getId() . '/move', ['dayOfWeek' => self::TUESDAY, 'venueId' => $ctx['jdr'], 'startTime' => '20:30']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('team_already_at_case', $this->body()['code'] ?? null);
    }

    /** Poser une séance de plus un jour où l'équipe s'entraîne : refusé, rien n'est créé. */
    #[DataProvider('layers')]
    public function testPlacingOnADayAlreadyTrainedIsRefused(bool $period): void
    {
        $ctx = $this->decor($period);
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');

        $this->post($ctx, '/api/schedules/' . $ctx['scheduleId'] . '/place-slot', ['teamId' => $ctx['u15'], 'dayOfWeek' => self::TUESDAY, 'venueId' => $ctx['jdr'], 'startTime' => '20:30']);

        $this->assertRefused('U15', 'mardi');
        $this->scopeGucToClub($ctx['clubId']);
        self::assertCount(1, $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $ctx['scheduleId']]), 'rien n\'est créé');
    }

    /** TÉMOIN : poser un jour libre reste permis. */
    public function testPlacingOnAFreeDayStaysLegal(): void
    {
        $ctx = $this->decor(false);
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');

        $this->post($ctx, '/api/schedules/' . $ctx['scheduleId'] . '/place-slot', ['teamId' => $ctx['u15'], 'dayOfWeek' => self::THURSDAY, 'venueId' => $ctx['jdr'], 'startTime' => '20:30']);

        self::assertResponseStatusCodeSame(200);
    }

    /** Déplacer un groupe vers un jour où UN membre s'entraîne seul : refusé en le nommant, aucun membre ne bouge. */
    #[DataProvider('layers')]
    public function testMovingAGroupOntoADayAMemberTrainsIsRefused(bool $period): void
    {
        $ctx = $this->decor($period);
        $block = $this->group($ctx, [$ctx['u15'], $ctx['u18']]);
        $u15 = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', blockId: $block);
        $this->session($ctx, $ctx['u18'], $ctx['armand'], self::THURSDAY, '19:00', blockId: $block);
        $this->session($ctx, $ctx['u18'], $ctx['armand'], self::TUESDAY, '18:00');

        $this->post($ctx, '/api/schedule-slots/move-group', [
            'scheduleId' => $ctx['scheduleId'],
            'blockId' => $block,
            'source' => ['venueId' => $ctx['armand'], 'dayOfWeek' => self::THURSDAY, 'startTime' => '19:00'],
            'target' => ['venueId' => $ctx['jdr'], 'dayOfWeek' => self::TUESDAY, 'startTime' => '20:30'],
        ]);

        $this->assertRefused('U18', 'mardi');
        $this->assertStillOnDay($ctx, $u15->getId(), self::THURSDAY);
    }

    /** TÉMOIN : le groupe déplacé dans son propre jour : ses N séances sont le geste, aucune ne compte contre une autre. */
    public function testMovingAGroupWithinItsOwnDayStaysLegal(): void
    {
        $ctx = $this->decor(false);
        $block = $this->group($ctx, [$ctx['u15'], $ctx['u18']]);
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', blockId: $block);
        $this->session($ctx, $ctx['u18'], $ctx['armand'], self::THURSDAY, '19:00', blockId: $block);

        $this->post($ctx, '/api/schedule-slots/move-group', [
            'scheduleId' => $ctx['scheduleId'],
            'blockId' => $block,
            'source' => ['venueId' => $ctx['armand'], 'dayOfWeek' => self::THURSDAY, 'startTime' => '19:00'],
            'target' => ['venueId' => $ctx['jdr'], 'dayOfWeek' => self::THURSDAY, 'startTime' => '20:30'],
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    /** Échanger envoie la source sur un jour qu'elle occupe déjà : refusé, personne ne bouge. */
    #[DataProvider('layers')]
    public function testSwappingTheSourceOntoADayItTrainsIsRefused(bool $period): void
    {
        $ctx = $this->decor($period);
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $source = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');
        $target = $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');

        $this->post($ctx, '/api/schedule-slots/swap', ['slotId' => $source->getId(), 'otherSlotId' => $target->getId()]);

        $this->assertRefused('U15', 'mardi');
        $this->assertStillOnDay($ctx, $source->getId(), self::THURSDAY);
        $this->assertStillOnDay($ctx, $target->getId(), self::TUESDAY);
    }

    /** L'autre sens de l'échange : c'est l'occupant qui arriverait sur un jour qu'il occupe déjà. */
    public function testSwappingTheOccupantOntoADayItTrainsIsRefused(): void
    {
        $ctx = $this->decor(false);
        $source = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');
        $target = $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');
        $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::THURSDAY, '20:30');

        $this->post($ctx, '/api/schedule-slots/swap', ['slotId' => $source->getId(), 'otherSlotId' => $target->getId()]);

        $this->assertRefused('U18', 'jeudi');
    }

    /** TÉMOIN : échanger deux équipes du même jour : chacune reste sur son jour, le geste est légal. */
    public function testSwappingTwoTeamsOfTheSameDayStaysLegal(): void
    {
        $ctx = $this->decor(false);
        $source = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $target = $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');

        $this->post($ctx, '/api/schedule-slots/swap', ['slotId' => $source->getId(), 'otherSlotId' => $target->getId()]);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Mutualiser depuis la fiche en remplaçant le jeudi alors que l'équipe a déjà le mardi de l'ancre : refusé, et la fiche dit
     * laquelle désigner. Rien n'est écrit : ni groupe, ni séance déplacée.
     */
    #[DataProvider('layers')]
    public function testMutualizingFromTheCardRefusesAndNamesTheSessionToDesignate(bool $period): void
    {
        $ctx = $this->decor($period);
        $anchor = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');
        $thursday = $this->session($ctx, $ctx['u18'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->post($ctx, '/api/schedule-slots/' . $anchor->getId() . '/mutualize', ['teamIds' => [$ctx['u15'], $ctx['u18']], 'replacedSlotIds' => [$thursday->getId()]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'U18 s\'entraîne déjà le mardi : une seule séance par jour. Désignez sa séance du mardi 20:30 à JDR comme séance remplacée.',
            $this->body()['error'] ?? null,
        );
        $this->assertStillOnDay($ctx, $thursday->getId(), self::THURSDAY);
        $this->assertNoGroupWritten($ctx);
    }

    /** TÉMOIN : la séance du mardi désignée comme remplacée, l'équipe garde une seule séance le mardi : la mutualisation passe. */
    #[DataProvider('layers')]
    public function testDesignatingTheSessionOfThatDayLetsTheMutualizationThrough(bool $period): void
    {
        $ctx = $this->decor($period);
        $anchor = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $tuesday = $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');
        $this->session($ctx, $ctx['u18'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->post($ctx, '/api/schedule-slots/' . $anchor->getId() . '/mutualize', ['teamIds' => [$ctx['u15'], $ctx['u18']], 'replacedSlotIds' => [$tuesday->getId()]]);

        self::assertResponseStatusCodeSame(200);
    }

    /** Deux équipes rejoignent, la seconde ne peut pas : tout ou rien, la première ne bouge pas non plus. */
    public function testOneJoiningTeamRefusedWritesNothingForTheOthers(): void
    {
        $ctx = $this->decor(false);
        $anchor = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $u18 = $this->session($ctx, $ctx['u18'], $ctx['armand'], self::THURSDAY, '19:00');
        $this->session($ctx, $ctx['u20'], $ctx['jdr'], self::TUESDAY, '20:30');
        $u20Thursday = $this->session($ctx, $ctx['u20'], $ctx['jdr'], self::THURSDAY, '20:30');

        $this->post($ctx, '/api/schedule-slots/' . $anchor->getId() . '/mutualize', ['teamIds' => [$ctx['u15'], $ctx['u18'], $ctx['u20']], 'replacedSlotIds' => [$u18->getId(), $u20Thursday->getId()]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('U20 s\'entraîne déjà le mardi', (string) ($this->body()['error'] ?? ''));
        $this->assertStillOnDay($ctx, $u18->getId(), self::THURSDAY);
        $this->assertNoGroupWritten($ctx);
    }

    /** Une séance de groupe le mardi compte pour le membre qui voudrait rejoindre une autre case ce mardi. */
    public function testASharedSessionBlocksJoiningAnotherGroupThatDay(): void
    {
        $ctx = $this->decor(false);
        $block = $this->group($ctx, [$ctx['u18'], $ctx['u20']]);
        $anchor = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30', blockId: $block);
        $this->session($ctx, $ctx['u20'], $ctx['jdr'], self::TUESDAY, '20:30', blockId: $block);
        $thursday = $this->session($ctx, $ctx['u18'], $ctx['armand'], self::THURSDAY, '19:00');

        $this->post($ctx, '/api/schedule-slots/' . $anchor->getId() . '/mutualize', ['teamIds' => [$ctx['u15'], $ctx['u18']], 'replacedSlotIds' => [$thursday->getId()]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('U18 s\'entraîne déjà le mardi', (string) ($this->body()['error'] ?? ''));
    }

    /** Glisser-déposer pour mutualiser sur un jour que la source occupe déjà : refusé, sans consigne de fiche, rien n'est écrit. */
    #[DataProvider('layers')]
    public function testMutualizingByDropOntoADayAlreadyTrainedIsRefused(bool $period): void
    {
        $ctx = $this->decor($period);
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $source = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');
        $target = $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');

        $this->post($ctx, '/api/schedule-slots/mutualize-at-case', ['sourceSlotId' => $source->getId(), 'targetSlotId' => $target->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('U15 s\'entraîne déjà le mardi : une seule séance par jour.', $this->body()['error'] ?? null);
        $this->assertStillOnDay($ctx, $source->getId(), self::THURSDAY);
        $this->assertNoGroupWritten($ctx);
    }

    /** L'essai à blanc du dépôt dit le même refus avant d'agir. */
    public function testTheDryRunOfADropRefusesBeforeActing(): void
    {
        $ctx = $this->decor(false);
        $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $source = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00');
        $target = $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');

        $this->post($ctx, '/api/schedule-slots/mutualize-at-case', ['sourceSlotId' => $source->getId(), 'targetSlotId' => $target->getId(), 'dryRun' => true]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('une seule séance par jour', (string) ($this->body()['error'] ?? ''));
    }

    /** Le groupe glissé porte chacun de ses membres : celui qui a déjà le mardi fait refuser le dépôt, nommé. */
    public function testDroppingAGroupOntoADayAMemberTrainsIsRefused(): void
    {
        $ctx = $this->decor(false);
        $block = $this->group($ctx, [$ctx['u15'], $ctx['u20']]);
        $source = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::THURSDAY, '19:00', blockId: $block);
        $this->session($ctx, $ctx['u20'], $ctx['armand'], self::THURSDAY, '19:00', blockId: $block);
        $this->session($ctx, $ctx['u20'], $ctx['armand'], self::TUESDAY, '18:00');
        $target = $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');

        $this->post($ctx, '/api/schedule-slots/mutualize-at-case', ['sourceSlotId' => $source->getId(), 'targetSlotId' => $target->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('U20 s\'entraîne déjà le mardi', (string) ($this->body()['error'] ?? ''));
    }

    /** TÉMOIN : le dépôt dans son propre jour : la source EST la séance qui bouge, la mutualisation passe. */
    #[DataProvider('layers')]
    public function testDroppingWithinTheSameDayStaysLegal(bool $period): void
    {
        $ctx = $this->decor($period);
        $source = $this->session($ctx, $ctx['u15'], $ctx['armand'], self::TUESDAY, '18:00');
        $target = $this->session($ctx, $ctx['u18'], $ctx['jdr'], self::TUESDAY, '20:30');

        $this->post($ctx, '/api/schedule-slots/mutualize-at-case', ['sourceSlotId' => $source->getId(), 'targetSlotId' => $target->getId()]);

        self::assertResponseStatusCodeSame(200);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->engineCalls = 0;
        self::getContainer()->set(EngineClient::class, new EngineClient(new MockHttpClient(function (): MockResponse {
            ++$this->engineCalls;

            return new MockResponse(self::ACCEPT, ['http_code' => 200]);
        }), new RequestIdContext));
    }

    /** @return Decor */
    private function decor(bool $period): array
    {
        return $period ? $this->periodDecor() : $this->seasonDecor();
    }

    /**
     * @param Decor                $ctx
     * @param array<string, mixed> $body
     */
    private function post(array $ctx, string $url, array $body): void
    {
        $this->client->request('POST', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($ctx['user']),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** Le refus nomme l'équipe et le jour, et le moteur n'a jamais été consulté. */
    private function assertRefused(string $team, string $day): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertSame(\sprintf('%s s\'entraîne déjà le %s : une seule séance par jour.', $team, $day), $this->body()['error'] ?? null);
        self::assertSame(0, $this->engineCalls, 'refusé avant le moteur');
    }

    /** @param Decor $ctx */
    private function assertStillOnDay(array $ctx, string $slotId, int $day): void
    {
        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertSame($day, $this->em->getRepository(ScheduleSlotTemplate::class)->find($slotId)?->getDayOfWeek(), 'rien n\'est écrit');
    }

    /** @param Decor $ctx */
    private function assertNoGroupWritten(array $ctx): void
    {
        $this->em->clear();
        $this->scopeGucToClub($ctx['clubId']);
        self::assertSame(0, $this->em->getRepository(SharedTrainingBlock::class)->count(['seasonId' => $ctx['seasonId']]), 'aucun groupe créé');
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data;
    }
}

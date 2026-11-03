<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Team;
use App\EventListener\CacheInvalidationListener;
use App\Service\ScheduleConstraintBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Isolation cross-club du cache métier RÉEL : `cache.schedule`, le payload
 * solveur (P2-9ter). P2-12 (2026-08-04) : ce gate attestait aussi l'isolation
 * de clés `tenant_data`/`schedule_snapshot` qu'AUCUN code n'écrivait — le pool
 * `cache.tenant` a été supprimé, le test est recentré sur ce qui existe.
 * Il reste le SEUL garde-fou d'isolation cross-club côté cache.
 */
#[Group('phase1')]
final class TenantCacheIsolationTest extends TestCase
{
    private const CLUB_A = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
    private const CLUB_B = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
    private const SEASON_ONE = '11111111-1111-4111-8111-111111111111';
    private const SEASON_TWO = '22222222-2222-4222-8222-222222222222';

    /**
     * P2-9ter NR-4 — le payload solveur est purgé par TAG, sur TOUTES les saisons du
     * club, et sur le club édité SEULEMENT.
     *
     * Le piège que ce test ferme : la clé du payload porte la saison, mais le
     * listener ne connaît que le club — deux entités du payload (`SportCategory`,
     * `TeamTag`) n'ont même pas de `seasonId`. Reconstruire une clé à la main ici
     * cesserait donc de viser quoi que ce soit, EN SILENCE. En passant par
     * `ScheduleConstraintBuilder::cacheTag`, la clé et l'invalidation ont une source
     * unique : elles ne peuvent plus diverger.
     */
    public function testScheduleInputIsPurgedByTagAcrossSeasonsAndIsolatesClubs(): void
    {
        $schedulePool = $this->seededPool();
        $listener = new CacheInvalidationListener($schedulePool);

        $listener->postPersist(new PostPersistEventArgs(
            (new Team)->setClubId(self::CLUB_A),
            $this->createMock(EntityManagerInterface::class),
        ));
        $listener->flushInvalidations();

        $this->assertClubAPurgedClubBIntact($schedulePool);
    }

    /**
     * P2-11 — le WORKER ne termine jamais une requête HTTP : sans écoute des
     * événements Messenger, les invalidations collectées pendant
     * `GenerateScheduleHandler` ne partaient JAMAIS (payload périmé après une
     * génération, `$pendingInvalidations` croissant sur un process long).
     */
    public function testWorkerMessageHandledFlushesTheInvalidations(): void
    {
        $schedulePool = $this->seededPool();
        $listener = new CacheInvalidationListener($schedulePool);

        $listener->postPersist(new PostPersistEventArgs(
            (new Team)->setClubId(self::CLUB_A),
            $this->createMock(EntityManagerInterface::class),
        ));
        // La fin de traitement d'un MESSAGE, pas d'une requête.
        $listener->onWorkerMessageHandled(new WorkerMessageHandledEvent(new Envelope(new stdClass), 'async'));

        $this->assertClubAPurgedClubBIntact($schedulePool);
    }

    /** An entity that cannot resolve a club id must not purge anything. */
    public function testEntityWithoutClubIdPurgesNothing(): void
    {
        $schedulePool = $this->seededPool();
        $listener = new CacheInvalidationListener($schedulePool);

        $listener->postPersist(new PostPersistEventArgs(
            new stdClass,
            $this->createMock(EntityManagerInterface::class),
        ));
        $listener->flushInvalidations();

        self::assertTrue($schedulePool->hasItem(ScheduleConstraintBuilder::cacheKey(self::CLUB_A, self::SEASON_ONE, null)));
        self::assertTrue($schedulePool->hasItem(ScheduleConstraintBuilder::cacheKey(self::CLUB_B, self::SEASON_ONE, null)));
    }

    /** Club A en cache sur DEUX saisons + club B sur une — le décor de chaque test. */
    private function seededPool(): TagAwareAdapter
    {
        $pool = new TagAwareAdapter(new ArrayAdapter);
        foreach ([[self::CLUB_A, self::SEASON_ONE], [self::CLUB_A, self::SEASON_TWO], [self::CLUB_B, self::SEASON_ONE]] as [$club, $season]) {
            $item = $pool->getItem(ScheduleConstraintBuilder::cacheKey($club, $season, null));
            $item->set(['payload' => $club . $season]);
            $item->tag(ScheduleConstraintBuilder::cacheTag($club));
            $pool->save($item);
        }

        return $pool;
    }

    private function assertClubAPurgedClubBIntact(TagAwareAdapter $pool): void
    {
        self::assertFalse(
            $pool->hasItem(ScheduleConstraintBuilder::cacheKey(self::CLUB_A, self::SEASON_ONE, null)),
            'le payload de la saison 1 du club édité doit être purgé',
        );
        self::assertFalse(
            $pool->hasItem(ScheduleConstraintBuilder::cacheKey(self::CLUB_A, self::SEASON_TWO, null)),
            'purger par tag doit emporter TOUTES les saisons du club — une entité sans seasonId ne peut viser aucune clé',
        );
        self::assertTrue(
            $pool->hasItem(ScheduleConstraintBuilder::cacheKey(self::CLUB_B, self::SEASON_ONE, null)),
            'le payload d\'un AUTRE club doit survivre — l\'isolation que ce gate garde',
        );
    }
}

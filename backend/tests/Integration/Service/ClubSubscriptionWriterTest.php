<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Enum\SubscriptionOrigin;
use App\Service\ClubSubscriptionWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lot 8, PR 2 — une ligne d'abonnement porte toujours un palier qui va avec son offre : l'offre
 * complète en exige un des siens, Découverte et la Bêta n'en portent aucun, et la base ne laisse
 * jamais une ligne pointer vers un palier absent.
 *
 * Angle nommé : l'ÉCRIVAIN seul, appelé sans la commande. Les jumeaux
 * `Integration/Command/SetClubPlanCommandTest::testTheTierIsRequiredForTheCompleteOfferAndRefusedElsewhere`
 * (la commande refuse avant l'écrivain) et `Security/AdminClubActionTest::testSetPlanValidatesTheClosedArgumentSchemaFailClosed`
 * (le schéma de la console refuse avant la commande) masquent le refus de l'écrivain : couper sa
 * condition SQL les laisse verts. Les seeders, la route de dev et la migration écrivent par lui
 * sans passer par la commande.
 *
 * Le décor vit sur la connexion `admin`, celle de l'écrivain (patron de `SetClubPlanCommandTest`).
 */
#[Group('integration')]
final class ClubSubscriptionWriterTest extends KernelTestCase
{
    private Connection $admin;

    private ClubSubscriptionWriter $writer;

    /**
     * L'écrivain refuse, sans rien écrire, l'offre complète sans palier ou avec un palier inconnu, et
     * un palier sur Découverte ou sur la Bêta. Témoins : l'offre complète avec son palier, la Bêta
     * sans palier s'écrivent.
     */
    public function testTheWriterRequiresTheTierOfTheCompleteOfferAndRefusesOneElsewhere(): void
    {
        $clubId = $this->club();

        foreach ([
            'offre complète sans palier' => ['complete', null],
            'offre complète, palier inconnu' => ['complete', 'palier-4'],
            'palier sur la Bêta' => ['beta', 'palier-1'],
            'palier sur Découverte' => ['decouverte', 'palier-1'],
        ] as $case => [$planCode, $tierCode]) {
            try {
                $this->writer->record($clubId, 2026, $planCode, $tierCode, SubscriptionOrigin::SUPPORT);
                self::fail($case . ' : l\'écrivain doit refuser');
            } catch (InvalidArgumentException) {
            }
            self::assertSame([], $this->linesOf($clubId), $case . ' : le refus n\'écrit aucune ligne');
        }

        $this->writer->record($clubId, 2026, 'complete', 'palier-2', SubscriptionOrigin::SUPPORT);
        $this->writer->record($clubId, 2027, 'beta', null, SubscriptionOrigin::SUPPORT);
        self::assertSame(
            [[2026, 'complete', 'palier-2'], [2027, 'beta', null]],
            $this->linesOf($clubId),
            'témoins : l\'offre complète avec son palier, la Bêta sans palier',
        );
    }

    /**
     * La base garde le lien ligne → palier : un palier porté par une ligne ne se supprime pas (clé
     * étrangère restreinte, ni effacée en cascade ni remise à vide), et une ligne ne pointe jamais
     * vers un palier qui n'existe pas.
     */
    public function testALineNeverPointsAtAMissingTier(): void
    {
        $clubId = $this->club();
        $this->writer->record($clubId, 2026, 'complete', 'palier-1', SubscriptionOrigin::SUPPORT);
        self::assertSame([[2026, 'complete', 'palier-1']], $this->linesOf($clubId), 'témoin : la ligne porte le palier 1');

        self::assertTrue(
            $this->isRefusedByAForeignKey('DELETE FROM subscription_plan_tier WHERE code = \'palier-1\''),
            'un palier porté par une ligne ne se supprime pas',
        );
        self::assertSame([[2026, 'complete', 'palier-1']], $this->linesOf($clubId), 'la ligne garde son palier');

        self::assertTrue(
            $this->isRefusedByAForeignKey('UPDATE club_subscription SET tier_id = gen_random_uuid() WHERE club_id = ?', [$clubId]),
            'une ligne ne pointe pas vers un palier absent',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);
        $this->admin = $connection;
        $writer = self::getContainer()->get(ClubSubscriptionWriter::class);
        self::assertInstanceOf(ClubSubscriptionWriter::class, $writer);
        $this->writer = $writer;
    }

    /**
     * L'écriture dans un point de sauvegarde (une erreur n'empoisonne pas la transaction du test).
     *
     * @param list<string> $parameters
     */
    private function isRefusedByAForeignKey(string $sql, array $parameters = []): bool
    {
        $this->admin->beginTransaction();
        try {
            $this->admin->executeStatement($sql, $parameters);

            return false;
        } catch (ForeignKeyConstraintViolationException) {
            return true;
        } finally {
            $this->admin->rollBack();
        }
    }

    private function club(): string
    {
        $clubId = $this->admin->fetchOne('SELECT gen_random_uuid()::text');
        self::assertIsString($clubId);
        $suffix = bin2hex(random_bytes(4));
        $this->admin->executeStatement(
            'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season, is_demo) VALUES (?, 1, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0, false)',
            [$clubId, 'Writer ' . $suffix, 'writer-' . $suffix],
        );

        return $clubId;
    }

    /** @return list<array{0: int, 1: string, 2: ?string}> [année, offre, palier] */
    private function linesOf(string $clubId): array
    {
        $rows = $this->admin->fetchAllAssociative(
            'SELECT cs.season_year, p.code AS plan, t.code AS tier FROM club_subscription cs JOIN subscription_plan p ON p.id = cs.plan_id LEFT JOIN subscription_plan_tier t ON t.id = cs.tier_id WHERE cs.club_id = ? ORDER BY cs.season_year',
            [$clubId],
        );

        return array_map(static fn (array $row): array => [(int) $row['season_year'], (string) $row['plan'], null === $row['tier'] ? null : (string) $row['tier']], $rows);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Service\Geo\OpponentTravelFailureStore;
use App\Tests\Integration\Api\OpponentTravelApiTest;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * M14 (retours Nico, BCCL 2026-10-27) — le magasin VOLATILE de la cause d'échec du DERNIER
 * calcul de trajets adverses (clé d'appariement → budget épuisé / introuvable), sous UNE clé
 * Redis par club+saison. Ce test prouve son contrat contre un vrai Redis : aller-retour,
 * écrasement à chaque run, effacement sur carte vide, et surtout l'ÉTANCHÉITÉ par club+saison
 * — Redis n'a pas de RLS, seul le NOMMAGE de la clé isole les locataires.
 *
 * Point sensible « tenant » (charte des tests §1) : la non-fuite entre clubs est prouvée ICI,
 * au plus bas, comme angle complémentaire de {@see OpponentTravelResolverTest}
 * (qui prouve la CLASSIFICATION du run) et de {@see OpponentTravelApiTest}
 * (qui prouve le service PAR LIGNE du GET). Nettoyage par DEL ciblé (`record(..., [])`), jamais FLUSHALL.
 */
#[Group('integration')]
final class OpponentTravelFailureStoreTest extends KernelTestCase
{
    /** @var list<array{0: string, 1: string}> clubId+seasonId écrits, à DEL en tearDown */
    private array $written = [];

    public function testRecordRoundTripsTheCausesKeyedByPairingKey(): void
    {
        $store = $this->store();
        [$club, $season] = $this->ids();

        $this->write($store, $club, $season, [
            'ARA0069001' => OpponentTravelFailureStore::REASON_AWAITING_RETRY,
            'ARA0069002' => OpponentTravelFailureStore::REASON_UNROUTABLE,
        ]);

        self::assertSame(
            [
                'ARA0069001' => OpponentTravelFailureStore::REASON_AWAITING_RETRY,
                'ARA0069002' => OpponentTravelFailureStore::REASON_UNROUTABLE,
            ],
            $store->reasonsFor($club, $season),
            'la carte revient telle qu\'écrite, keyée par clé d\'appariement',
        );
    }

    public function testJamaisCalculeEstUneCarteVide(): void
    {
        $store = $this->store();
        [$club, $season] = $this->ids();

        self::assertSame([], $store->reasonsFor($club, $season), 'aucun run mémorisé → carte vide');
    }

    public function testAnEmptyMapDeletesTheKey(): void
    {
        $store = $this->store();
        [$club, $season] = $this->ids();

        $this->write($store, $club, $season, ['ARA0069010' => OpponentTravelFailureStore::REASON_UNROUTABLE]);
        self::assertNotSame([], $store->reasonsFor($club, $season), 'préalable : une cause est mémorisée');

        // Un run sans échec (tout résolu depuis) → la carte vide EFFACE la clé : la cause disparaît.
        $store->record($club, $season, []);
        self::assertSame([], $store->reasonsFor($club, $season), 'un run propre efface les causes du run précédent');
    }

    public function testEachRunOverwritesThePreviousMap(): void
    {
        $store = $this->store();
        [$club, $season] = $this->ids();

        $this->write($store, $club, $season, ['ARA0069020' => OpponentTravelFailureStore::REASON_AWAITING_RETRY]);
        // Le run suivant ne CUMULE pas : il reflète le DERNIER calcul (l'ancienne cause a disparu).
        $this->write($store, $club, $season, ['ARA0069021' => OpponentTravelFailureStore::REASON_UNROUTABLE]);

        self::assertSame(
            ['ARA0069021' => OpponentTravelFailureStore::REASON_UNROUTABLE],
            $store->reasonsFor($club, $season),
            'la carte reflète le dernier run, jamais un cumul',
        );
    }

    /**
     * Étanchéité tenant (point sensible) : une cause écrite pour un club+saison n'est JAMAIS lue
     * sous un AUTRE club, ni sous une AUTRE saison du même club. C'est le nommage de la clé qui
     * isole — un bug de clé ferait fuiter la cause d'un club chez son voisin de ville.
     */
    public function testCausesNeverLeakToAnotherClubOrSeason(): void
    {
        $store = $this->store();
        [$clubA, $seasonX] = $this->ids();
        $clubB = Uuid::v4()->toRfc4122();
        $seasonY = Uuid::v4()->toRfc4122();

        $this->write($store, $clubA, $seasonX, ['ARA0069030' => OpponentTravelFailureStore::REASON_UNROUTABLE]);

        self::assertSame([], $store->reasonsFor($clubB, $seasonX), 'un AUTRE club ne voit pas la cause (même saison)');
        self::assertSame([], $store->reasonsFor($clubA, $seasonY), 'une AUTRE saison du même club ne la voit pas non plus');
        self::assertNotSame([], $store->reasonsFor($clubA, $seasonX), 'le club+saison d\'origine, lui, la voit toujours');
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $store = $this->store();
        foreach ($this->written as [$club, $season]) {
            $store->record($club, $season, []); // DEL ciblé, jamais FLUSHALL
        }
        $this->written = [];
        parent::tearDown();
    }

    /**
     * @param array<string, OpponentTravelFailureStore::REASON_*> $reasons
     */
    private function write(OpponentTravelFailureStore $store, string $clubId, string $seasonId, array $reasons): void
    {
        $this->written[] = [$clubId, $seasonId];
        $store->record($clubId, $seasonId, $reasons);
    }

    /** @return array{0: string, 1: string} un club+saison uniques (RFC 4122) */
    private function ids(): array
    {
        return [Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()];
    }

    private function store(): OpponentTravelFailureStore
    {
        $store = self::getContainer()->get(OpponentTravelFailureStore::class);
        self::assertInstanceOf(OpponentTravelFailureStore::class, $store);

        return $store;
    }
}

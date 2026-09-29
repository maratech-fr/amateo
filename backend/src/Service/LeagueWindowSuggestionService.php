<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClubLeagueWindow;
use App\Entity\LeagueMatchWindow;
use App\Entity\Season;
use App\Repository\ClubLeagueWindowRepository;
use App\Repository\ClubRepository;
use App\Repository\LeagueMatchWindowRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

/**
 * P4-272 ② — la « tendance dominante » des plages de match de l'INSTANCE fédérale du
 * club (comité / ligue / fédération selon le niveau de la plage), plus un repli sur
 * le catalogue fédéral de la ligue là où aucune tendance ne se dégage. Le gros du
 * calcul (agrégat cross-tenant, seuil ≥ 3 ET majorité) vit dans la fonction SQL
 * `league_window_suggestions` (SECURITY DEFINER) : le service l'appelle, ajoute le
 * repli fédéral, et MASQUE côté serveur toute combinaison déjà identique à la copie
 * du club (rien à suggérer). Aucune plage n'est JAMAIS fournie par le client :
 * `apply` RECALCULE la suggestion et remplace la copie.
 *
 * Instance dérivée du `ffbb_club_code` du demandeur : 3 lettres = ligue, 4 chiffres
 * suivants = comité. Code illisible → réponse neutre (`instance: null`, zéro item).
 * Le repli fédéral mappe le préfixe → clé catalogue via {@see LeagueResolver::PREFIX_LEAGUE}
 * (ARA → AURA) — JAMAIS AURA pour une autre ligue (GUY → rien).
 */
final class LeagueWindowSuggestionService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubRepository $clubRepository,
        private readonly ClubLeagueWindowRepository $clubLeagueWindowRepository,
        private readonly LeagueMatchWindowRepository $leagueMatchWindowRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * @param list<array{kickoffMin: string, kickoffMax: string}> $windows
     */
    private static function windowsFingerprint(array $windows): string
    {
        usort($windows, static fn (array $a, array $b): int => [$a['kickoffMin'], $a['kickoffMax']] <=> [$b['kickoffMin'], $b['kickoffMax']]);

        return json_encode(array_map(static fn (array $w): string => $w['kickoffMin'] . '-' . $w['kickoffMax'], $windows), \JSON_THROW_ON_ERROR);
    }

    private static function combinationKey(string $category, string $level, ?string $gender, int $dayOfWeek): string
    {
        return implode('|', [$category, $level, $gender ?? '', $dayOfWeek]);
    }

    /**
     * @return array{
     *     instance: array{ligue: string, comite: string}|null,
     *     items: list<array{category: string, level: string, gender: string|null, dayOfWeek: int, windows: list<array{kickoffMin: string, kickoffMax: string}>, clubCount: int|null, source: string, scope: string}>
     * }
     */
    public function suggestionsFor(string $clubId): array
    {
        $instance = $this->instanceOf($clubId);
        if (null === $instance) {
            return ['instance' => null, 'items' => []];
        }

        $items = array_values($this->buildSuggestionMap($clubId, $instance['ligue']));

        // Masquage SERVEUR : une combinaison déjà identique à ma copie ne se suggère pas.
        $mine = $this->myCopyByCombination($clubId);
        $items = array_values(array_filter(
            $items,
            static function (array $item) use ($mine): bool {
                $key = self::combinationKey($item['category'], $item['level'], $item['gender'], $item['dayOfWeek']);

                return !isset($mine[$key]) || $mine[$key] !== self::windowsFingerprint($item['windows']);
            },
        ));

        usort($items, static fn (array $a, array $b): int => [$a['category'], $a['level'], $a['gender'] ?? '', $a['dayOfWeek']]
                <=> [$b['category'], $b['level'], $b['gender'] ?? '', $b['dayOfWeek']]);

        // Ne conserver que les clés servies (liste blanche — aucune club-identifiante).
        $items = array_map(
            static fn (array $i): array => [
                'category' => $i['category'],
                'level' => $i['level'],
                'gender' => $i['gender'],
                'dayOfWeek' => $i['dayOfWeek'],
                'windows' => $i['windows'],
                'clubCount' => $i['clubCount'],
                'source' => $i['source'],
                'scope' => $i['scope'],
            ],
            $items,
        );

        return ['instance' => $instance, 'items' => $items];
    }

    /**
     * Recalcule la suggestion et REMPLACE, transactionnellement, les lignes de la
     * copie pour chacune des combinaisons demandées (jamais de plages du client). Une
     * combinaison sans suggestion recalculée est ignorée. Rend le nombre appliqué.
     *
     * @param list<array{category: string, level: string, gender: string|null, dayOfWeek: int}> $combinations
     */
    public function apply(string $clubId, array $combinations): int
    {
        $season = $this->currentSeason($clubId);
        $instance = $this->instanceOf($clubId);
        if (!$season instanceof Season || null === $instance) {
            return 0;
        }

        $map = $this->buildSuggestionMap($clubId, $instance['ligue']);
        $league = $this->leagueMatchWindowRepository->effectiveLeague($this->clubRepository->find($clubId)?->getLeague());

        $applied = 0;
        $this->connection->beginTransaction();
        try {
            foreach ($combinations as $combination) {
                $gender = ('' === $combination['gender']) ? null : $combination['gender'];
                $key = self::combinationKey($combination['category'], $combination['level'], $gender, $combination['dayOfWeek']);
                if (!isset($map[$key])) {
                    continue;
                }

                $this->replaceCopyRows($clubId, $season->getId(), $league, $map[$key]);
                ++$applied;
            }
            $this->entityManager->flush();
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return $applied;
    }

    /**
     * Deletes the copy's rows for this combination and re-inserts the suggested set.
     *
     * @param array{category: string, level: string, gender: string|null, dayOfWeek: int, windows: list<array{kickoffMin: string, kickoffMax: string}>} $suggestion
     */
    private function replaceCopyRows(string $clubId, string $seasonId, string $league, array $suggestion): void
    {
        // DELETE en DQL : les filtres Doctrine ne s'appliquent PAS aux DELETE — on
        // borne explicitement au club+saison (la RLS via le GUC est la seconde ceinture).
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(ClubLeagueWindow::class, 'w')
            ->where('w.clubId = :clubId')->setParameter('clubId', $clubId)
            ->andWhere('w.seasonId = :seasonId')->setParameter('seasonId', $seasonId)
            ->andWhere('w.category = :category')->setParameter('category', $suggestion['category'])
            ->andWhere('w.level = :level')->setParameter('level', $suggestion['level'])
            ->andWhere('w.dayOfWeek = :dayOfWeek')->setParameter('dayOfWeek', $suggestion['dayOfWeek']);
        if (null === $suggestion['gender']) {
            $qb->andWhere('w.gender IS NULL');
        } else {
            $qb->andWhere('w.gender = :gender')->setParameter('gender', $suggestion['gender']);
        }
        $qb->getQuery()->execute();

        foreach ($suggestion['windows'] as $window) {
            $row = new ClubLeagueWindow;
            $row->setClubId($clubId);
            $row->setSeasonId($seasonId);
            $row->setLeague($league);
            $row->setCategory($suggestion['category']);
            $row->setLevel($suggestion['level']);
            $row->setGender($suggestion['gender']);
            $row->setDayOfWeek($suggestion['dayOfWeek']);
            $row->setKickoffMin(new DateTimeImmutable($window['kickoffMin']));
            $row->setKickoffMax(new DateTimeImmutable($window['kickoffMax']));
            $this->entityManager->persist($row);
        }
    }

    /**
     * Tendency rows (SQL function) + federation fallback for uncovered combinations,
     * keyed by combination. NOT masked against the club's own copy — that is done by
     * the read path only (apply wants the full recomputed set).
     *
     * @return array<string, array{category: string, level: string, gender: string|null, dayOfWeek: int, windows: list<array{kickoffMin: string, kickoffMax: string}>, clubCount: int|null, source: string, scope: string}>
     */
    private function buildSuggestionMap(string $clubId, string $ligue): array
    {
        $map = [];

        /** @var list<array{category: string, level: string, gender: string|null, day_of_week: int|string, windows: string, club_count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT category, level, gender, day_of_week, windows, club_count FROM league_window_suggestions(:club)',
            ['club' => $clubId],
        );
        foreach ($rows as $row) {
            $category = $row['category'];
            $level = $row['level'];
            $gender = $row['gender'];
            $dayOfWeek = (int) $row['day_of_week'];
            $key = self::combinationKey($category, $level, $gender, $dayOfWeek);
            $map[$key] = [
                'category' => $category,
                'level' => $level,
                'gender' => $gender,
                'dayOfWeek' => $dayOfWeek,
                'windows' => $this->decodeWindows($row['windows']),
                'clubCount' => (int) $row['club_count'],
                'source' => 'clubs',
                'scope' => $this->scopeForLevel($level),
            ];
        }

        // Repli fédéral : catalogue de MA ligue (préfixe → clé catalogue), pour les
        // combinaisons SANS tendance. Jamais AURA pour une autre ligue (préfixe absent
        // de la table ⇒ pas de repli).
        $fedKey = LeagueResolver::PREFIX_LEAGUE[$ligue] ?? null;
        if (null !== $fedKey) {
            foreach ($this->federationWindowsByCombination($fedKey) as $key => $fed) {
                if (!isset($map[$key])) {
                    $map[$key] = $fed;
                }
            }
        }

        return $map;
    }

    /**
     * @return array<string, array{category: string, level: string, gender: string|null, dayOfWeek: int, windows: list<array{kickoffMin: string, kickoffMax: string}>, clubCount: int|null, source: string, scope: string}>
     */
    private function federationWindowsByCombination(string $fedKey): array
    {
        /** @var list<LeagueMatchWindow> $windows */
        $windows = $this->leagueMatchWindowRepository->findBy(['league' => $fedKey]);
        $byCombination = [];
        foreach ($windows as $window) {
            $key = self::combinationKey($window->getCategory(), $window->getLevel(), $window->getGender(), $window->getDayOfWeek());
            $byCombination[$key]['meta'] = $window;
            $byCombination[$key]['windows'][] = [
                'kickoffMin' => $window->getKickoffMin()->format('H:i'),
                'kickoffMax' => $window->getKickoffMax()->format('H:i'),
            ];
        }

        $out = [];
        foreach ($byCombination as $key => $group) {
            /** @var LeagueMatchWindow $meta */
            $meta = $group['meta'];
            /** @var list<array{kickoffMin: string, kickoffMax: string}> $ws */
            $ws = $group['windows'];
            usort($ws, static fn (array $a, array $b): int => [$a['kickoffMin'], $a['kickoffMax']] <=> [$b['kickoffMin'], $b['kickoffMax']]);
            $out[$key] = [
                'category' => $meta->getCategory(),
                'level' => $meta->getLevel(),
                'gender' => $meta->getGender(),
                'dayOfWeek' => $meta->getDayOfWeek(),
                'windows' => $ws,
                'clubCount' => null,
                'source' => 'federation',
                'scope' => 'federation',
            ];
        }

        return $out;
    }

    /**
     * The club's own copy for the current season, as combination → windows fingerprint.
     *
     * @return array<string, string>
     */
    private function myCopyByCombination(string $clubId): array
    {
        $season = $this->currentSeason($clubId);
        if (!$season instanceof Season) {
            return [];
        }

        $byCombination = [];
        foreach ($this->clubLeagueWindowRepository->findForClubSeason($clubId, $season->getId()) as $window) {
            $key = self::combinationKey($window->getCategory(), $window->getLevel(), $window->getGender(), $window->getDayOfWeek());
            $byCombination[$key][] = [
                'kickoffMin' => $window->getKickoffMin()->format('H:i'),
                'kickoffMax' => $window->getKickoffMax()->format('H:i'),
            ];
        }

        return array_map(static fn (array $ws): string => self::windowsFingerprint($ws), $byCombination);
    }

    /** @return array{ligue: string, comite: string}|null */
    private function instanceOf(string $clubId): ?array
    {
        $ffbb = $this->clubRepository->find($clubId)?->getFfbbClubCode();
        if (!\is_string($ffbb) || 1 !== preg_match('/^([A-Za-z]{3})([0-9]{4})/', $ffbb, $m)) {
            return null;
        }

        return ['ligue' => strtoupper($m[1]), 'comite' => $m[2]];
    }

    private function currentSeason(string $clubId): ?Season
    {
        return $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
    }

    /** @return list<array{kickoffMin: string, kickoffMax: string}> */
    private function decodeWindows(string $json): array
    {
        /** @var list<array{kickoffMin: string, kickoffMax: string}> $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        // Normalise key order (jsonb reorders keys) → a stable {kickoffMin, kickoffMax}.
        return array_map(
            static fn (array $w): array => ['kickoffMin' => $w['kickoffMin'], 'kickoffMax' => $w['kickoffMax']],
            $decoded,
        );
    }

    /** Instance grouping the level maps to (founder ruling 2026-09-29). */
    private function scopeForLevel(string $level): string
    {
        return match ($level) {
            'REGIONAL' => 'ligue',
            'NATIONAL', 'ELITE' => 'federation',
            default => 'comite',
        };
    }
}

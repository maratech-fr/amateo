<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Service\PlanEntitlements;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * P2-21 lot A — crée AUTOMATIQUEMENT les équipes engagées d'un club neuf à
 * l'onboarding (cadrage ffbb-appariement-source-de-verite §6bis).
 *
 * Décisions fondateur (2026-08-04) :
 *  - création automatique + modale d'annonce côté wizard — « le gestionnaire
 *    n'a rien à faire, il constate que 10 équipes sont déjà chargées » ;
 *  - l'API est une AIDE, pas la source de vérité : tout est éditable, le
 *    manuel et l'import Excel restent (P3-7 gardé) ;
 *  - PAS d'appariement compétition ici — une équipe fraîchement créée va être
 *    renommée/supprimée, un lien posé maintenant serait un lien fragile ;
 *  - décodage des codes de compétition pour les JEUNES (champs structurés
 *    vides — mesuré 7/14 sur BCCL) : `RMU13` = Régional Masculin U13.
 *
 * Bornes :
 *  - CLUB VIDE uniquement : la moindre équipe existante → no-op (jamais de
 *    doublon silencieux ; la ré-exécution passera par une proposition
 *    explicite, cf. §1ter) ;
 *  - un engagement non mappable (catégorie inconnue du catalogue) est SAUTÉ
 *    et loggé — jamais deviné ;
 *  - juin : les poules n'existent pas encore → le reader rend 0 ligne, no-op
 *    naturel (§5).
 */
final class FfbbTeamImporter
{
    /**
     * Niveau décodé → ordinal de TRI (PN d'abord). N'EST PLUS un rang (refonte PR2 D9 : le rang suit
     * la règle FBI unique {@see TeamCreationSuggester}, par catégorie + numéro) — juste l'ordre dans
     * lequel on numérote les équipes d'un même moule (le plus haut niveau prend le n°1).
     */
    private const LEVEL_SORT = ['PN' => 0, 'R' => 1, 'PR' => 2, 'D' => 3];

    private const LEVEL_ENUM = ['PN' => TeamLevel::NATIONAL, 'R' => TeamLevel::REGIONAL, 'PR' => TeamLevel::PRE_REGION, 'D' => TeamLevel::DEPARTEMENTAL];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FfbbEngagementReader $reader,
        private readonly SeasonResolver $seasonResolver,
        private readonly LoggerInterface $logger,
        private readonly PlanEntitlements $planEntitlements,
        private readonly TeamCreationSuggester $suggester,
    ) {}

    /**
     * @return int the number of teams created (0 = nothing to do / not empty)
     */
    public function importEngagedTeams(Club $club): int
    {
        $clubCode = $club->getFfbbClubCode();
        $season = $this->seasonResolver->currentSeason($club->getId());
        if (null === $clubCode || !$season instanceof Season) {
            return 0;
        }
        // Borne « club vide » : une équipe existe → un humain (ou un import) est
        // déjà passé, on ne mélange pas nos créations aux siennes.
        if ([] !== $this->entityManager->getRepository(Team::class)->findBy(['clubId' => $club->getId()], [], 1)) {
            return 0;
        }

        $rows = $this->reader->read($clubCode, SeasonResolver::seasonYear($season->getStartDate()));
        if ([] === $rows) {
            return 0;
        }

        /** @var list<SportCategory> $categories */
        $categories = $this->entityManager->getRepository(SportCategory::class)->findBy(['clubId' => $club->getId()]);

        // Décodage + tri : niveau (PN > R > PR > D) puis âge décroissant — les plus hauts d'abord,
        // pour que la NUMÉROTATION des équipes d'un même moule donne le n°1 au plus haut niveau (le
        // rang, lui, suit ensuite la règle FBI par catégorie + numéro).
        $decoded = [];
        foreach ($rows as $row) {
            $parsed = $this->decode($row);
            if (null === $parsed) {
                continue;
            }
            $category = $this->matchCategory($categories, $parsed['categoryToken']);
            if (!$category instanceof SportCategory) {
                $this->logger->warning('FFBB team import: unmapped category, engagement skipped', [
                    'clubId' => $club->getId(),
                    'competition' => $row['competitionName'],
                    'categoryToken' => $parsed['categoryToken'],
                ]);

                continue;
            }
            $decoded[] = [...$parsed, 'category' => $category, 'competitionName' => $row['competitionName']];
        }
        usort($decoded, static fn (array $a, array $b): int => [$a['levelSort'], -$a['ageMax']] <=> [$b['levelSort'], -$b['ageMax']]);

        // P1-3 — cap payant : n'importe que jusqu'au cap, le reste est sauté et loggé. Ce chemin
        // ne tourne que sur un club VIDE (borne ci-dessus), donc teamsUsed = 0 → remaining = cap.
        // À l'onboarding le club est Découverte (cap null) : rien n'est bridé ; en pratique seul un
        // club payant qui ré-importe après vidage est concerné (spec §4, note assumée).
        $cap = $this->planEntitlements->teamCap($club, $season);
        if (null !== $cap && \count($decoded) > $cap) {
            $this->logger->warning('FFBB team import: offer cap reached, extra engagements skipped', [
                'clubId' => $club->getId(),
                'cap' => $cap,
                'engagements' => \count($decoded),
            ]);
            $decoded = \array_slice($decoded, 0, $cap);
        }

        $created = 0;
        $usedNames = [];
        foreach ($decoded as $order => $entry) {
            $base = $this->baseName($entry);
            $name = $this->uniqueName($base, $usedNames);
            $usedNames[$name] = true;

            // Refonte PR2 (D9) — le RANG suit la règle FBI unique ({@see TeamCreationSuggester}, par
            // catégorie + numéro), plus le niveau PN/R/D d'office. Le numéro est le suffixe que vient
            // d'apposer `uniqueName` (SM1 → 1). Le GENRE reste le STRUCTURÉ fédéral décodé (prioritaire
            // sur ce que lirait le suggester au nom) ; le `level` décodé est TOUJOURS stocké.
            $number = (string) (int) substr($name, \strlen($base));
            $categoryLabel = 'SE' === $entry['categoryToken'] ? 'Seniors' : $entry['categoryToken'];
            $suggestion = $this->suggester->suggest($name, $categoryLabel, $number);

            $team = new Team;
            $team->setClubId($club->getId());
            $team->setSeasonId($season->getId());
            $team->setSportCategoryId($entry['category']->getId());
            $team->setName($name);
            $team->setGender($entry['gender']);
            $team->setLevel($entry['level']);
            $team->setPriorityTierId($suggestion['tierId']);
            $team->setTierOrder($order);
            // sessionsPerWeek : défaut d'entité = 2 (décision fondateur 2026-08-04).
            $this->entityManager->persist($team);
            ++$created;
        }
        if ($created > 0) {
            // Vérité serveur : c'est ELLE (pas un flag localStorage) qui autorise
            // la modale « équipes importées » et l'atterrissage forcé sur Équipes.
            $club->setFfbbTeamsImportedAt(new DateTimeImmutable);
        }
        $this->entityManager->flush();

        return $created;
    }

    /**
     * Décode un engagement : champs structurés d'abord (les seniors), grammaire
     * du code de compétition en repli (les jeunes — `RMU13` = R + M + U13).
     *
     * @param array{ffbbCompetitionCode: string, category: ?string, level: ?string, gender: ?string} $row
     *
     * @return array{categoryToken: string, gender: ?Gender, level: ?TeamLevel, levelSort: int, ageMax: int}|null
     */
    private function decode(array $row): ?array
    {
        $code = strtoupper($row['ffbbCompetitionCode']);
        preg_match('/^(PN|PR|R|D)(M|F)?(U\d{1,2})?/', $code, $m);
        $levelToken = '' !== ($m[1] ?? '') ? $m[1] : null;
        $genderToken = '' !== ($m[2] ?? '') ? $m[2] : null;
        $categoryToken = '' !== ($m[3] ?? '') ? $m[3] : null;

        // Structuré d'abord — le code ne sert que là où la FFBB n'a rien rempli.
        $gender = match ($row['gender']) {
            'Masculin' => Gender::M,
            'Féminin' => Gender::F,
            default => null !== $genderToken ? Gender::from($genderToken) : null,
        };
        $category = match (true) {
            null !== $row['category'] && str_starts_with($row['category'], 'Senior') => 'SE',
            null !== $categoryToken => $categoryToken,
            null !== $row['category'] && 1 === preg_match('/U\d{1,2}/', $row['category'], $cm) => $cm[0],
            default => null,
        };
        if (null === $category) {
            return null;
        }

        return [
            'categoryToken' => $category,
            'gender' => $gender,
            'level' => null !== $levelToken ? self::LEVEL_ENUM[$levelToken] : null,
            'levelSort' => null !== $levelToken ? self::LEVEL_SORT[$levelToken] : 4,
            'ageMax' => 'SE' === $category ? 99 : (int) substr($category, 1),
        ];
    }

    /**
     * La catégorie du CLUB qui contient l'âge décodé — `U20` → U21, `U17` → U18
     * (le catalogue n'a pas toutes les bornes FFBB, le plus proche CONTENANT
     * gagne ; rien ne contient → null, l'engagement sera sauté).
     *
     * @param list<SportCategory> $categories
     */
    private function matchCategory(array $categories, string $token): ?SportCategory
    {
        if ('SE' === $token) {
            foreach ($categories as $category) {
                if ('Senior' === $category->getName()) {
                    return $category;
                }
            }

            return null;
        }
        $age = (int) substr($token, 1);
        $best = null;
        foreach ($categories as $category) {
            $min = $category->getAgeMin();
            $max = $category->getAgeMax();
            if (null === $min || null === $max || $age < $min || $age > $max || $max >= 99) {
                continue; // les catégories sans bornes jeunes (Senior/Vétéran/Loisir) ne captent pas un U-token
            }
            if (null === $best || $max < $best->getAgeMax()) {
                $best = $category; // la plus SERRÉE qui contient l'âge
            }
        }

        return $best;
    }

    /**
     * « SM »/« SF » pour les seniors, « U13M »/« U13F » pour les jeunes.
     *
     * @param array{categoryToken: string, gender: ?Gender} $entry
     */
    private function baseName(array $entry): string
    {
        $genderSuffix = match ($entry['gender']) {
            Gender::M => 'M',
            Gender::F => 'F',
            default => '',
        };
        if ('SE' === $entry['categoryToken']) {
            return 'S' . ('' !== $genderSuffix ? $genderSuffix : 'E');
        }

        return $entry['categoryToken'] . $genderSuffix;
    }

    /** @param array<string, true> $used */
    private function uniqueName(string $base, array $used): string
    {
        // « SM1 » d'office : le club aura probablement plusieurs équipes du même
        // moule, autant numéroter dès la première (c'est le vocabulaire usuel).
        for ($i = 1;; ++$i) {
            $candidate = $base . $i;
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }
    }
}

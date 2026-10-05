<?php

declare(strict_types=1);

namespace App\Service\Fbi;

use App\Entity\Competition;
use App\Enum\FixtureHomeAway;
use App\Service\Basketball\FbiDivisionSignature;
use App\Service\Basketball\VenueLabelNormalizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gardes de correspondance division↔compétition de l'import FBI (P1-4 PR F2 /
 * 6.1 / 6.3) : le garde-fou avant écriture des mappings, la garde de poule et le
 * résolveur de suggestion. Extrait verbatim de {@see FbiFixtureImporter} (lot 5
 * architecture, BCK-19) — l'importeur délègue. `normalizeLabel`/`containsClub`
 * sont les mêmes adaptateurs fins vers le foyer unique {@see VenueLabelNormalizer}
 * que ceux de l'importeur.
 */
final class FbiMappingGuards
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly FbiDivisionSignature $divisionSignature,
    ) {}

    /**
     * Guard-before-write (revue F2 round 1): a mapping whose division the poule
     * guard REFUSES is dropped (named error) instead of persisted — the dialog
     * has no remap gesture, a wrong write would stick. The target competition
     * is resolved WITHOUT writing: the suggestion's competitionId, else the
     * exact (team, name) lookup persistMappings would use. A target without
     * pairing has no poule → never checked, mapping passes.
     *
     * @param list<array{division: string, fbiTeamLabel: string|null, teamId: string, competitionId: string|null}>                                                                                                                                                                                              $mappings
     * @param list<array{name: string, divisionKey: string, label: string, labelKey: string, multiLabel: bool, rowCount: int, rows: list<array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}>}> $groups
     * @param list<string>                                                                                                                                                                                                                                                                                      $errors
     * @param array<string, true>                                                                                                                                                                                                                                                                               $blockedKeys divisionKey|labelKey of refused divisions
     *
     * @return list<array{division: string, fbiTeamLabel: string|null, teamId: string, competitionId: string|null}> the surviving mappings
     */
    public function rejectGuardBlockedMappings(array $mappings, array $groups, array &$errors, array &$blockedKeys): array
    {
        if ([] === $mappings) {
            return [];
        }
        $competitionRepository = $this->entityManager->getRepository(Competition::class);

        $survivors = [];
        foreach ($mappings as $mapping) {
            $divisionKey = $this->normalizeLabel($mapping['division']);
            $labelKey = null !== $mapping['fbiTeamLabel'] ? $this->normalizeLabel($mapping['fbiTeamLabel']) : null;
            $group = null;
            foreach ($groups as $candidate) {
                if ($candidate['divisionKey'] === $divisionKey && (null === $labelKey || $candidate['labelKey'] === $labelKey)) {
                    $group = $candidate;
                    break;
                }
            }

            $target = null;
            $mappingCompetitionId = $mapping['competitionId'] ?? null;
            if (null !== $mappingCompetitionId) {
                $byId = $competitionRepository->findOneBy(['id' => $mappingCompetitionId]);
                if ($byId instanceof Competition && $byId->getTeamId() === $mapping['teamId']) {
                    $target = $byId;
                }
            }
            $target ??= $competitionRepository->findOneBy(['teamId' => $mapping['teamId'], 'name' => mb_substr(trim($mapping['division']), 0, 180)]);

            $guard = null !== $group && $target instanceof Competition ? $this->pouleGuard($target, $group['rows'], $group['name']) : null;
            if (null !== $guard && $guard['blocking']) {
                $errors[] = $guard['message'];
                $blockedKeys[$group['divisionKey'] . '|' . $group['labelKey']] = true;
                continue;
            }
            $survivors[] = $mapping;
        }

        return $survivors;
    }

    /**
     * The poule guard (6.1): confront the division's DISTINCT opponents to the
     * paired poule's club list (whole-word normalized containment via
     * {@see containsClub} — « FIRMINY CHAZEAU-FAYOL AL - 1 » matches the poule
     * club « FIRMINY CHAZEAU-FAYOL AL »). > 50 % unknown → blocking; 1..50 % →
     * warning; competition without a paired opponent list → never checked
     * (today's behaviour). Null = nothing to report.
     *
     * @param list<array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}> $rows
     *
     * @return array{blocking: bool, message: string, unknown: list<string>}|null
     */
    public function pouleGuard(Competition $competition, array $rows, string $divisionName): ?array
    {
        $pouleClubs = $competition->getFfbbPouleOpponents();
        if (null === $pouleClubs || [] === $pouleClubs) {
            return null;
        }
        $needles = array_map(fn (string $club): string => $this->normalizeLabel($club), $pouleClubs);

        $unknown = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = $this->normalizeLabel($row['opponentLabel']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $known = false;
            foreach ($needles as $needle) {
                // The SAME whole-word join as the club-side detection — one idiom.
                if ('' !== $needle && $this->containsClub($row['opponentLabel'], $needle)) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                $unknown[] = $row['opponentLabel'];
            }
        }

        if ([] === $unknown) {
            return null;
        }
        $total = \count($seen);
        $blocking = \count($unknown) * 2 > $total;
        $pouleName = $competition->getFfbbPouleName() ?? '?';
        $message = $blocking
            ? \sprintf(
                'Division « %s » ignorée : %d adversaire(s) sur %d hors de la poule « %s » (%s) — mauvais fichier, mauvaise équipe ou mauvaise phase ? Données de la ligue — un écart se corrige auprès d\'elle.',
                $divisionName,
                \count($unknown),
                $total,
                $pouleName,
                implode(', ', \array_slice($unknown, 0, 5)),
            )
            : \sprintf(
                'Division « %s » : %d adversaire(s) sur %d hors de la poule « %s » (%s).',
                $divisionName,
                \count($unknown),
                $total,
                $pouleName,
                implode(', ', \array_slice($unknown, 0, 5)),
            );

        return ['blocking' => $blocking, 'message' => $message, 'unknown' => $unknown];
    }

    /**
     * Suggestion resolver (6.3): a file division label → a PAIRED competition to
     * pre-fill. A suggestion, never a resolution — the manager confirms in the
     * dialog (mapping stays the contract). Two étapes :
     *
     *  1. nom canonique : la clé normalisée de la division == le nom FFBB canonique
     *     d'une compétition appariée (deux appariées partageant la clé = ambigu → rien) ;
     *  2. PONT SIGNATURE (décision fondateur 2026-10-01) : quand le nom canonique ne
     *     matche pas, on réduit le libellé du FICHIER à sa signature FBI ({@see
     *     FbiDivisionSignature::fromCode}) et on la ponte aux compétitions appariées,
     *     réduites via leur nom canonique ({@see FbiDivisionSignature::fromFfbbRow}).
     *     Plusieurs équipes DISTINCTES pontées → ambigu → rien (jamais deviner entre
     *     équipes) ; plusieurs compétitions vers la MÊME équipe → la première par nom.
     *     Ferme le défaut « résolveur = nom canonique seul » : une division xlsx (code
     *     FBI « PNM ») retrouve la compétition appariée « Pré régionale masculine ».
     */
    public function buildSuggestionResolver(): callable
    {
        /** @var list<Competition> $competitions */
        $competitions = $this->entityManager->getRepository(Competition::class)->findBy([]);
        /** @var array<string, Competition|null> $byCanonical null = ambiguous */
        $byCanonical = [];
        /** @var list<array{competition: Competition, signature: array{level: string|null, division: int|null, gender: string|null, category: string|null, type: string}}> $bridgeCandidates */
        $bridgeCandidates = [];
        foreach ($competitions as $competition) {
            $canonical = $competition->getFfbbCompetitionName();
            if (null === $canonical) {
                continue;
            }
            $key = $this->normalizeLabel($canonical);
            $byCanonical[$key] = \array_key_exists($key, $byCanonical) ? null : $competition;
            $bridgeCandidates[] = [
                'competition' => $competition,
                'signature' => $this->divisionSignature->fromFfbbRow(null, null, null, $canonical),
            ];
        }

        return function (string $divisionName) use ($byCanonical, $bridgeCandidates): ?Competition {
            $byName = $byCanonical[$this->normalizeLabel($divisionName)] ?? null;
            if ($byName instanceof Competition) {
                return $byName;
            }

            $fileSignature = $this->divisionSignature->fromCode($divisionName);
            if (null === $fileSignature) {
                return null;
            }
            $matches = [];
            $teamIds = [];
            foreach ($bridgeCandidates as $candidate) {
                if ($this->divisionSignature->bridges($fileSignature, $candidate['signature'])) {
                    $matches[] = $candidate['competition'];
                    $teamIds[$candidate['competition']->getTeamId()] = true;
                }
            }
            if (1 !== \count($teamIds)) {
                return null;
            }
            usort($matches, static fn (Competition $a, Competition $b): int => strcmp($a->getName(), $b->getName()));

            return $matches[0];
        };
    }

    /**
     * Header labels AND team labels tolerate case/accents/spacing drift. Adaptateur
     * fin vers le foyer unique {@see VenueLabelNormalizer::normalize}, identique à
     * celui de {@see FbiFixtureImporter}.
     */
    private function normalizeLabel(string $value): string
    {
        return $this->labelNormalizer->normalize($value);
    }

    /**
     * Whole-word containment of the club needle in a team label. Adaptateur fin vers
     * {@see VenueLabelNormalizer::containsWord}, identique à celui de
     * {@see FbiFixtureImporter}.
     */
    private function containsClub(string $label, string $clubNeedle): bool
    {
        return $this->labelNormalizer->containsWord($label, $clubNeedle);
    }
}

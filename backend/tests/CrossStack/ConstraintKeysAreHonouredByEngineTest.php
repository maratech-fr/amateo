<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ConstraintConfigValidator;
use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * SEC-13 — CHAQUE clé de la liste blanche doit CHANGER le résultat du solveur.
 *
 * La liste blanche décide de ce qui entre en base : elle devient l'autorité sur
 * la qualité des données. Une autorité se garde, sinon elle se contente d'être
 * crue. Trois niveaux étaient possibles ; le fondateur a tranché pour le plus
 * exigeant (2026-08-07) :
 *
 * 1. comparer la liste à un grep du source Python — aveugle à la clé rangée dans
 *    la MAUVAISE famille, et cassant au premier refactor du moteur ;
 * 2. faire déclarer sa table au moteur et comparer — attrape la mauvaise famille,
 *    mais pas la clé DÉCLARÉE des deux côtés que le solveur n'applique pas. Ce
 *    placebo n'est pas théorique : ENG-18 et ENG-21 l'ont été ;
 * 3. **prouver l'effet** : pour chaque clé, un payload où elle est DÉCISIVE,
 *    envoyé au VRAI moteur, et le résultat doit changer.
 *
 * Le test est piloté PAR LA LISTE BLANCHE (`engineKeysByFamily()`), pas par une
 * énumération recopiée : ajouter une clé sans écrire son scénario fait rougir
 * `testEveryWhitelistedEngineKeyHasAProof`. On ne peut donc pas déclarer sans
 * prouver — c'est là que le garde est gardé.
 *
 * Chaque scénario porte son TÉMOIN : le même payload SANS la règle doit donner
 * un AUTRE résultat. Le témoin n'attend pas une valeur précise (le choix du
 * solveur entre deux options équivalentes ne nous regarde pas) mais exige la
 * DIFFÉRENCE — sans lui, un scénario où le solveur choisit déjà ce qu'on espère
 * passerait au vert sans rien prouver.
 *
 * Tourne dans le job CI dédié « Engine semantics » (groupe `contract`), avec le
 * moteur réel ; skip propre s'il est indisponible, comme `ContractSchemaTest`.
 */
#[Group('contract')]
final class ConstraintKeysAreHonouredByEngineTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';
    private const string V1 = '11111111-1111-4111-8111-111111111111';
    private const string V2 = '22222222-2222-4222-8222-222222222222';
    private const string TEAM = 't1';
    private const string COACH = 'c1';

    /**
     * Un scénario par clé moteur. Chacun : une grille à DEUX issues possibles,
     * une règle qui doit en imposer une, et ce qu'on observe du résultat.
     *
     * `grid` choisit la forme du problème (voir `payload`) :
     *   `only`   — UN seul créneau, lundi 18:00          → on observe le PLACEMENT
     *   `days`   — un gymnase, lundi et mercredi 18:00   → on observe le jour
     *   `hours`  — un gymnase, lundi 18:00 ET lundi 20:00 → on observe l'HEURE (format
     *              rendu par le moteur : `'18:00:00'`/`'20:00:00'`, mesuré — avec secondes)
     *   `venues` — deux gymnases, lundi 18:00            → on observe le gymnase
     *
     * ⚠ Pourquoi le STATUT pour les règles dures : mesuré le 2026-08-07, sur une
     * grille à deux issues équivalentes le solveur en choisit une spontanément —
     * et neuf scénarios sur dix-huit « passaient » en attendant justement celle-là.
     * Le témoin les a démasqués : sans la règle, le résultat était le MÊME. Une
     * règle dure se prouve donc en rendant l'unique option ILLICITE — l'équipe
     * ressort NON PLACÉE, ce qu'aucun arbitrage interne ne peut imiter. Les règles SOUPLES, elles, ne
     * rendent jamais infaisable : elles se prouvent sur le choix — en penchant
     * vers l'option que le solveur ne prend PAS spontanément.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>, 5: string}>
     *                                                                                                                 clé, grille, famille, ruleType, config, attendu AVEC la règle
     */
    public static function engineKeyProvider(): iterable
    {
        // ---- TIME : un SEUL créneau lundi 18:00, la règle doit l'interdire ---
        yield 'maxStartTime' => ['maxStartTime', 'only', 'TIME', 'HARD', ['maxStartTime' => '17:00'], 'non placée'];
        yield 'minStartTime' => ['minStartTime', 'only', 'TIME', 'HARD', ['minStartTime' => '19:00'], 'non placée'];
        // Borne la FIN : 18:00 + 90 min = 19:30, donc une borne à 19:00 l'exclut —
        // et c'est bien la FIN qui est lue, pas le début (18:00 < 19:00).
        yield 'maxEndTime' => ['maxEndTime', 'only', 'TIME', 'HARD', ['maxEndTime' => '19:00'], 'non placée'];
        // SOUPLE : une clé TIME prouvée AUSSI à un cran plus souple, pas seulement en dur (ALIGN-14
        // — une clé honorée en obligatoire pouvait être un placebo muet en PREFERRED). Le chemin
        // soft (add_preferred_time_bonus) lit min/maxStartTime. Sur la grille `hours` le solveur
        // choisit spontanément 20:00 (mesuré) ; la préférence « au plus tard 19:00 » doit le faire
        // pencher vers 18:00 — l'AUTRE heure, celle qu'il ne prend pas seul.
        yield 'maxStartTime souple' => ['maxStartTime', 'hours', 'TIME', 'PREFERRED', ['maxStartTime' => '19:00'], '18:00:00'];

        // ---- DAY : même grille, la règle doit exclure le lundi ---------------
        yield 'forbiddenDays' => ['forbiddenDays', 'only', 'DAY', 'HARD', ['forbiddenDays' => [1]], 'non placée'];
        yield 'allowedDays' => ['allowedDays', 'only', 'DAY', 'HARD', ['allowedDays' => [3]], 'non placée'];
        // « au moins une séance ces jours-là » : aucun créneau le mercredi, donc
        // insatisfaisable. C'est la sémantique de forcedDays, distincte d'allowedDays.
        yield 'forcedDays' => ['forcedDays', 'only', 'DAY', 'HARD', ['forcedDays' => [3]], 'non placée'];
        // SOUPLE : elle ne peut rien rendre infaisable. Deux jours licites, et
        // elle doit faire pencher vers le LUNDI — que le solveur ne choisit pas
        // spontanément sur cette grille (mesuré : il prend mercredi).
        yield 'preferredDays' => ['preferredDays', 'days', 'DAY', 'PREFERRED', ['preferredDays' => [1]], '1'];

        // ---- FACILITY : deux gymnases équivalents, même jour, même heure -----
        yield 'forcedVenueId' => ['forcedVenueId', 'venues', 'FACILITY', 'HARD', ['forcedVenueId' => self::V2], self::V2];
        yield 'forbiddenVenueId' => ['forbiddenVenueId', 'venues', 'FACILITY', 'HARD', ['forbiddenVenueId' => self::V1], self::V2];
        yield 'preferredVenueId' => ['preferredVenueId', 'venues', 'FACILITY', 'PREFERRED', ['preferredVenueId' => self::V2], self::V2];
        yield 'minAtVenueId' => ['minAtVenueId', 'venues', 'FACILITY', 'HARD', ['minAtVenueId' => self::V2, 'minAtVenueCount' => 1], self::V2];
        yield 'minAtVenueCount' => ['minAtVenueCount', 'venues', 'FACILITY', 'HARD', ['minAtVenueId' => self::V2, 'minAtVenueCount' => 1], self::V2];

        // (FACILITY_CAPACITY portait ici `venueId`/`maxTeams`, prouvées sur une
        // grille `shared`. La famille a été RETIRÉE le 2026-08-08 : plus de clés,
        // plus de scénarios — et le garde `testEveryWhitelistedEngineKeyHasAProof`
        // n'en réclame plus, puisqu'il lit la liste blanche.)

        // ---- COACH_AVAILABILITY : le coach requis ne peut pas lundi ----------
        yield 'unavailableDays' => ['unavailableDays', 'only', 'COACH_AVAILABILITY', 'HARD', ['unavailableDays' => [1]], 'non placée'];
        yield 'availableDays' => ['availableDays', 'only', 'COACH_AVAILABILITY', 'HARD', ['availableDays' => [3]], 'non placée'];
        // ESCALADE (ALIGN-14) : la disponibilité coach est TOUJOURS appliquée en dur — tout ruleType
        // non HARD/LOCK est escaladé (parsing.py ~305). C'est cette escalade qu'on prouve, pas un
        // cran souple : cette famille n'en a pas. Un `unavailableDays` PREFERRED bloque donc le
        // lundi comme un HARD → équipe non placée (mesuré : nslots=0 ; témoin sans règle = placée).
        yield 'unavailableDays escaladé' => ['unavailableDays', 'only', 'COACH_AVAILABILITY', 'PREFERRED', ['unavailableDays' => [1]], 'non placée'];
        // ⚠ Les deux fenêtres se prouvent À L'ENVERS : sans elles, « indisponible
        // lundi » bloque la journée entière (failed) ; avec, l'indisponibilité est
        // BORNÉE hors du créneau de 18:00, qui redevient jouable (completed). Un
        // scénario qui ajouterait la fenêtre à une journée déjà bloquée aurait le
        // même résultat avec et sans — il ne prouverait rien.
        yield 'fromTime' => ['fromTime', 'only', 'COACH_AVAILABILITY', 'HARD', ['unavailableDays' => [1], 'fromTime' => '20:00'], 'placée'];
        yield 'untilTime' => ['untilTime', 'only', 'COACH_AVAILABILITY', 'HARD', ['unavailableDays' => [1], 'untilTime' => '17:00'], 'placée'];
    }

    /**
     * LE garde du garde : aucune clé ne peut entrer dans la liste blanche sans
     * que quelqu'un ait prouvé qu'elle sert à quelque chose.
     */
    public function testEveryWhitelistedEngineKeyHasAProof(): void
    {
        $proven = [];
        foreach (self::engineKeyProvider() as $case) {
            $proven[$case[0]] = true;
        }

        $missing = [];
        foreach ((new ConstraintConfigValidator)->engineKeysByFamily() as $family => $keys) {
            foreach ($keys as $key) {
                if (!isset($proven[$key])) {
                    $missing[] = $family . '.' . $key;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'Ces clés sont autorisées à l\'écriture sans qu\'aucun scénario ne prouve qu\'elles changent le '
            . 'résultat du solveur. Une liste blanche non prouvée autorise des règles-placebo : le gestionnaire '
            . 'les saisit, l\'écran les affiche, et le planning les ignore (SEC-13). Écrire le scénario, ou '
            . 'retirer la clé.',
        );
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('engineKeyProvider')]
    public function testTheKeyChangesWhatTheSolverDoes(string $key, string $grid, string $family, string $ruleType, array $config, string $expected): void
    {
        $withRule = $this->observe($grid, $this->payload($grid, $family, $ruleType, $config));

        self::assertSame(
            $expected,
            $withRule,
            \sprintf(
                '« %s » est autorisée à l\'écriture mais le solveur n\'en tient pas compte : le gestionnaire '
                . 'saisirait une règle sans effet, affichée comme active. Soit le moteur a cessé de la lire, '
                . 'soit elle doit sortir de la liste blanche (SEC-13).',
                $key,
            ),
        );

        // TÉMOIN — sans la règle, le solveur ne fait PAS la même chose. On n'exige
        // pas une valeur précise (son arbitrage entre deux options équivalentes ne
        // nous regarde pas), seulement la différence : sans elle, un scénario où
        // il choisit déjà « le bon » créneau serait vert sans rien prouver.
        // Pour les fenêtres coach, le témoin n'est pas « aucune règle » mais « la
        // MÊME règle sans la fenêtre » : c'est la fenêtre qu'on prouve, pas
        // l'indisponibilité qui la porte.
        $witnessConfig = array_diff_key($config, ['fromTime' => true, 'untilTime' => true]);
        $witness = $witnessConfig === $config ? [] : $witnessConfig;

        self::assertNotSame(
            $withRule,
            $this->observe($grid, $this->payload($grid, $family, $ruleType, $witness)),
            \sprintf('témoin cassé pour « %s » : le solveur rend la même chose SANS la règle — le scénario ne prouve rien', $key),
        );
    }

    /** Ce que le scénario regarde du résultat : heure, jour, gymnase, ou nombre placé. */
    private function observe(string $grid, array $payload): string
    {
        $result = $this->solve($payload);

        // Grille `only` : la preuve est LE PLACEMENT — la règle rend l'unique
        // créneau illicite, l'équipe reste sur le carreau. ⚠ Le moteur ne rend PAS
        // `failed` dans ce cas (mesuré) : le modèle reste faisable, c'est
        // l'objectif qui pénalise le non-placé. Observer le statut aurait donné
        // « completed » des deux côtés — une preuve qui ne prouve rien.
        if ('only' === $grid) {
            return 0 === \count($result['slots']) ? 'non placée' : 'placée';
        }

        self::assertSame('completed', $result['status'], 'le scénario doit rester résoluble — sinon il ne compare rien');
        $slots = $result['slots'];

        if ('shared' === $grid) {
            return (string) \count($slots);
        }

        self::assertCount(1, $slots, 'ces grilles placent EXACTEMENT une séance — sinon l\'observation est ambiguë');

        return match ($grid) {
            'hours' => (string) $slots[0]['startTime'],
            'days' => (string) $slots[0]['dayOfWeek'],
            default => (string) $slots[0]['venueId'],
        };
    }

    /**
     * Le payload minimal de la grille demandée. Une règle vide = le témoin.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function payload(string $grid, string $family, string $ruleType, array $config): array
    {
        $teams = [$this->team(self::TEAM)];
        $coaches = [];
        $constraints = [];

        $venues = match ($grid) {
            // UN seul créneau : toute règle dure qui l'exclut rend `failed`.
            'only' => [$this->venue(self::V1, [[1, '18:00', 1]])],
            'days' => [$this->venue(self::V1, [[1, '18:00', 1], [3, '18:00', 1]])],
            // Deux heures le MÊME jour, capacité 1 chacune : la préférence horaire départage.
            'hours' => [$this->venue(self::V1, [[1, '18:00', 1], [1, '20:00', 1]])],
            'venues' => [$this->venue(self::V1, [[1, '18:00', 1]]), $this->venue(self::V2, [[1, '18:00', 1]])],
            // Un SEUL créneau de capacité 2 et deux équipes : sans rabot les deux
            // y tiennent, avec rabot une seule — le nombre placé fait la preuve.
            default => [$this->venue(self::V1, [[1, '18:00', 2]])],
        };
        if ('shared' === $grid) {
            $teams[] = $this->team('t2');
        }

        // COACH_AVAILABILITY n'a d'effet que si le coach est REQUIS par l'équipe :
        // c'est la contrainte legacy TEAM_COACH qui crée ce lien. Sans elle, la
        // disponibilité du coach ne contraint rien et le témoin serait faux.
        if ('COACH_AVAILABILITY' === $family) {
            $coaches[] = ['id' => self::COACH, 'firstName' => 'Co', 'lastName' => 'Ach', 'isActive' => true];
            $constraints[] = [
                'id' => 'link', 'type' => 'TEAM_COACH', 'teamId' => self::TEAM,
                'metadata' => ['coachId' => self::COACH, 'role' => 'MAIN', 'isRequired' => true],
                'isActive' => true,
            ];
        }

        if ([] !== $config) {
            $constraints[] = [
                'id' => 'rule',
                'scope' => 'COACH_AVAILABILITY' === $family ? 'COACH' : 'TEAM',
                'scopeTargetId' => 'COACH_AVAILABILITY' === $family ? self::COACH : self::TEAM,
                'family' => $family,
                'ruleType' => $ruleType,
                'name' => 'preuve ' . $family,
                'config' => $config,
                'sortOrder' => 0,
                'isActive' => true,
            ];
        }

        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-proof',
            'seasonId' => 'season-proof',
            'solverSeed' => 42,
            'teams' => $teams,
            'venues' => $venues,
            'coaches' => $coaches,
            'constraints' => $constraints,
            'slotTemplates' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function team(string $id): array
    {
        return ['id' => $id, 'name' => strtoupper($id), 'sportCategoryId' => 'cat', 'priorityTierId' => 3, 'sessionsPerWeek' => 1, 'isActive' => true];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}> $slots jour, heure, capacité
     *
     * @return array<string, mixed>
     */
    private function venue(string $id, array $slots): array
    {
        return [
            'id' => $id, 'name' => 'V-' . substr($id, 0, 4), 'isActive' => true,
            'trainingSlots' => array_map(
                static fn (array $s): array => ['dayOfWeek' => $s[0], 'startTime' => $s[1], 'durationMinutes' => 90, 'capacity' => $s[2]],
                $slots,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function solve(array $payload): array
    {
        $client = HttpClient::create(['timeout' => 30]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());

            return $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }
}

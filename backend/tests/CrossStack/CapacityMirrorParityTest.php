<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\PayloadCapacityMirror;
use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * P3-19 — LE GARDE CROSS-STACK du miroir d'algèbre (décision fondateur 2026-08-07 :
 * épingler la parité plutôt que déplacer le calcul côté moteur).
 *
 * `PayloadCapacityMirror` (PHP) réplique une partie de l'algèbre de créneaux du
 * moteur pour que le récap annonce des chiffres justes. Deux copies de la même
 * logique = un jour, quelqu'un change l'une sans l'autre et LE RÉCAP MENT EN
 * SILENCE (sept divergences trouvées en deux rounds de revue #341 — le risque
 * n'est pas théorique). Ce test envoie les MÊMES payloads au VRAI moteur et
 * vérifie que les deux copies rendent le même verdict.
 *
 * Chaque scénario est construit pour que les règles répliquées SOIENT décisives :
 * un payload où elles ne jouent pas passerait au vert quelle que soit l'algèbre.
 *
 * Même régime que ContractSchemaTest : moteur réel sur le réseau docker, skip
 * propre s'il est indisponible (le groupe `contract` tourne là où il existe).
 */
#[Group('contract')]
final class CapacityMirrorParityTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';

    private PayloadCapacityMirror $mirror;

    /**
     * LA règle du miroir — dédup par triplet. (Le rabot `FACILITY_CAPACITY` en était
     * une seconde ; la famille a été retirée le 2026-08-08, aucun chemin UI ne la
     * créait.).
     *
     * Grille : le même triplet (V, lundi, 18:00) déclaré DEUX fois à capacité 3
     * (le moteur écrase, il n'additionne pas) + un second créneau (mercredi,
     * capacité 3). Miroir attendu : 3 + 3 = 6 places — PAS 3+3+3=9 (sans dédup).
     *
     * Face moteur : 6 équipes à 1 séance → il doit en placer EXACTEMENT `offer`.
     * S'il place plus, le miroir sous-estime (fausses alertes de sous-capacité) ;
     * s'il place moins, le miroir sur-estime (le récap promet des places qui
     * n'existent pas). Les deux sens rougissent.
     */
    public function testOfferMatchesWhatTheEngineActuallyPlaces(): void
    {
        $payload = $this->basePayload(
            teams: $this->teams(6),
            venues: [[
                'id' => 'v1', 'name' => 'V1', 'isActive' => true,
                'trainingSlots' => [
                    ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 3],
                    ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 3], // doublon exact
                    ['dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 3],
                ],
            ]],
        );

        $offer = $this->mirror->offer($payload);
        self::assertSame(6, $offer, 'le miroir PHP doit dédupliquer le triplet (2 lignes au même créneau = 1 place-set)');

        $result = $this->solve($payload);
        self::assertSame('completed', $result['status']);
        self::assertCount(
            $offer,
            $result['slots'],
            'PARITÉ ROMPUE : le moteur ne place pas le nombre de séances que le miroir annonce — '
            . 'son algèbre de créneaux a changé (dédup par triplet). '
            . 'Aligner App\Service\PayloadCapacityMirror, sinon le récap ment.',
        );
    }

    /**
     * Règle « saturation » — un pin HARD ferme son triplet à TOUT le monde et ne
     * compte PAS pour les « au moins » de sa propre équipe.
     *
     * 2 équipes exigent chacune 1 séance à V1 (2 places demandées) ; V1 a 2 créneaux
     * mais l'un est épinglé par une TROISIÈME équipe → 1 place libre. Le miroir dit
     * saturé (2 > 1) ; le moteur doit être INFEASIBLE. Témoin : sans le pin, les
     * deux verdicts basculent ensemble (rien à signaler / completed).
     */
    public function testSaturationVerdictMatchesEngineFeasibility(): void
    {
        $venues = [[
            'id' => 'v1', 'name' => 'V1', 'isActive' => true,
            'trainingSlots' => [
                ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
                ['dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
            ],
        ]];
        $minConstraints = [
            $this->minAtVenue('t1', 'v1'),
            $this->minAtVenue('t2', 'v1'),
        ];
        $pin = [[
            'id' => 'pin-1', 'teamId' => 't3', 'venueId' => 'v1', 'coachId' => null,
            'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90,
            'lockLevel' => 'HARD', 'pendingConstraintSuggestion' => null,
        ]];

        $saturatedPayload = $this->basePayload(teams: $this->teams(3), venues: $venues, constraints: $minConstraints, slotTemplates: $pin);
        $saturation = $this->mirror->venueMinimumSaturation($saturatedPayload);
        self::assertCount(1, $saturation, 'le miroir doit voir la saturation (2 minimums pour 1 place libre)');
        self::assertSame(['demand' => 2, 'free' => 1], ['demand' => $saturation[0]['demand'], 'free' => $saturation[0]['free']]);
        self::assertSame(
            'failed',
            $this->solve($saturatedPayload)['status'],
            'PARITÉ ROMPUE : le miroir annonce un INFEASIBLE certain mais le moteur a résolu — '
            . 'son algèbre des minimums ou des verrous a changé (pins comptés ? triplet non fermé ?). '
            . 'Aligner App\Service\PayloadCapacityMirror, sinon le récap bloque à tort.',
        );

        // TÉMOIN — sans le pin, les DEUX côtés doivent basculer ensemble : sans lui,
        // « failed » ci-dessus pourrait venir d'un tout autre facteur du payload.
        $freePayload = $this->basePayload(teams: $this->teams(3), venues: $venues, constraints: $minConstraints);
        self::assertSame([], $this->mirror->venueMinimumSaturation($freePayload));
        self::assertSame('completed', $this->solve($freePayload)['status']);
    }

    /**
     * Le COMPORTEMENT qui justifie le bloqueur « réservation orpheline » (P4-44) :
     * un pin HARD hors grille n'est PAS refusé par le moteur — il est ÉMIS tel quel,
     * statut `completed`. C'est parce que le moteur se tait que le backend doit
     * bloquer AVANT. Si ce test rougit un jour (le moteur se met à refuser), le
     * bloqueur backend est à réviser — il ferait double emploi.
     */
    public function testEngineSilentlyPlacesAnOffGridPinWhichIsWhyTheBackendBlocks(): void
    {
        $payload = $this->basePayload(
            teams: $this->teams(1),
            venues: [[
                'id' => 'v1', 'name' => 'V1', 'isActive' => true,
                'trainingSlots' => [['dayOfWeek' => 2, 'startTime' => '18:30', 'durationMinutes' => 90, 'capacity' => 1]],
            ]],
            slotTemplates: [[
                'id' => 'pin-mort', 'teamId' => 't1', 'venueId' => 'v1', 'coachId' => null,
                'dayOfWeek' => 2, 'startTime' => '18:00', 'durationMinutes' => 90, // horaire MORT
                'lockLevel' => 'HARD', 'pendingConstraintSuggestion' => null,
            ]],
        );

        $result = $this->solve($payload);

        self::assertSame('completed', $result['status'], 'le moteur ne refuse PAS un pin hors grille — mesuré, et épinglé ici');
        $emitted = array_column($result['slots'], 'startTime');
        self::assertContains('18:00:00', $emitted, 'la séance sort à l\'horaire MORT : la porte fermée que le bloqueur backend (P4-44) existe pour empêcher');
    }

    /**
     * P4-97 — un plancher « au moins N à V » SATISFAIT PAR LES VERROUS de l'équipe :
     * le crédit doit exister DES DEUX CÔTÉS, falsifiable dans les deux sens.
     *
     * t1 exige 2 séances à V1 et y est épinglée 2 JOURS DISTINCTS (lun + mer) : les
     * réservations saturent déjà le plancher. Le miroir doit rendre `[]` (aucun bloqueur)
     * ET le moteur doit rendre `completed` SANS diagnostic `venue_minimum_unreachable`.
     *
     * Les deux assertions sur le MÊME payload font le garde bidirectionnel :
     * - crédit retiré CÔTÉ PHP → la demande retombe à 2 > 1 place libre (jour 3) →
     *   `venueMinimumSaturation` rend une saturation → ROUGE ;
     * - crédit retiré CÔTÉ MOTEUR → `effective_min` redevient 2 sur le seul jour libre
     *   restant → `venue_minimum_unreachable` reparaît dans les diagnostics → ROUGE.
     *
     * TÉMOIN : épinglée UNE seule fois (plancher non saturé), les deux côtés basculent
     * ensemble — le miroir signale et le moteur émet l'ERROR.
     */
    public function testMinimumSatisfiedByOwnLocksBlocksNothingOnEitherSide(): void
    {
        // Grille V1 réduite au SEUL lundi : c'est ce qui rend le témoin décisif — retirer un
        // pin ne libère aucun jour de grille, donc le plancher redevient inatteignable des
        // deux côtés. Le second pin (mercredi) est hors grille, comme une réservation.
        $venues = [[
            'id' => 'v1', 'name' => 'V1', 'isActive' => true,
            'trainingSlots' => [
                ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
            ],
        ]];
        $team = [[
            'id' => 't1', 'name' => 'T1', 'sportCategoryId' => 'cat-1',
            'priorityTierId' => 3, 'sessionsPerWeek' => 2, 'isActive' => true,
        ]];
        $minTwo = [$this->minAtVenue('t1', 'v1', count: 2)];

        // Deux pins HARD de t1 à V1, DEUX jours distincts → plancher (2) saturé par les verrous.
        $satisfiedPayload = $this->basePayload(
            teams: $team,
            venues: $venues,
            constraints: $minTwo,
            slotTemplates: [$this->pin('t1', 'v1', 1, '18:00'), $this->pin('t1', 'v1', 3, '18:00')],
        );

        self::assertSame(
            [],
            $this->mirror->venueMinimumSaturation($satisfiedPayload),
            'PARITÉ ROMPUE (PHP) : une équipe déjà servie par ses verrous ne doit RIEN saturer — '
            . 'le crédit des jours verrouillés manque dans App\Service\PayloadCapacityMirror.',
        );
        $satisfied = $this->solve($satisfiedPayload);
        self::assertSame('completed', $satisfied['status']);
        self::assertNotContains(
            'venue_minimum_unreachable',
            array_column($satisfied['diagnostics'] ?? [], 'type'),
            'PARITÉ ROMPUE (moteur) : effective_min doit créditer les jours verrouillés — '
            . 'sinon add_venue_minimum_constraints ré-émet un faux venue_minimum_unreachable.',
        );

        // TÉMOIN — un seul pin : le plancher (2) n'est plus saturé, les DEUX côtés le disent.
        $witnessPayload = $this->basePayload(
            teams: $team,
            venues: $venues,
            constraints: $minTwo,
            slotTemplates: [$this->pin('t1', 'v1', 1, '18:00')],
        );
        self::assertCount(
            1,
            $this->mirror->venueMinimumSaturation($witnessPayload),
            'témoin : avec un seul pin, demande=1 restante pour 0 place libre (le lundi est verrouillé) → saturé',
        );
        self::assertContains(
            'venue_minimum_unreachable',
            array_column($this->solve($witnessPayload)['diagnostics'] ?? [], 'type'),
            'témoin : le moteur doit à son tour signaler l\'inatteignabilité avec un seul pin',
        );
    }

    /**
     * PR-4 — la DEMANDE est BLOC-AWARE : une séance de bloc réunit N membres sur UNE place, donc
     * (n_membres − 1) × commonSessions sortent de la demande. Falsifiable dans les deux sens sur le
     * VRAI moteur : le bloc replie la demande EXACTEMENT comme il fait TENIR le roster sur une seule
     * place. (La sous-capacité seule est SOFT — le moteur laisse une séance non placée sans échouer ;
     * ce n'est donc PAS le statut qu'on observe, mais le NOMBRE de séances RÉELLEMENT placées.).
     *
     * 2 équipes à 1 séance pour 1 SEULE place. Avec un bloc {t1,t2} commonSessions=1, le moteur
     * CO-PLACE les deux sur cette place (2 séances placées) — leur demande repliée (1) tient dans
     * l'offre (1). SANS le bloc, une seule tient (1 séance placée, l'autre à la dérive) — la demande
     * brute (2) dépasse l'offre. Le miroir doit rendre 1 avec bloc, 2 sans : sinon le récap ment.
     */
    public function testDemandIsBlocAwareLikeTheEngine(): void
    {
        $venues = [[
            'id' => 'v1', 'name' => 'V1', 'isActive' => true,
            'trainingSlots' => [
                ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
            ],
        ]];
        $blockPayload = $this->basePayload(
            teams: $this->teams(2),
            venues: $venues,
            sharedBlocks: [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]],
        );

        self::assertSame(1, $this->mirror->demand($blockPayload), 'le bloc replie la demande : 2 − (2−1)×1 = 1');
        $blockResult = $this->solve($blockPayload);
        self::assertSame('completed', $blockResult['status']);
        self::assertCount(
            2,
            $blockResult['slots'],
            'PARITÉ ROMPUE : le bloc fond ses membres sur UNE place — le moteur doit CO-PLACER les deux '
            . '(2 séances sur l\'unique créneau). Son algèbre de bloc a changé : aligner App\Service\PayloadCapacityMirror::demand.',
        );

        // TÉMOIN — sans le bloc, les deux séances se disputent la place unique : une seule tient.
        // Les DEUX côtés basculent ensemble (miroir = 2 > offre 1 ; moteur ne place qu'UNE des deux),
        // preuve que c'est bien le bloc qui replie la demande, pas un artefact du payload.
        $rawPayload = $this->basePayload(teams: $this->teams(2), venues: $venues);
        self::assertSame(2, $this->mirror->demand($rawPayload), 'sans bloc : Σ sessionsPerWeek brut');
        self::assertCount(1, $this->solve($rawPayload)['slots'], 'sans bloc, l\'unique place ne tient qu\'une séance');
    }

    protected function setUp(): void
    {
        $this->mirror = new PayloadCapacityMirror;
    }

    /**
     * @return array<string, mixed> un pin HARD minimal au contrat
     */
    private function pin(string $teamId, string $venueId, int $day, string $startTime): array
    {
        return [
            'id' => \sprintf('pin-%s-%s-%d', $teamId, $venueId, $day), 'teamId' => $teamId, 'venueId' => $venueId,
            'coachId' => null, 'dayOfWeek' => $day, 'startTime' => $startTime, 'durationMinutes' => 90,
            'lockLevel' => 'HARD', 'pendingConstraintSuggestion' => null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> statut + slots du VRAI moteur (skip si indisponible)
     */
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

    /** @return list<array<string, mixed>> */
    private function teams(int $count): array
    {
        return array_map(static fn (int $i): array => [
            'id' => 't' . $i,
            'name' => 'T' . $i,
            'sportCategoryId' => 'cat-1',
            'priorityTierId' => 3,
            'sessionsPerWeek' => 1,
            'isActive' => true,
        ], range(1, $count));
    }

    /** @return array<string, mixed> */
    private function minAtVenue(string $teamId, string $venueId, int $count = 1): array
    {
        return [
            'id' => 'min-' . $teamId, 'scope' => 'TEAM', 'scopeTargetId' => $teamId,
            'family' => 'FACILITY', 'ruleType' => 'HARD', 'name' => \sprintf('au moins %d à %s', $count, $venueId),
            'config' => ['minAtVenueId' => $venueId, 'minAtVenueCount' => $count], 'sortOrder' => 0, 'isActive' => true,
        ];
    }

    /**
     * Le payload MINIMAL au contrat backend⇄engine — les clés que le moteur lit
     * vraiment (version DÉRIVÉE de la source, pas d'un littéral qui dériverait).
     * La FORME complète est gardée par ContractSchemaTest ; ici le sujet est
     * l'ALGÈBRE, sur des payloads où elle est décisive.
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     * @param list<array<string, mixed>> $slotTemplates
     * @param list<array<string, mixed>> $sharedBlocks
     *
     * @return array<string, mixed>
     */
    private function basePayload(array $teams, array $venues, array $constraints = [], array $slotTemplates = [], array $sharedBlocks = []): array
    {
        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-parity',
            'seasonId' => 'season-parity',
            'solverSeed' => 42,
            'teams' => $teams,
            'venues' => $venues,
            'coaches' => [],
            'constraints' => $constraints,
            'slotTemplates' => $slotTemplates,
            'sharedBlocks' => $sharedBlocks,
        ];
    }
}

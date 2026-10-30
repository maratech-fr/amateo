<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DiagnosticMessageBuilder;
use PHPUnit\Framework\TestCase;

final class DiagnosticMessageBuilderTest extends TestCase
{
    private DiagnosticMessageBuilder $builder;

    public function testPrefersPreciseEngineMessageForConflict(): void
    {
        // The engine (S5) already names venue + teams + day + time. That precise
        // message must reach the manager, not be flattened by a local rebuild.
        $diagnostic = [
            'type' => 'conflict',
            'venueId' => 'v1',
            'message' => 'Le gymnase Léo Lagrange accueille 2 équipes en même temps le mardi 18:00–19:30 alors que sa capacité est de 1 : Séniors M1, U15 F.',
        ];

        $result = $this->builder->build($diagnostic, [], [], ['v1' => 'Léo Lagrange']);

        self::assertStringContainsString('mardi', $result);
        self::assertStringContainsString('Séniors M1', $result);
        self::assertStringContainsString('U15 F', $result);
    }

    public function testFallsBackToLocalisedMessageWhenEngineMessageMissing(): void
    {
        $diagnostic = ['type' => 'conflict', 'venueId' => 'v1'];

        $result = $this->builder->build($diagnostic, [], [], ['v1' => 'Gymnase A']);

        self::assertStringContainsString('Gymnase A', $result);
        self::assertStringContainsString('plusieurs équipes', $result);
    }

    public function testSoftLockMovedAlwaysRebuiltLocally(): void
    {
        // Engine still emits a raw English message for this type → rebuild locally.
        $diagnostic = [
            'type' => 'soft_lock_moved',
            'teamId' => 't1',
            'venueId' => 'v1',
            'message' => 'The preferred slot for team t1 at v1 on day 2 was moved.',
        ];

        $result = $this->builder->build($diagnostic, ['t1' => 'SM1'], [], ['v1' => 'Gymnase A']);

        self::assertStringContainsString('SM1', $result);
        self::assertStringContainsString('déplacé', $result);
        self::assertStringNotContainsString('preferred slot', $result);
    }

    public function testSoftLockMovedNamesTheDayAndTimeWhenTheEngineEmitsThem(): void
    {
        // Champs structurés (jour + heure + durée) → la parenthèse dit LEQUEL des créneaux a bougé.
        $result = $this->builder->build(
            [
                'type' => 'soft_lock_moved',
                'teamId' => 't1',
                'venueId' => 'v1',
                'dayOfWeek' => 2,
                'startTime' => '18:00',
                'durationMinutes' => 90,
                'message' => '',
            ],
            ['t1' => 'SM1'],
            [],
            ['v1' => 'Gymnase A'],
        );

        self::assertSame(
            'Le créneau préféré de SM1 (Gymnase A, mardi de 18:00 à 19:30) a été déplacé par le solveur pour un meilleur ajustement global.',
            $result,
        );
    }

    public function testSoftLockMovedWithoutCoordinatesIsUnchanged(): void
    {
        // Tolérance : sans les champs, le message reste celui d'avant, à l'octet près.
        $result = $this->builder->build(
            ['type' => 'soft_lock_moved', 'teamId' => 't1', 'venueId' => 'v1', 'message' => ''],
            ['t1' => 'SM1'],
            [],
            ['v1' => 'Gymnase A'],
        );

        self::assertSame(
            'Le créneau préféré de SM1 (Gymnase A) a été déplacé par le solveur pour un meilleur ajustement global.',
            $result,
        );
    }

    public function testUnusedSlotRebuiltInFrench(): void
    {
        // Engine sends a raw English "…: no team assigned" message → rebuild locally.
        $diagnostic = [
            'type' => 'unused_slot',
            'venueId' => 'v1',
            'dayOfWeek' => 3,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'message' => 'Gym Foo Wednesday 18:00-19:30: no team assigned',
        ];

        $result = $this->builder->build($diagnostic, [], [], ['v1' => 'Gymnase Foo']);

        self::assertStringContainsString('Gymnase Foo', $result);
        self::assertStringContainsString('mercredi', $result);
        self::assertStringContainsString('18:00', $result);
        self::assertStringContainsString('19:30', $result);
        self::assertStringNotContainsString('no team assigned', $result);
    }

    public function testUnusedSlotSundayUsesFrenchDayName(): void
    {
        // ISO dayOfWeek 7 = dimanche (the engine mislabels it as "7").
        $result = $this->builder->build(
            ['type' => 'unused_slot', 'venueId' => 'v1', 'dayOfWeek' => 7, 'startTime' => '10:00', 'durationMinutes' => 60],
            [],
            [],
            ['v1' => 'Gymnase A'],
        );

        self::assertStringContainsString('dimanche', $result);
        self::assertStringNotContainsString('(7', $result);
    }

    // ── Lot 3B PR B — l'ORIGINE d'une contrainte citée par un diagnostic de bloc ────────────────

    public function testSharedBlockNotHonoredAppendsSeasonInheritedOrigin(): void
    {
        // Le moteur nomme le groupe ; le backend AJOUTE l'origine. La contrainte citée n'est PAS
        // datée (calendarEntryId NULL = héritée de la saison) → le texte le dit et propose de la
        // désactiver pour la période.
        $diagnostic = [
            'type' => 'shared_block_not_honored',
            'message' => 'Groupe U9F1, U9F2 : aucun créneau où au moins un coach du groupe est disponible.',
            'causes' => [
                ['kind' => 'coach_unavailability', 'constraintId' => 'av-1', 'label' => 'Coach X · indispo lundi-jeudi', 'count' => 2],
            ],
        ];

        $result = $this->builder->build($diagnostic, [], [], [], ['av-1' => true]);

        self::assertStringContainsString('Groupe U9F1, U9F2', $result);
        self::assertStringContainsString('Coach X · indispo lundi-jeudi', $result);
        self::assertStringContainsString('héritée du planning de saison — désactivable pour cette période', $result);
    }

    public function testSharedBlockNotHonoredAppendsPeriodPostedOrigin(): void
    {
        // La contrainte citée EST datée (calendarEntryId non NULL = posée pour la période) → origine
        // « posée pour cette période », jamais « héritée ».
        $diagnostic = [
            'type' => 'shared_block_not_honored',
            'message' => 'Groupe U11M1, U11M2 : aucun créneau où au moins un coach du groupe est disponible.',
            'causes' => [
                ['kind' => 'coach_unavailability', 'constraintId' => 'av-dated', 'label' => 'Coach Y · indispo vacances', 'count' => 1],
            ],
        ];

        $result = $this->builder->build($diagnostic, [], [], [], ['av-dated' => false]);

        self::assertStringContainsString('Coach Y · indispo vacances', $result);
        self::assertStringContainsString('posée pour cette période', $result);
        self::assertStringNotContainsString('héritée du planning de saison', $result);
    }

    public function testSharedBlockNotHonoredKeepsEngineMessageWhenCauseHasNoResolvableOrigin(): void
    {
        // Cause sans libellé (dégradée) ⇒ aucune phrase d'origine ajoutée ; le message moteur reste
        // tel quel — jamais un groupe accusé avec une origine fantôme.
        $diagnostic = [
            'type' => 'shared_block_not_honored',
            'message' => 'Groupe A, B : aucun créneau où au moins un coach du groupe est disponible.',
            'causes' => [
                ['kind' => 'coach_unavailability', 'constraintId' => null, 'label' => null, 'count' => 3],
            ],
        ];

        $result = $this->builder->build($diagnostic, [], [], [], []);

        self::assertSame('Groupe A, B : aucun créneau où au moins un coach du groupe est disponible.', $result);
        self::assertStringNotContainsString('Contrainte en cause', $result);
    }

    // ── Réservation souveraine (besoin fondateur 2026-10-30) — le GABARIT FR d'une règle sacrifiée ──

    public function testRuleSacrificedComposesAFrenchMessageNamingTheRuleAndTheSovereignReservation(): void
    {
        // « La réservation gagne envers tout. » Quand un verrou HARD rend une règle dure
        // insatisfiable, le moteur émet un diagnostic `rule_sacrificed` ; le backend COMPOSE le texte
        // gestionnaire (gabarit, pas la phrase exacte) : il NOMME l'équipe + la règle sacrifiée (son
        // libellé, jamais un identifiant interne) et DIT que c'est une réservation/un verrou qui a
        // gagné. Message moteur vide ⇒ repli composé localement (patron `shared_block_not_honored`).
        $diagnostic = [
            'type' => 'rule_sacrificed',
            'severity' => 'WARNING',
            'teamId' => 't1',
            'message' => '',
            'causes' => [
                ['kind' => 'coach_unavailability', 'constraintId' => 'av-1', 'label' => 'Indispo du vendredi', 'count' => 1],
            ],
        ];

        $result = $this->builder->build($diagnostic, ['t1' => 'SM1'], [], []);

        self::assertNotSame('Diagnostic inconnu.', $result, 'le gabarit d\'une règle sacrifiée doit exister, pas retomber sur le défaut');
        self::assertStringContainsString('SM1', $result, 'le message doit NOMMER l\'équipe concernée');
        self::assertStringContainsString('Indispo du vendredi', $result, 'le message doit NOMMER la règle sacrifiée (son libellé)');
        self::assertMatchesRegularExpression('/r[ée]servation|verrou/i', $result, 'le message doit dire qu\'une réservation/un verrou a gagné');
    }

    public function testBlockedByReservationComposesAMessageNamingTeamRuleAndReservationWithoutClaimingARelaxation(): void
    {
        // La réservation d'une AUTRE équipe prend la seule case compatible : la règle TIENT, la séance
        // reste non placée (décision fondateur 2026-10-30). Le gabarit nomme l'équipe, la règle et la
        // réservation, et n'affirme jamais qu'une règle a été relâchée.
        $result = $this->builder->build(
            [
                'type' => 'blocked_by_reservation',
                'severity' => 'WARNING',
                'teamId' => 't1',
                'message' => '',
                'causes' => [['kind' => 'day_forced', 'constraintId' => 'd-1', 'label' => 'Vendredi imposé', 'count' => 1]],
            ],
            ['t1' => 'SM1'],
        );

        self::assertNotSame('Diagnostic inconnu.', $result, 'le gabarit doit exister');
        self::assertStringContainsString('SM1', $result);
        self::assertStringContainsString('Vendredi imposé', $result);
        self::assertMatchesRegularExpression('/r[ée]servation/i', $result);
        self::assertDoesNotMatchRegularExpression('/relâch|sacrifi/i', $result, 'la règle n\'est pas relâchée');
    }

    protected function setUp(): void
    {
        $this->builder = new DiagnosticMessageBuilder;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CoachGender;
use App\EventListener\EmailSignatureListener;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use App\Service\BrandAssets;
use App\Service\ConflictCoachMailBuilder;
use App\Service\ProductIdentity;
use App\Storage\LogoStorage;
use App\Tests\Integration\Api\ConflictCoachMailApiTest;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Les deux e-mails « conflits aux coachs » disent mot pour mot ce que le plan fixe : une liste à sa
 * date, indicative, sans décision ni suivi (« il ne pourra pas être surpris »). Le mail « conflits »
 * porte ses tuiles en fragment HTML (enveloppé par la carte de marque) et la même liste en texte ;
 * le mail « plus aucun conflit » est un texte seul.
 *
 * Club fictif « Les Aiglons » (libellé « AIGLONS »), Nadia gestionnaire, Karim B. (principal U15,
 * joue en SM2), saison « 2025-2026 », aujourd'hui = 03/11/2026 pour le club.
 *
 * Le jumeau d'envoi réel (collecteur, destinataires) : {@see ConflictCoachMailApiTest}.
 */
#[Group('phase1')]
final class ConflictCoachMailBuilderTest extends TestCase
{
    private Club $club;

    private Season $season;

    private Coach $karim;

    private Team $u15;

    private Team $sm2;

    private Team $u13a;

    private Venue $nord;

    // ── Mail « conflits » : enveloppe ──

    /** Sujet au singulier / pluriel, expéditeur « Nadia (club) via produit », destinataire, club marqué, aucun bouton. */
    public function testTheConflictsMailIsAddressedFromTheSenderOnBehalfOfTheClub(): void
    {
        $one = $this->conflicts([$this->matchMatch()]);
        $two = $this->conflicts([$this->matchMatch(), $this->coachUnavailable(5)]);

        self::assertSame('Un conflit vous concerne — AIGLONS', $one->getSubject());
        self::assertSame('2 conflits vous concernent — AIGLONS', $two->getSubject());
        self::assertSame('karim@aiglons.test', $one->getTo()[0]->getAddress());
        self::assertSame('Nadia (AIGLONS) via ' . new ProductIdentity()->name(), $one->getFrom()[0]->getName());
        self::assertSame($this->club->getId(), $one->getHeaders()->getHeaderBody(ClubMailMetadata::CLUB_ID_HEADER));
        self::assertTrue($one->getHeaders()->has(EmailTemplateRenderer::HTML_FRAGMENT_HEADER), 'les tuiles voyagent en fragment HTML');
        self::assertFalse($one->getHeaders()->has(EmailTemplateRenderer::CTA_URL_HEADER), 'aucun bouton : rien à faire, le mail informe');
    }

    /** Le texte salue, dit « à titre indicatif », nomme la saison et la date, puis rappelle que le planning peut bouger. */
    public function testTheConflictsMailSaysWhatItIsAndWhatItIsNot(): void
    {
        $text = (string) $this->conflicts([$this->matchMatch()])->getTextBody();

        self::assertStringStartsWith('Bonjour Karim,', $text);
        self::assertStringContainsString('Nadia (AIGLONS) vous informe, à titre indicatif, des conflits de personnes sur un match, datés d\'aujourd\'hui ou à venir, qui vous concernent pour la saison 2025-2026, au 03/11/2026.', $text);
        self::assertStringContainsString('Cette liste vaut à la date ci-dessus ; le planning peut encore bouger. Les matchs sans heure connue ne sont pas vérifiés.', $text);
    }

    /** Un conflit traité part sans son statut ni sa note : le mail ne porte aucune décision. */
    public function testNeitherTreatmentStatusNorNoteReachesTheCoach(): void
    {
        $treated = $this->matchMatch() + ['resolution' => ['status' => 'RESOLVED_INTERNALLY', 'note' => 'NOTE-INTERNE-DU-BUREAU', 'updatedAt' => '2026-11-01T10:00:00+00:00']];
        $email = $this->conflicts([$treated]);
        $all = (string) $email->getTextBody() . (string) $email->getHtmlBody();

        self::assertStringNotContainsString('NOTE-INTERNE-DU-BUREAU', $all);
        self::assertStringNotContainsString('RESOLVED', $all);
    }

    // ── Mail « conflits » : tuiles ──

    /** Chaque famille a son bandeau « famille — personne · jour date », et les tuiles sont triées par date. */
    public function testEachFamilyHasItsBannerAndTilesAreSortedByDate(): void
    {
        $email = $this->conflicts([$this->matchMatch(), $this->matchTraining(), $this->coachUnavailable(3)]);
        $text = (string) $email->getTextBody();

        self::assertStringContainsString('Personne en double — Karim B. · samedi 14/03', $text);
        self::assertStringContainsString('Match × entraînement — Karim B. · vendredi 13/03', $text);
        self::assertStringContainsString('Coach principal indisponible — Karim B. · samedi 14/03', $text);
        self::assertLessThan(strpos($text, 'Personne en double'), strpos($text, 'Match × entraînement'), 'le vendredi 13/03 passe avant le samedi 14/03');
        self::assertStringContainsString('Personne en double', (string) $email->getHtmlBody(), 'le fragment porte les mêmes tuiles');
    }

    /** Le mot de gravité est propre au mail : 3 « Important », 4 « À regarder », 5 « À surveiller ». */
    public function testTheSeverityWordIsTheMailsOwn(): void
    {
        self::assertStringContainsString('Important', (string) $this->conflicts([$this->coachUnavailable(3)])->getHtmlBody());
        self::assertStringContainsString('À regarder', (string) $this->conflicts([$this->coachUnavailable(4)])->getHtmlBody());
        self::assertStringContainsString('À surveiller', (string) $this->conflicts([$this->coachUnavailable(5)])->getHtmlBody());
    }

    /** Domicile et extérieur : équipe (rôle), lieu, adversaire, horaires, départ/retour quand le trajet est connu. */
    public function testMatchSidesSayTheTeamRoleWhereWhoAndWhen(): void
    {
        $text = (string) $this->conflicts([$this->matchMatch()])->getTextBody();

        self::assertStringContainsString('U15 (coach) · domicile · vs Les Loups · 15:30 → 17:25', $text);
        self::assertStringContainsString('SM2 (joueur) · extérieur à Villeurbanne · vs Les Ours · départ 16:00 · 17:00 · retour 19:30', $text);
        self::assertStringContainsString('Chevauchement 16:00 → 17:25 (1h25)', $text);
    }

    /** Une coach qui joue lit « joueuse ». */
    public function testThePlayerWordFollowsTheCoachsGender(): void
    {
        $this->karim->setGender(CoachGender::FEMALE);

        self::assertStringContainsString('SM2 (joueuse)', (string) $this->conflicts([$this->matchMatch()])->getTextBody());
    }

    /** Heure estimée, lieu inconnu, trajet inconnu, adversaire vide : chaque inconnue est dite, jamais inventée. */
    public function testEveryUnknownIsSaidNeverInvented(): void
    {
        $estimated = $this->matchMatch(right: ['kickoffTime' => null, 'estimatedKickoff' => true, 'estimatedKickoffTime' => '17:00']);
        $unknownPlace = $this->matchMatch(right: ['opponentPlace' => null]);
        $unknownTravel = $this->matchMatch(right: ['travelOneWayMinutes' => null]);
        $noOpponent = $this->matchMatch(left: ['opponentLabel' => '']);

        self::assertStringContainsString('17:00 (heure estimée)', (string) $this->conflicts([$estimated])->getTextBody());
        self::assertStringContainsString('extérieur (lieu inconnu)', (string) $this->conflicts([$unknownPlace])->getTextBody());
        self::assertStringContainsString('extérieur à Villeurbanne · vs Les Ours · 17:00 · trajet inconnu', (string) $this->conflicts([$unknownTravel])->getTextBody());
        $noOpponentText = (string) $this->conflicts([$noOpponent])->getTextBody();
        self::assertStringContainsString('U15 (coach) · domicile · 15:30 → 17:25', $noOpponentText);
        self::assertStringNotContainsString('vs  ·', $noOpponentText);
    }

    /** Le côté entraînement nomme l'équipe, le gymnase et la séance ; un chevauchement qui passe minuit garde ses deux jours. */
    public function testTrainingSideAndOverlapPastMidnight(): void
    {
        $text = (string) $this->conflicts([$this->matchTraining()])->getTextBody();
        self::assertStringContainsString('Entraînement U13A · Gymnase Nord · 18:30 → 20:00', $text);

        $late = $this->matchMatch();
        $late['start'] = '2026-03-14 23:30';
        $late['end'] = '2026-03-15 00:45';
        self::assertStringContainsString('Chevauchement sam. 14/03 23:30 → dim. 15/03 00:45 (1h15)', (string) $this->conflicts([$late])->getTextBody());
    }

    /** L'indisponibilité nomme le match manqué, et dit quand son heure n'est qu'estimée. */
    public function testUnavailabilityNamesTheMissedMatch(): void
    {
        self::assertStringContainsString('Indisponible pour le match de U15, samedi 14/03 à 15:30 · vs Les Loups', (string) $this->conflicts([$this->coachUnavailable(3)])->getTextBody());

        $estimated = $this->coachUnavailable(3);
        $estimated['fixture']['kickoffTime'] = null;
        $estimated['fixture']['estimatedKickoff'] = true;
        $estimated['fixture']['estimatedKickoffTime'] = '15:30';
        self::assertStringContainsString('Indisponible pour le match de U15, samedi 14/03 à 15:30 (heure estimée) · vs Les Loups', (string) $this->conflicts([$estimated])->getTextBody());
    }

    /**
     * Abus : équipe, adversaire, gymnase, lieu et nom du coach sont des saisies du club (ou de FBI)
     * — tout est échappé UNE fois dans le builder, aucune balise ne survit dans le fragment, et le
     * fragment ne charge aucune image distante.
     */
    public function testUntrustedNamesAreEscaped(): void
    {
        $this->u15->setName('<script>alert(1)</script>');
        $this->nord->setName('<b>Nord</b>');
        $this->karim->setFirstName('<i>Karim</i>');
        $hostile = $this->matchMatch(left: ['opponentLabel' => '"><img src=x onerror=alert(2)>'], right: ['opponentPlace' => '<u>Lyon</u>']);

        $html = (string) $this->conflicts([$hostile, $this->matchTraining()])->getHtmlBody();

        foreach (['<script', '<img', '<b>Nord', '<i>Karim', '<u>Lyon', 'onerror=alert(2)>'] as $raw) {
            self::assertStringNotContainsString($raw, $html);
        }
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;Nord', $html);
        self::assertStringNotContainsString('src="http', $html, 'aucune image distante');
    }

    /** Taille : 50 tuiles, liste complète non plafonnée, tiennent sous 100 Ko une fois dans la carte (Gmail coupe au-delà). */
    public function testFiftyTilesStayUnderOneHundredKilobytes(): void
    {
        $conflicts = [];
        for ($i = 0; $i < 50; ++$i) {
            $conflicts[] = $this->matchMatch(left: ['fixtureId' => \sprintf('ffffffff-2222-4000-8000-%012d', $i), 'opponentLabel' => 'Adversaire au nom assez long numéro ' . $i]);
        }
        $email = $this->conflicts($conflicts);
        $fragment = (string) $email->getHtmlBody();

        self::assertSame(50, substr_count((string) $email->getTextBody(), 'Personne en double — '), 'aucune tuile n\'est retirée');
        $card = new EmailTemplateRenderer()->render((string) $email->getTextBody(), 'Amateo', 'Accroche', 'https://amateo.test', 'AIGLONS', true, bodyHtmlFragment: $fragment);
        self::assertLessThan(100 * 1024, \strlen($card), \sprintf('50 tuiles pèsent %d octets', \strlen($card)));
    }

    // ── Mail « plus aucun conflit » ──

    /** Sujet, salutation, phrase datée avec la saison, attribution au gestionnaire COURANT, sans tuile ni fragment. */
    public function testTheClearedMailSaysNothingIsLeft(): void
    {
        $email = $this->builder()->buildCleared('karim@aiglons.test', $this->karim, $this->club, $this->season, 'Paul', new DateTimeImmutable('2026-11-03'));
        $text = (string) $email->getTextBody();

        self::assertSame('Plus aucun conflit sur un match ne vous concerne — AIGLONS', $email->getSubject());
        self::assertSame('Paul (AIGLONS) via ' . new ProductIdentity()->name(), $email->getFrom()[0]->getName());
        self::assertStringStartsWith('Bonjour Karim,', $text);
        self::assertStringContainsString('Les conflits qui vous avaient été signalés ne sont plus d\'actualité au 03/11/2026 : aucun conflit de personne sur un match, daté d\'aujourd\'hui ou à venir, ne vous concerne pour la saison 2025-2026. Paul (AIGLONS) vous en informe.', $text);
        self::assertStringContainsString('Le planning peut encore bouger. Les matchs sans heure connue ne sont pas vérifiés.', $text);
        self::assertNull($email->getHtmlBody());
        self::assertFalse($email->getHeaders()->has(EmailTemplateRenderer::HTML_FRAGMENT_HEADER));
    }

    /** Passé par la vraie chaîne de signature, le mail « plus aucun » porte le pied anti-usurpation (texte et HTML). */
    public function testTheClearedMailCarriesTheAntiSpoofFooter(): void
    {
        $email = $this->builder()->buildCleared('karim@aiglons.test', $this->karim, $this->club, $this->season, 'Paul', new DateTimeImmutable('2026-11-03'));

        $this->signatureListener()->onMessage(new MessageEvent($email, Envelope::create($email), 'null://null', true));

        self::assertStringContainsString('Vous ne connaissez pas ce club ? Ignorez ce message.', (string) $email->getTextBody());
        self::assertSame(1, substr_count((string) $email->getHtmlBody(), 'Vous ne connaissez pas ce club ? Ignorez ce message.'));
    }

    protected function setUp(): void
    {
        $this->club = new Club()->setName('Les Aiglons de Villefranche')->setShortName('AIGLONS');
        $this->season = new Season()->setName('2025-2026');
        $this->karim = new Coach()->setFirstName('Karim')->setLastName('Benali')->setEmail('karim@aiglons.test')->setGender(CoachGender::MALE);
        $this->u15 = new Team()->setName('U15');
        $this->sm2 = new Team()->setName('SM2');
        $this->u13a = new Team()->setName('U13A');
        $this->nord = new Venue()->setName('Gymnase Nord');
    }

    private function builder(): ConflictCoachMailBuilder
    {
        return new ConflictCoachMailBuilder;
    }

    /** @param list<array<string, mixed>> $conflicts */
    private function conflicts(array $conflicts): Email
    {
        return $this->builder()->buildConflicts(
            'karim@aiglons.test',
            $this->karim,
            $this->club,
            $this->season,
            'Nadia',
            new DateTimeImmutable('2026-11-03'),
            $conflicts,
            [$this->u15->getId() => $this->u15, $this->sm2->getId() => $this->sm2, $this->u13a->getId() => $this->u13a],
            [$this->nord->getId() => $this->nord],
            [$this->karim->getId() => $this->karim],
        );
    }

    /**
     * Karim coache U15 samedi 14/03 15:30 à domicile (vs Les Loups) et joue avec SM2 à 17:00 à
     * Villeurbanne (vs Les Ours, 1 h de trajet, match 1h30) → chevauchement 16:00 → 17:25.
     *
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    private function matchMatch(array $left = [], array $right = []): array
    {
        return [
            'type' => 'MATCH_MATCH',
            'severity' => 3,
            'coachId' => $this->karim->getId(),
            'coachRole' => 'PLAYER',
            'start' => '2026-03-14 16:00',
            'end' => '2026-03-14 17:25',
            'left' => $left + [
                'fixtureId' => 'ffffffff-1111-4000-8000-000000000001',
                'teamId' => $this->u15->getId(),
                'homeAway' => 'HOME',
                'matchDate' => '2026-03-14',
                'kickoffTime' => '15:30',
                'estimatedKickoff' => false,
                'estimatedKickoffTime' => null,
                'travelOneWayMinutes' => null,
                'matchDurationMinutes' => 115,
                'opponentLabel' => 'Les Loups',
                'windowStart' => '2026-03-14 15:00',
                'windowEnd' => '2026-03-14 17:25',
                'role' => 'MAIN',
            ],
            'right' => $right + [
                'fixtureId' => 'ffffffff-1111-4000-8000-000000000002',
                'teamId' => $this->sm2->getId(),
                'homeAway' => 'AWAY',
                'matchDate' => '2026-03-14',
                'kickoffTime' => '17:00',
                'estimatedKickoff' => false,
                'estimatedKickoffTime' => null,
                'travelOneWayMinutes' => 60,
                'matchDurationMinutes' => 90,
                'opponentLabel' => 'Les Ours',
                'opponentPlace' => 'Villeurbanne',
                'windowStart' => '2026-03-14 16:00',
                'windowEnd' => '2026-03-14 19:30',
                'role' => 'PLAYER',
            ],
            'fingerprint' => 'MATCH_MATCH:' . $this->karim->getId(),
        ];
    }

    /**
     * Karim coache U15 vendredi 13/03 à 18:30 (match avancé, domicile) alors que sa séance U13A
     * occupe le Gymnase Nord de 18:30 à 20:00.
     *
     * @return array<string, mixed>
     */
    private function matchTraining(): array
    {
        return [
            'type' => 'MATCH_TRAINING',
            'severity' => 4,
            'coachId' => $this->karim->getId(),
            'coachRole' => 'MAIN',
            'start' => '2026-03-13 18:30',
            'end' => '2026-03-13 20:00',
            'fixture' => [
                'fixtureId' => 'ffffffff-1111-4000-8000-000000000003',
                'teamId' => $this->u15->getId(),
                'homeAway' => 'HOME',
                'matchDate' => '2026-03-13',
                'kickoffTime' => '18:30',
                'estimatedKickoff' => false,
                'estimatedKickoffTime' => null,
                'travelOneWayMinutes' => null,
                'matchDurationMinutes' => 115,
                'opponentLabel' => 'Les Loups',
                'windowStart' => '2026-03-13 18:00',
                'windowEnd' => '2026-03-13 20:25',
                'role' => 'MAIN',
            ],
            'training' => [
                'slotTemplateId' => 'eeeeeeee-0000-4000-8000-000000000001',
                'scheduleId' => 'bbbbbbbb-0000-4000-8000-000000000001',
                'teamId' => $this->u13a->getId(),
                'venueId' => $this->nord->getId(),
                'dayOfWeek' => 5,
                'startTime' => '18:30',
                'durationMinutes' => 90,
                'role' => 'MAIN',
                'windowStart' => '2026-03-13 18:30',
                'windowEnd' => '2026-03-13 20:00',
            ],
            'fingerprint' => 'MATCH_TRAINING:' . $this->karim->getId(),
        ];
    }

    /** @return array<string, mixed> Karim indisponible pour le match de U15 samedi 14/03 15:30 (gravité donnée). */
    private function coachUnavailable(int $severity): array
    {
        return [
            'type' => 'MATCH_COACH_UNAVAILABLE',
            'severity' => $severity,
            'coachId' => $this->karim->getId(),
            'coachRole' => 'MAIN',
            'fixture' => [
                'fixtureId' => 'ffffffff-1111-4000-8000-000000000004',
                'teamId' => $this->u15->getId(),
                'homeAway' => 'HOME',
                'matchDate' => '2026-03-14',
                'kickoffTime' => '15:30',
                'estimatedKickoff' => false,
                'estimatedKickoffTime' => null,
                'travelOneWayMinutes' => null,
                'matchDurationMinutes' => 115,
                'opponentLabel' => 'Les Loups',
                'windowStart' => '2026-03-14 15:00',
                'windowEnd' => '2026-03-14 17:25',
                'role' => 'MAIN',
            ],
            'fingerprint' => 'MATCH_COACH_UNAVAILABLE:' . $this->karim->getId() . ':' . $severity,
        ];
    }

    private function signatureListener(): EmailSignatureListener
    {
        return new EmailSignatureListener(
            new ProductIdentity,
            new BrandAssets,
            new EmailTemplateRenderer,
            new class implements LogoStorage {
                public function store(string $clubId, string $bytes): void {}

                public function read(string $clubId): ?string
                {
                    return null;
                }

                public function delete(string $clubId): void {}
            },
        );
    }
}

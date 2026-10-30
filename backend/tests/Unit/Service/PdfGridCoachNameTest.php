<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ScheduleSlotTemplate;
use App\Service\PdfGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Chantier E — la GRILLE du PDF (section 1) affiche le nom COURT du coach sous chaque équipe
 * QUAND, et seulement quand, le gestionnaire a coché « Afficher le nom des coachs ». La matrice
 * (section 2) n'en porte jamais : c'est déjà prouvé par
 * {@see PdfTeamDayMatrixTest::testMatrixCellCarriesVenueAndTimeButNeverTheCoach} (point sensible
 * « export », angle jumeau cité, pas redoublé ici).
 *
 * Abus couverts : A1 — un nom de coach hostile est ÉCHAPPÉ dans la cellule ; A4 — jamais le nom
 * COMPLET, seulement la forme courte (« Karim B. »).
 *
 * Même patron de réflexion que le test jumeau `PdfTeamDayMatrixTest` : les collaborateurs de
 * `PdfGenerator` sont `final` et non doublés, donc on exerce le builder pur `slotCell` directement.
 *
 * ⚠ Signature PROPOSÉE (option A) : `slotCell(bucket, span, teamNames, venues, groupLabels,
 * dayStart, coachShortNames, showCoaches)` — les DEUX nouveaux paramètres ajoutés EN FIN. NEO peut
 * l'adapter mécaniquement (ordre/défauts) ; les promesses d'affichage ne bougent pas.
 */
#[Group('phase1')]
final class PdfGridCoachNameTest extends TestCase
{
    public function testGridShowsTheCoachShortNameWhenOptedIn(): void
    {
        $html = $this->slotCell(
            [$this->slot('t-a', 'v-a', 2, '18:00', 'c-1')],
            coachShortNames: ['c-1' => 'Karim B.'],
            showCoaches: true,
        );

        self::assertStringContainsString('Karim B.', $html, 'coché → le nom court du coach paraît dans la cellule de grille');
    }

    public function testGridOmitsTheCoachWhenNotOptedIn(): void
    {
        $html = $this->slotCell(
            [$this->slot('t-a', 'v-a', 2, '18:00', 'c-1')],
            coachShortNames: ['c-1' => 'Karim B.'],
            showCoaches: false,
        );

        self::assertStringNotContainsString('Karim B.', $html, 'décoché → aucun nom de coach dans la grille');
    }

    public function testGridNeverShowsTheFullCoachName(): void
    {
        // A4 — même coché, seule la forme COURTE paraît : le nom de famille complet ne doit jamais
        // atterrir sur un document remis aux familles. La grille ne reçoit QUE la map courte.
        $html = $this->slotCell(
            [$this->slot('t-a', 'v-a', 2, '18:00', 'c-1')],
            coachShortNames: ['c-1' => 'Karim B.'],
            showCoaches: true,
        );

        self::assertStringNotContainsString('Benzema', $html, 'jamais le nom complet, seulement « Karim B. »');
    }

    public function testGridOmitsTheCoachLineWhenNoShortNameIsKnown(): void
    {
        // Promesse fondateur « pas de coach → rien » : une séance dont le coach ne résout aucun
        // nom court (coachId null, ou coach sans nom → CoachShortName rend null → absent de la map)
        // ne rend AUCUNE ligne de coach — même coché, la cellule est alors BYTE-IDENTIQUE à la
        // cellule décochée (aucune ligne de coach vide ajoutée).
        $slot = [$this->slot('t-a', 'v-a', 2, '18:00', null)];
        $optedIn = $this->slotCell($slot, coachShortNames: [], showCoaches: true);
        $optedOut = $this->slotCell($slot, coachShortNames: [], showCoaches: false);

        self::assertSame($optedOut, $optedIn, 'sans nom court connu, coché ou non rend la MÊME cellule — jamais de ligne de coach vide');
    }

    public function testAHostileCoachNameIsHtmlEscapedInTheCell(): void
    {
        // A1 — le nom d'un coach est du contenu club : un `<script>` saisi comme nom doit sortir
        // ÉCHAPPÉ, jamais comme balise vivante dans le document.
        $html = $this->slotCell(
            [$this->slot('t-a', 'v-a', 2, '18:00', 'c-1')],
            coachShortNames: ['c-1' => '<script>pwn()</script> B.'],
            showCoaches: true,
        );

        self::assertStringContainsString('&lt;script&gt;', $html, 'le nom hostile est échappé');
        self::assertStringNotContainsString('<script>pwn', $html, 'jamais de balise vivante dans la cellule');
    }

    public function testGridFallsBackToTheTeamMainCoachWhenTheSlotCarriesNone(): void
    {
        // Promesse fondateur Q4 : une séance SANS coach (coachId null) affiche le 1er coach MAIN de
        // son équipe — EXACTEMENT ce que montre l'écran (`grid.ts::slotCoachId` : `slot.coachId ??
        // teamCoach.get(teamId)`). Sur la base locale du fondateur, 233 séances sont dans ce cas.
        // La RÉSOLUTION du 1er MAIN (MAIN seul, scopé club+saison, ordre de l'endpoint) est prouvée
        // côté serveur par le jumeau {@see \App\Tests\Integration\Service\ScheduleExportCoachResolutionTest}
        // (point sensible « export », angles VOULUS cités l'un l'autre) ; ici on prouve que la GRILLE
        // APPLIQUE cette carte équipe → coach quand la séance n'a pas de coach à elle.
        $html = $this->slotCell(
            [$this->slot('t-a', 'v-a', 2, '18:00', null)],
            coachShortNames: ['c-9' => 'Karim B.'],
            showCoaches: true,
            teamMainCoachIds: ['t-a' => 'c-9'],
        );

        self::assertStringContainsString('Karim B.', $html, 'séance sans coach → le 1er coach MAIN de l’équipe paraît, comme à l’écran');
    }

    public function testTheSlotOwnCoachWinsOverTheTeamMainCoach(): void
    {
        // Précédence : un coach POSÉ sur la séance PRIME sur le coach d'équipe (`slot.coachId ??
        // teamCoach` côté écran : le `??` ne retombe sur l'équipe que si la séance n'a rien). Ce
        // garde interdit une résolution inversée (le coach d'équipe écrasant celui de la séance).
        $html = $this->slotCell(
            [$this->slot('t-a', 'v-a', 2, '18:00', 'c-1')],
            coachShortNames: ['c-1' => 'Karim B.', 'c-9' => 'Autre C.'],
            showCoaches: true,
            teamMainCoachIds: ['t-a' => 'c-9'],
        );

        self::assertStringContainsString('Karim B.', $html, 'le coach de la séance gagne');
        self::assertStringNotContainsString('Autre C.', $html, 'jamais le coach d’équipe quand la séance en porte un');
    }

    /** Décision fondateur : tous les coachs de l'équipe (« B / E »), rien n'impose un tenant. */
    public function testGridShowsAllTheCoachesOfTheTeam(): void
    {
        $html = $this->slotCell(
            [$this->slot('t-a', 'v-a', 2, '18:00', 'c-b')],
            coachShortNames: ['c-b' => 'Bruno B.', 'c-e' => 'Elsa E.'],
            showCoaches: true,
            teamCoachIds: ['t-a' => ['c-b', 'c-e']],
        );

        self::assertStringContainsString('Bruno B. / Elsa E.', $html);
    }

    /** Le tenant n'est nommé que s'il est imposé : le coach stocké n'est plus le premier de l'équipe. */
    public function testGridNamesTheHolderOnlyWhenImposed(): void
    {
        $html = $this->slotCell(
            [$this->slot('t-a', 'v-a', 2, '18:00', 'c-e')],
            coachShortNames: ['c-b' => 'Bruno B.', 'c-e' => 'Elsa E.'],
            showCoaches: true,
            teamCoachIds: ['t-a' => ['c-b', 'c-e']],
        );

        self::assertStringContainsString('tenue par Elsa E.', $html);
    }

    private function slot(string $teamId, string $venueId, int $day, string $time, ?string $coachId): ScheduleSlotTemplate
    {
        return new ScheduleSlotTemplate()
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($time))
            ->setDurationMinutes(90)
            ->setCoachId($coachId);
    }

    /**
     * @param list<ScheduleSlotTemplate> $bucket
     * @param array<string, string>      $coachShortNames  coachId → « Karim B. »
     * @param array<string, string>      $teamMainCoachIds teamId → coachId du 1er MAIN (repli quand
     *                                                     la séance n'a pas de coach à elle)
     * @param array<string, list<string>> $teamCoachIds    teamId → TOUS ses coachs principaux, dans l'ordre
     *
     * ⚠ Signature PROPOSÉE (option A) : `$teamMainCoachIds` ajouté EN FIN, après `$showCoaches` :
     * `slotCell(bucket, span, teamNames, venues, groupLabels, dayStart, coachShortNames, showCoaches,
     * teamMainCoachIds)`. NEO l'adapte mécaniquement ; la RÈGLE de repli (séance d'abord, sinon
     * équipe) ne bouge pas.
     */
    private function slotCell(array $bucket, array $coachShortNames, bool $showCoaches, array $teamMainCoachIds = [], array $teamCoachIds = []): string
    {
        $teamNames = ['t-a' => 'U13 F', 't-b' => 'U15 M'];
        $venues = ['v-a' => ['name' => 'Gymnase Municipal', 'color' => '#FFD700']];
        $generator = new ReflectionClass(PdfGenerator::class)->newInstanceWithoutConstructor();

        return (string) new ReflectionMethod(PdfGenerator::class, 'slotCell')
            ->invoke($generator, $bucket, 6, $teamNames, $venues, [], false, $coachShortNames, $showCoaches, $teamMainCoachIds, $teamCoachIds);
    }
}

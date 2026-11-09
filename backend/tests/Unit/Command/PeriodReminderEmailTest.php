<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Entity\CalendarEntry;
use App\Entity\SchoolHolidayPeriod;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonPlanState;
use App\Mail\EmailTemplateRenderer;
use App\Service\PeriodReminderMailBuilder;
use App\Tests\AssertsActionMailCta;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('phase1')]
final class PeriodReminderEmailTest extends TestCase
{
    use AssertsActionMailCta;

    public function testReminderCarriesTheCockpitCta(): void
    {
        // PROMESSE (D1) : le rappel « période sans plan » porte un bouton « Ouvrir le cockpit »
        // vers le lien du corps. ROUGE tant que le builder ne pose pas l'en-tête CTA.
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->build('admin@club.fr', 'BCCL', $this->period(), 14);

        $this->assertCtaHeadersPointToBodyLink($email, 'http://localhost:5173/', 'Ouvrir le cockpit');
    }

    public function testSubjectAndBodyAtJ14(): void
    {
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->build('admin@club.fr', 'BCCL', $this->period(), 14);

        // Nom unique (D6) : sujet et corps disent « planning de période », sans « (calendrier secondaire) ».
        self::assertSame('⏳ Gym Barros fermé dans 14 j — pas de planning de période', $email->getSubject());
        self::assertSame('admin@club.fr', $email->getTo()[0]->getAddress());
        self::assertSame('no-reply@amateo.app', $email->getFrom()[0]->getAddress());
        self::assertSame('Amateo', $email->getFrom()[0]->getName());
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('BCCL', $body);
        self::assertStringContainsString('Gym Barros fermé', $body);
        self::assertStringContainsString('Du 04/05/2026 au 10/05/2026', $body);
        self::assertStringContainsString('http://localhost:5173/', $body);
        self::assertStringContainsString('n\'a pas encore de planning de période.', $body);
        self::assertStringNotContainsString('secondaire', $body);
    }

    public function testRedSubjectAtJ3(): void
    {
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->build('a@b.fr', 'BCCL', $this->period(), 3);
        self::assertStringStartsWith('🔴', (string) $email->getSubject());
    }

    public function testNoLinkWhenBaseUrlEmpty(): void
    {
        $email = new PeriodReminderMailBuilder('')->build('a@b.fr', 'BCCL', $this->period(), 7);
        self::assertStringNotContainsString('http', (string) $email->getTextBody());
    }

    /**
     * PROMESSE (jour J, comportement LIVRÉ modifié par le chantier rappel vacances) : le rappel de
     * fermeture du jour même dit « aujourd'hui », jamais « dans 0 j » / « dans 0 jour ».
     */
    public function testClosureSubjectAndBodySayTodayAtJ0(): void
    {
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->build('a@b.fr', 'BCCL', $this->period(), 0);

        self::assertSame('🔴 Gym Barros fermé aujourd\'hui — pas de planning de période', $email->getSubject());
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('Elle commence aujourd\'hui et n\'a pas encore de planning de période.', $body);
        self::assertStringNotContainsString('dans 0', $body);
    }

    /** PROMESSE (D4) : le rappel de vacances vire au rouge à son DERNIER palier (J-7), jamais avant (J-8). */
    public function testHolidaySubjectIsRedAtJ7AndNotAtJ8(): void
    {
        $builder = new PeriodReminderMailBuilder('http://localhost:5173');

        self::assertSame('⏳ Vacances de Noël dans 8 j — pas de planning de période', $builder->buildForHoliday('a@b.fr', 'BCCL', $this->noel(), 8, SeasonPlanState::CHOSEN)->getSubject());
        self::assertSame('🔴 Vacances de Noël dans 7 j — pas de planning de période', $builder->buildForHoliday('a@b.fr', 'BCCL', $this->noel(), 7, SeasonPlanState::CHOSEN)->getSubject());
    }

    /** PROMESSE (D7, variante A) : planning de saison validé → le mail pousse à ADAPTER la vacance, mot pour mot, singulier à J-1. */
    public function testBodyWhenSeasonPlanChosen(): void
    {
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->buildForHoliday('admin@club.fr', 'BCCL', $this->noel(), 30, SeasonPlanState::CHOSEN);

        self::assertSame('⏳ Vacances de Noël dans 30 j — pas de planning de période', $email->getSubject());
        self::assertSame('admin@club.fr', $email->getTo()[0]->getAddress());
        self::assertSame(implode("\n", [
            'Club : BCCL',
            'Période : Vacances de Noël',
            'Du 19/12/2026 au 03/01/2027',
            '',
            'Elle commence dans 30 jours et n\'a pas encore de planning de période.',
            'Rien ne se fait tout seul — ouvre le cockpit pour l\'adapter.',
            '',
            'http://localhost:5173/',
        ]), $email->getTextBody());

        $oneDay = (string) new PeriodReminderMailBuilder('http://localhost:5173')->buildForHoliday('a@b.fr', 'BCCL', $this->noel(), 1, SeasonPlanState::CHOSEN)->getTextBody();
        self::assertStringContainsString('Elle commence dans 1 jour et n\'a pas encore de planning de période.', $oneDay);
    }

    /** PROMESSE (D7, variante B « génère puis valide ») : aucun planning de saison terminé → le mail dit de générer PUIS valider. */
    public function testBodyWhenSeasonPlanNotGenerated(): void
    {
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->buildForHoliday('admin@club.fr', 'BCCL', $this->noel(), 30, SeasonPlanState::NOT_GENERATED);

        self::assertSame('⏳ Vacances de Noël dans 30 j — planning de saison à valider', $email->getSubject());
        self::assertSame(implode("\n", [
            'Club : BCCL',
            'Période : Vacances de Noël',
            'Du 19/12/2026 au 03/01/2027',
            '',
            'Elle commence dans 30 jours. Pour préparer son planning de période, génère puis valide d\'abord ton planning de saison (page Planning).',
            'Rien ne se fait tout seul — ouvre le cockpit pour t\'y mettre.',
            '',
            'http://localhost:5173/',
        ]), $email->getTextBody());
        self::assertSame('🔴 Vacances de Noël dans 7 j — planning de saison à valider', new PeriodReminderMailBuilder('')->buildForHoliday('a@b.fr', 'BCCL', $this->noel(), 7, SeasonPlanState::NOT_GENERATED)->getSubject());
    }

    /** PROMESSE (D7, variante B « valide ») : un planning de saison terminé mais non validé → le mail dit seulement de VALIDER. */
    public function testBodyWhenSeasonPlanFinishedNotChosen(): void
    {
        $email = new PeriodReminderMailBuilder('http://localhost:5173')->buildForHoliday('admin@club.fr', 'BCCL', $this->noel(), 30, SeasonPlanState::FINISHED_NOT_CHOSEN);

        self::assertSame('⏳ Vacances de Noël dans 30 j — planning de saison à valider', $email->getSubject());
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('Elle commence dans 30 jours. Pour préparer son planning de période, valide d\'abord ton planning de saison (page Planning).', $body);
        self::assertStringNotContainsString('génère', $body);
    }

    /** PROMESSE (jour J) : le rappel de vacances du jour même dit « aujourd'hui » dans le sujet et le corps, dans les deux variantes. */
    public function testHolidaySubjectAndBodySayTodayAtJ0(): void
    {
        $builder = new PeriodReminderMailBuilder('http://localhost:5173');

        $chosen = $builder->buildForHoliday('a@b.fr', 'BCCL', $this->noel(), 0, SeasonPlanState::CHOSEN);
        self::assertSame('🔴 Vacances de Noël aujourd\'hui — pas de planning de période', $chosen->getSubject());
        self::assertStringContainsString('Elle commence aujourd\'hui et n\'a pas encore de planning de période.', (string) $chosen->getTextBody());

        $notChosen = $builder->buildForHoliday('a@b.fr', 'BCCL', $this->noel(), 0, SeasonPlanState::NOT_GENERATED);
        self::assertSame('🔴 Vacances de Noël aujourd\'hui — planning de saison à valider', $notChosen->getSubject());
        self::assertStringNotContainsString('dans 0', (string) $notChosen->getTextBody());
    }

    /** PROMESSE (D9) : le rappel de vacances porte le bouton « Ouvrir le cockpit » vers la racine du front, dans les deux variantes. */
    public function testHolidayReminderCarriesTheCockpitCta(): void
    {
        $builder = new PeriodReminderMailBuilder('http://localhost:5173');

        $this->assertCtaHeadersPointToBodyLink($builder->buildForHoliday('a@b.fr', 'BCCL', $this->noel(), 30, SeasonPlanState::CHOSEN), 'http://localhost:5173/', 'Ouvrir le cockpit');
        $this->assertCtaHeadersPointToBodyLink($builder->buildForHoliday('a@b.fr', 'BCCL', $this->noel(), 30, SeasonPlanState::NOT_GENERATED), 'http://localhost:5173/', 'Ouvrir le cockpit');
    }

    /** PROMESSE (D9) : sans base de front configurée, le rappel de vacances ne porte ni lien ni bouton. */
    public function testHolidayNoLinkNoCtaWhenBaseUrlEmpty(): void
    {
        $email = new PeriodReminderMailBuilder('')->buildForHoliday('a@b.fr', 'BCCL', $this->noel(), 14, SeasonPlanState::CHOSEN);

        self::assertStringContainsString('Vacances de Noël', (string) $email->getTextBody(), 'témoin : le corps du rappel de vacances est bien rendu');
        self::assertStringNotContainsString('http', (string) $email->getTextBody());
        self::assertFalse($email->getHeaders()->has(EmailTemplateRenderer::CTA_URL_HEADER), 'aucun bouton sans lien cliquable');
    }

    private function noel(): SchoolHolidayPeriod
    {
        return (new SchoolHolidayPeriod)
            ->setZone('A')
            ->setLabel('Vacances de Noël')
            ->setHolidayType('noel')
            ->setStartDate(new DateTimeImmutable('2026-12-19'))
            ->setEndDate(new DateTimeImmutable('2027-01-03'))
            ->setSchoolYear('2026-2027');
    }

    private function period(): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId('club-1');
        $entry->setSeasonId('season-1');
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym Barros fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));

        return $entry;
    }
}

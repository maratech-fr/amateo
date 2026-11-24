<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubMailboxMessage;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\Constraint;
use App\Entity\Feedback;
use App\Entity\Person;
use App\Entity\Place;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Plans\PlanComposition;
use App\Service\CoachWishCampaignTokenSync;
use App\Service\FeedbackContextEnricher;
use App\Service\PdfGenerator;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Service\StructureSnapshotter;
use App\Tests\Integration\Command\ErasePersonCommandTest;
use App\Tests\Integration\Structure\PersonEraserTest;
use App\Tests\Security\PersonErasureLeavesNoTraceTest;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Le décor des tests de l'effacement d'une personne (lot conception 3, PR 3), maison unique des trois
 * fichiers qui le jouent ({@see PersonErasureLeavesNoTraceTest},
 * {@see PersonEraserTest},
 * {@see ErasePersonCommandTest}). Il pose, par le relevé du §2 du plan
 * (`specs/evolution/lot-conception-3-personnes-et-lieux.md`), chaque endroit où vit ce qui vient de
 * « Camille Durand » : sa fiche, ses rôles dans la saison en cours ET la saison passée (archivée,
 * lecture seule), ses séances, son indisponibilité, la demande figée et la photo d'une version, deux
 * diagnostics (l'un porte son identifiant, l'autre, d'une autre équipe, la cite seulement), sa doléance
 * commentée, le lien de la collecte ouverte, deux signalements (planning nommé ; génération en échec,
 * composé par le vrai {@see FeedbackContextEnricher}), deux messages de la boîte d'un club de
 * démonstration (l'un adressé à elle, l'autre, le récapitulatif adressé au gestionnaire, la cite) et
 * les PDF stockés du club.
 *
 * Un AUTRE club porte une homonyme, « Camille Durand » elle aussi, son signalement qui la cite et son PDF :
 * l'effacement d'une personne d'un club n'y touche jamais.
 *
 * Le décor est posé par l'EntityManager sous le GUC du club (dama annule tout à la fin du test) ;
 * les PDF sont de vrais fichiers dans {@see PdfGenerator::outputDir()}, retirés par
 * {@see removeErasureDecorFiles()} en fin de test.
 */
trait PersonErasureDecorTrait
{
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private const string ERASED_FIRST_NAME = 'Camille';

    /**
     * Identifiants et textes du décor du club A (celui où la personne est effacée) et du club B.
     *
     * @var array<string, string>
     */
    private array $decor = [];

    /** @var list<string> fichiers PDF écrits par le décor */
    private array $decorFiles = [];

    /** Le club A : `$demo` en fait un club de démonstration à horloge simulée (boîte aux lettres). */
    private function seedErasureDecor(bool $demo = false): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = bin2hex(random_bytes(4));
        $this->decor['uid'] = $uid;
        // Nom de famille propre au test (« Durand- » et huit lettres) : la demande figée et la photo rangent
        // prénom et nom dans deux champs, le balayage cherche donc aussi le nom seul.
        $this->decor['lastName'] = 'Durand-' . ucfirst(strtr($uid, '0123456789', 'ghijklmnop'));
        $this->decor['fullName'] = self::ERASED_FIRST_NAME . ' ' . $this->decor['lastName'];
        $this->decor['email'] = 'camille.durand.' . $uid . '@exemple.fr';
        $this->decor['phone'] = '06' . str_pad((string) (hexdec($uid) % 100000000), 8, '0', \STR_PAD_LEFT);
        $this->decor['comment'] = 'Je pars en stage ' . $uid . ' la premiere semaine';
        $this->decor['managerEmail'] = 'gestion.' . $uid . '@exemple.fr';

        // Club B d'abord : son homonyme, son signalement, son PDF.
        $clubB = $this->erasureClub('B ' . $uid, false);
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $seasonB = $this->erasureSeason($clubB, $year, SeasonStatus::ACTIVE);
        $homonym = $this->newCoach($clubB->getId(), $seasonB->getId(), self::ERASED_FIRST_NAME, $this->decor['lastName']);
        $this->personOfDecorCoach($homonym)->setEmail('camille.durand.' . $uid . '@club-b.fr')->setPhone('0699999999');
        $em->flush();
        $scheduleB = $this->erasureSchedule($clubB, $seasonB, ScheduleStatus::COMPLETED);
        $this->decor['pdfB'] = $this->storePdf($scheduleB, $seasonB);
        $feedbackB = new Feedback($clubB->getId(), $this->uuid(), 'bug', 'Le planning du club B est faux.');
        $feedbackB->setContext(['note' => 'cite ' . $this->decor['fullName'] . ' du club B']);
        $em->persist($feedbackB);
        $em->flush();
        $this->decor += [
            'clubB' => $clubB->getId(), 'seasonB' => $seasonB->getId(), 'homonymCoach' => $homonym->getId(),
            'homonymPerson' => $homonym->getPersonId(), 'scheduleB' => $scheduleB->getId(), 'feedbackB' => $feedbackB->getId(),
        ];

        // Club A.
        $club = $this->erasureClub('A ' . $uid, $demo);
        $previous = $this->erasureSeason($club, $year - 1, SeasonStatus::ARCHIVED);
        $current = $this->erasureSeason($club, $year, SeasonStatus::ACTIVE);

        $camille = $this->newCoach($club->getId(), $current->getId(), self::ERASED_FIRST_NAME, $this->decor['lastName']);
        $dominique = $this->newCoach($club->getId(), $current->getId(), 'Dominique', 'Martin');
        $em->flush();
        $camillePerson = $this->personOfDecorCoach($camille)->setEmail($this->decor['email'])->setPhone($this->decor['phone']);
        $camillePast = new Coach($camillePerson)->setClubId($club->getId())->setSeasonId($previous->getId());
        $em->persist($camillePast);
        $em->flush();

        // Saison en cours : U13M1 (Camille seule), U15M1 (Camille puis Dominique, deux principaux), SM1 (Dominique).
        $venue = $this->erasureVenue($club, $current);
        $u13 = $this->erasureTeam($club, $current, 'U13M1');
        $u15 = $this->erasureTeam($club, $current, 'U15M1');
        $sm1 = $this->erasureTeam($club, $current, 'SM1');
        $this->erasureLink($club, $current, $u13, $camille, '2025-09-01');
        $this->erasureLink($club, $current, $u15, $camille, '2025-09-01');
        $this->erasureLink($club, $current, $u15, $dominique, '2025-09-02');
        $this->erasureLink($club, $current, $sm1, $dominique, '2025-09-02');
        $rule = (new Constraint)->setClubId($club->getId())->setSeasonId($current->getId())
            ->setName($this->decor['fullName'] . ' · indispo lundi')->setScope(ConstraintScope::COACH)->setScopeTargetId($camille->getId())
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['unavailableDays' => [1]]);
        $em->persist($rule);
        $em->flush();

        // La version générée de la saison en cours : demande figée par le vrai constructeur, photo, séances, diagnostics.
        $v1 = $this->erasureSchedule($club, $current, ScheduleStatus::COMPLETED);
        $v1->setSolveStartedAt(new DateTimeImmutable('-2 hours'));
        $em->flush();
        foreach ([$u13, $u15] as $index => $team) {
            $this->erasureSession($club, $current, $v1, $team, $venue, $camille, 1 + $index);
        }
        $this->erasureSession($club, $current, $v1, $sm1, $venue, $dominique, 4);
        $own = $this->erasureDiagnostic($club, $current, $v1, $this->decor['fullName'] . ' dépasse son plafond de jours', coachId: $camille->getId());
        $cited = $this->erasureDiagnostic($club, $current, $v1, 'SM1 : créneau partagé avec ' . $this->decor['fullName'] . ' (U13M1)', teamId: $sm1->getId());
        $this->decor['pdfV1'] = $this->storePdf($v1, $current);

        // Saison passée, archivée : son rôle, ses séances.
        $pastVenue = $this->erasureVenue($club, $previous);
        $pastTeam = $this->erasureTeam($club, $previous, 'U13M1');
        $this->erasureLink($club, $previous, $pastTeam, $camillePast, '2024-09-01');
        $v0 = $this->erasureSchedule($club, $previous, ScheduleStatus::COMPLETED);
        $v0->setSolveStartedAt(new DateTimeImmutable('-400 days'));
        $em->flush();
        $this->erasureSession($club, $previous, $v0, $pastTeam, $pastVenue, $camillePast, 2);
        $this->decor['pdfV0'] = $this->storePdf($v0, null);

        // La collecte ouverte (vacances à venir) : Camille, premier principal, en est la destinataire.
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($current->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('2030-04-08'))->setEndDate(new DateTimeImmutable('2030-04-21'));
        $em->persist($entry);
        $campaign = (new CoachWishCampaign)->setClubId($club->getId())->setSeasonId($current->getId())
            ->setCalendarEntryId($entry->getId())->setDeadline(new DateTimeImmutable('2030-04-01'))
            ->setWeeks(['2030-04-08'])->setTeamIds([$u13->getId(), $u15->getId()]);
        $em->persist($campaign);
        $em->flush();
        static::getContainer()->get(CoachWishCampaignTokenSync::class)->sync($campaign);
        $wish = (new CoachWish)->setClubId($club->getId())->setSeasonId($current->getId())->setCalendarEntryId($entry->getId())
            ->setWeekStart(new DateTimeImmutable('2030-04-08'))->setTeamId($u15->getId())->setCoachId($camille->getId())->setSlotsWanted(1)
            ->setStaffUnavailability([['coachId' => $camille->getId(), 'days' => [4]]])->setComment($this->decor['comment']);
        $em->persist($wish);
        $em->flush();

        // Signalement 1 : le planning nommé, contexte lourd recopié comme le fait FeedbackController.
        $named = new Feedback($club->getId(), $this->uuid(), 'bug', 'Le planning ne me convient pas.');
        $named->setContext([
            'scheduleId' => $v1->getId(), 'seasonId' => $current->getId(), 'scheduleStatus' => 'COMPLETED',
            'snapshot' => $v1->engineInput(),
            'diagnostics' => [
                ['type' => $own->getType(), 'message' => $own->getMessage(), 'coachId' => $camille->getId()],
                ['type' => $cited->getType(), 'message' => $cited->getMessage(), 'teamId' => $sm1->getId()],
            ],
        ]);
        $em->persist($named);

        // Signalement 2 : né d'une génération en échec récente, composé par le vrai enrichisseur (branche generationFailure).
        $failed = $this->erasureSchedule($club, $current, ScheduleStatus::FAILED);
        $failed->setSolveStartedAt(new DateTimeImmutable('-5 minutes'));
        $em->flush();
        $this->erasureDiagnostic($club, $current, $failed, 'Aucun créneau pour U13M1 : ' . $this->decor['fullName'] . ' est indisponible', teamId: $u13->getId(), severity: ScheduleDiagnosticSeverity::ERROR);
        $onFailure = new Feedback($club->getId(), $this->uuid(), 'bug', 'La génération a échoué.');
        $onFailure->setContext(static::getContainer()->get(FeedbackContextEnricher::class)->enrich($club->getId(), $this->uuid(), []));
        $em->persist($onFailure);
        $em->flush();

        // La boîte du club de démonstration : un message adressé à elle, le récapitulatif de fin de collecte qui la cite.
        $addressed = (new ClubMailboxMessage)->setClubId($club->getId())->setSimulatedDate(new DateTimeImmutable('today'))
            ->setFromAddress('collecte@exemple.fr')->setToAddress($this->decor['email'])
            ->setSubject('Vos disponibilités pour les vacances')->setBodyText('Bonjour ' . $this->decor['fullName'] . ', merci de répondre.');
        $digest = (new ClubMailboxMessage)->setClubId($club->getId())->setSimulatedDate(new DateTimeImmutable('today'))
            ->setFromAddress('collecte@exemple.fr')->setToAddress($this->decor['managerEmail'])
            ->setSubject('Collecte : réponse de ' . $this->decor['fullName'])->setBodyText("Nouveaux répondants :\n- " . $this->decor['fullName'] . ' (U15M1)');
        $em->persist($addressed);
        $em->persist($digest);
        $em->flush();

        $this->decor += [
            'club' => $club->getId(), 'current' => $current->getId(), 'previous' => $previous->getId(),
            'person' => $camille->getPersonId(), 'coach' => $camille->getId(), 'coachPast' => $camillePast->getId(),
            'dominique' => $dominique->getId(), 'dominiquePerson' => $dominique->getPersonId(),
            'u13' => $u13->getId(), 'u15' => $u15->getId(), 'sm1' => $sm1->getId(), 'pastTeam' => $pastTeam->getId(),
            'rule' => $rule->getId(), 'v1' => $v1->getId(), 'v0' => $v0->getId(), 'failed' => $failed->getId(),
            'ownDiagnostic' => $own->getId(), 'citedDiagnostic' => $cited->getId(),
            'campaign' => $campaign->getId(), 'wish' => $wish->getId(),
            'feedbackNamed' => $named->getId(), 'feedbackFailure' => $onFailure->getId(),
            'mailAddressed' => $addressed->getId(), 'mailDigest' => $digest->getId(),
        ];
        $em->clear();
    }

    /** Retire du disque les PDF écrits par le décor (ceux que l'effacement n'a pas déjà supprimés). */
    private function removeErasureDecorFiles(): void
    {
        foreach ($this->decorFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function decorConnection(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /** @return list<string> les textes de la personne effacée : nom composé, mail, téléphone, commentaire */
    private function erasedPersonNeedles(): array
    {
        return [$this->decor['fullName'], $this->decor['lastName'], $this->decor['email'], $this->decor['phone'], $this->decor['comment']];
    }

    /** `table.colonne` qui contiennent encore l'un des textes, vus sous le GUC du club donné. */
    private function tracesSeenByClub(string $clubId, array $needles): string
    {
        $this->scopeGucToClub($clubId);

        return (string) $this->decorConnection()->fetchOne(PersonTraceSweep::sql($needles));
    }

    private function erasureClub(string $tag, bool $demo): Club
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slug = 'effacement-' . strtolower(str_replace(' ', '-', $tag));
        $club = (new Club)->setName('Effacement ' . $tag)->setSlug($slug)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        if ($demo) {
            $club->setIsDemo(true)->setSimulatedToday(new DateTimeImmutable('today'));
        }
        $em->persist($club);
        $em->flush();
        $this->scopeGucToClub($club->getId());

        return $club;
    }

    private function erasureSeason(Club $club, int $startYear, SeasonStatus $status): Season
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $season = (new Season)->setClubId($club->getId())->setName($startYear . '-' . ($startYear + 1))
            ->setStartDate(new DateTimeImmutable($startYear . '-09-01'))->setEndDate(new DateTimeImmutable(($startYear + 1) . '-06-30'))->setStatus($status);
        $em->persist($season);
        $em->flush();

        return $season;
    }

    private function erasureVenue(Club $club, Season $season): Venue
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $place = (new Place)->setClubId($club->getId());
        $em->persist($place);
        $venue = new Venue($place)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase Nord')->setSource('manual');
        $em->persist($venue);
        $em->flush();
        foreach ([1, 2, 3, 4] as $day) {
            $em->persist((new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())->setSchedulePlanId(SeasonPlanOf::id(static::getContainer(), $season->getId()))->setVenueId($venue->getId())
                ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        }
        $em->flush();

        return $venue;
    }

    private function erasureTeam(Club $club, Season $season, string $name): Team
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(1)
            ->setName($name)->setIsActive(true);
        $em->persist($team);
        $em->flush();
        static::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 1]]);
        $em->flush();

        return $team;
    }

    private function erasureLink(Club $club, Season $season, Team $team, Coach $coach, string $since): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN)->setCreatedAt(new DateTimeImmutable($since)));
        $em->flush();
    }

    /** Une version numérotée de son plan de saison ; la demande figée et sa photo viennent des vrais composants. */
    private function erasureSchedule(Club $club, Season $season, ScheduleStatus $status): Schedule
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provisioner = static::getContainer()->get(SchedulePlanProvisioner::class);
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('V')->setStatus($status)
            ->setSchedulePlanId($provisioner->ensureSeasonPlanId($season->getId()));
        $em->persist($schedule);
        $provisioner->linkSchedule($schedule);
        $em->flush();
        $payload = $this->freshEngineRequest($club->getId(), $season->getId());
        $schedule->setSnapshotData($payload)->setSnapshotHash(ScheduleConstraintBuilder::structureHash($payload));
        $em->flush();
        $snapshotter = static::getContainer()->get(StructureSnapshotter::class);
        $snapshotter->store($schedule, $snapshotter->serialize($club->getId(), $season->getId()));

        return $schedule;
    }

    /** La demande que composerait le constructeur maintenant : le cache du club est vidé d'abord (le décor écrit sans fin de requête). */
    private function freshEngineRequest(string $clubId, string $seasonId): array
    {
        $cache = static::getContainer()->get('cache.schedule');
        \assert($cache instanceof TagAwareCacheInterface);
        $cache->invalidateTags([ScheduleConstraintBuilder::cacheTag($clubId)]);

        return static::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason($clubId, $seasonId);
    }

    private function erasureSession(Club $club, Season $season, Schedule $schedule, Team $team, Venue $venue, Coach $coach, int $day): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())->setScheduleId($schedule->getId())
            ->setTeamId($team->getId())->setVenueId($venue->getId())->setCoachId($coach->getId())
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        $em->flush();
    }

    private function erasureDiagnostic(Club $club, Season $season, Schedule $schedule, string $message, ?string $coachId = null, ?string $teamId = null, ScheduleDiagnosticSeverity $severity = ScheduleDiagnosticSeverity::WARNING): ScheduleDiagnostic
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $diagnostic = (new ScheduleDiagnostic)->setClubId($club->getId())->setSeasonId($season->getId())->setScheduleId($schedule->getId())
            ->setType('coach_overload')->setSeverity($severity)->setCoachId($coachId)->setTeamId($teamId)->setMessage($message);
        $em->persist($diagnostic);
        $em->flush();

        return $diagnostic;
    }

    /** Un PDF livré sur disque, son adresse sur la version et, si la saison est donnée, épinglé sur elle. */
    private function storePdf(Schedule $schedule, ?Season $season): string
    {
        $dir = PdfGenerator::outputDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $filename = \sprintf('schedule-%s-all.pdf', $schedule->getId());
        $path = $dir . '/' . $filename;
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $this->decorFiles[] = $path;
        $url = \sprintf('/api/schedules/%s/export-pdf/%s', $schedule->getId(), $filename);
        $schedule->setPdfExportStatus('completed')->setPdfExportUrl($url)->setPdfExportRequestId(bin2hex(random_bytes(16)));
        $season?->setExportPdfUrl($url);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $path;
    }

    private function personOfDecorCoach(Coach $coach): Person
    {
        $person = $coach->personGivenAtCreation();
        \assert($person instanceof Person);

        return $person;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

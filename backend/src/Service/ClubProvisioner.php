<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Enum\ClubRole;
use App\Enum\SeasonStatus;
use App\Repository\SportRepository;
use App\Service\Basketball\CategoryCatalog;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * P3-4 — le provisioning d'un club, EXTRAIT de AuthController::verifyEmail :
 * l'approbation d'une demande de création (ClubApprovalService) matérialise
 * désormais le même club que la vérification d'email d'antan — une seule
 * vérité pour créer/seeder un club, deux appelants (verify pour la reprise
 * RGPD memberless, approbation pour le club neuf).
 *
 * ⚠ RLS : les écritures club-scopées (saison, catégories, contraintes,
 * membership) exigent le GUC tenant — l'APPELANT le pose (patron verifyEmail :
 * setClubId après persist du club, clear en finally). Aucun flush ici — la
 * transaction appartient à l'appelant.
 */
final class ClubProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolZoneResolver $schoolZoneResolver,
        private readonly LeagueResolver $leagueResolver,
        private readonly SportRepository $sportRepository,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly DefaultConstraintSeeder $defaultConstraintSeeder,
        private readonly ClubLeagueWindowSeeder $clubLeagueWindowSeeder,
        private readonly OrphanAccountNotifier $orphanAccountNotifier,
    ) {}

    public function createClub(string $clubName, string $ara): Club
    {
        $slug = (string) new AsciiSlugger('fr')->slug($clubName)->lower() . '-' . bin2hex(random_bytes(4));

        $club = new Club;
        $club->setName($clubName);
        $club->setSlug($slug);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(false);
        $club->setFfbbClubCode($ara);
        // Best-effort academic zone from the FFBB code (accueil-cockpit-temporel §4bis);
        // null when undecidable → stays manually editable via Club PATCH.
        $club->setSchoolZone($this->schoolZoneResolver->resolveFromFfbbCode($ara));
        // Best-effort FFBB league (région) from the code prefix — drives the
        // match-window catalog envelope (spec gestion-matchs §6bis); null →
        // falls back to the federation-default (AURA) at read time.
        $club->setLeague($this->leagueResolver->resolveFromFfbbCode($ara));
        $this->entityManager->persist($club);

        return $club;
    }

    public function createMembership(string $clubId, string $userId, bool $isActive, ClubRole $role): void
    {
        $clubUser = new ClubUser;
        $clubUser->setClubId($clubId);
        $clubUser->setUserId($userId);
        $clubUser->setRole($role->value);
        $clubUser->setIsActive($isActive);
        $this->entityManager->persist($clubUser);

        // P4-301 — toute NAISSANCE d'une adhésion ACTIVE (création d'un club, reprise)
        // annule un éventuel préavis « compte sans club ». Foyer unique
        // OrphanAccountNotifier::cancelFor ; flushé par la transaction de l'appelant.
        if ($isActive) {
            $this->orphanAccountNotifier->cancelFor($userId);
        }
    }

    public function seedWorkspace(Club $club): void
    {
        // La saison COURANTE au sens du pivot système (15 juillet, SeasonResolver) —
        // pas l'année civile : un club inscrit en janvier 2027 rejoint la saison
        // 2026-2027 en cours, pas une saison future. Fenêtre alignée sur le pivot
        // (15/07 → 14/07), nom « 2026-2027 » (décision fondateur 2026-07-24 :
        // jamais « 2026 » seul — ambigu sur une saison à cheval).
        $seasonYear = SeasonResolver::seasonYear(new DateTimeImmutable);
        // P1-5 : la saison de naissance est réputée couverte par l'inscription —
        // la SUIVANTE exigera le paiement (gate de bascule, SeasonTransitionService).
        $club->setPaidSeasonYear($seasonYear);
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName(SeasonResolver::defaultSeasonName(new DateTimeImmutable($seasonYear . '-07-15')));
        $season->setStartDate(new DateTimeImmutable($seasonYear . '-07-15'));
        $season->setEndDate(new DateTimeImmutable(($seasonYear + 1) . '-07-14'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->entityManager->persist($season);

        // ADR-0002 Lot A: the onboarded club's season starts with its empty
        // SEASON plan (flushed with the rest of the seed by the caller).
        $this->schedulePlanProvisioner->ensureSeasonPlan($season);

        // P4-272 ① — la copie club de l'enveloppe ligue naît avec la saison
        // (recopie de la ligue effective) : le placement lit désormais CETTE
        // copie, jamais le catalogue global. Flushée avec le reste du seed.
        $this->clubLeagueWindowSeeder->seedForSeason($club->getId(), $season->getId(), $club->getLeague());

        $sport = $this->sportRepository->findOneBy(['slug' => 'basketball']);
        if (null === $sport) {
            $sport = new Sport;
            $sport->setName('Basketball');
            $sport->setSlug('basketball');
            $sport->setIsActive(true);
            $this->entityManager->persist($sport);
        }
        // Le club connaît son sport de première main (retour fondateur 2026-07-18) —
        // basket, seul sport aujourd'hui. Au 2e sport : threader le choix
        // register → EmailVerificationToken → ici (patron de $intentClubName).
        $club->setSportId($sport->getId());

        $categories = CategoryCatalog::categories();
        foreach ($categories as $categoryData) {
            $sportCategory = new SportCategory;
            $sportCategory->setClubId($club->getId());
            $sportCategory->setSportId($sport->getId());
            $sportCategory->setName($categoryData['name']);
            $sportCategory->setAgeMin($categoryData['ageMin']);
            $sportCategory->setAgeMax($categoryData['ageMax']);
            $sportCategory->setIsCustom(false);
            $sportCategory->setSortOrder($categoryData['sortOrder']);
            $this->entityManager->persist($sportCategory);
        }

        // P2-16 — les contraintes de base (« la page pas vierge »), semées à la
        // création SEULEMENT : au changement de saison le planning précédent est
        // copié, le travail est déjà prémâché. Flushées avec le reste du seed.
        $this->defaultConstraintSeeder->seed($club, $season);
    }
}

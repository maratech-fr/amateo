<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubTravelCache;
use App\Entity\ClubUser;
use App\Entity\Feedback;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Entity\SolverMetric;
use App\Entity\SportCategory;
use App\Entity\TeamTag;
use App\Enum\AuditAction;
use App\Enum\OpponentVenueLinkSource;
use App\Repository\OpponentVenueSuggestionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RGPD — purge du workspace d'un club effacé (délai de grâce échu).
 *
 * Vide TOUTES les données d'exploitation du tenant : chaque saison via
 * SeasonDataPurger (ligne Season incluse), puis les entités club-scoped sans
 * saison (TeamTag, SportCategory custom) et les memberships ClubUser.
 *
 * ÉPARGNE l'identité publique FFBB du club (décision fondateur 2026-07-11) :
 * nom, ffbbClubCode, logo, ligue/comité, contacts président/correspondant tels
 * que publiés par la FFBB (source FfbbClubPopulator, base légale intérêt
 * légitime — organisation des rencontres, futur annuaire adverse, win-back).
 * Les comptes User des membres NE sont PAS touchés : ils appartiennent à leurs
 * titulaires (responsable de traitement = Maratech), qui peuvent les
 * effacer eux-mêmes via DELETE /api/me.
 *
 * Tourne sous le GUC du club (posé par l'appelant, pattern PurgeSeasonsCommand).
 */
final class ErasedClubPurger
{
    use DisablesTenantFilters;

    /**
     * Tenant, VOLONTAIREMENT hors de l'effacement DIRECT — chacune avec sa raison. Le
     * test PurgeCompletenessTest exige que toute entité tenant soit purgée (par saison
     * via SeasonDataPurger, ou par club ici) ou nommément exclue ici.
     *
     * @var array<string, string> table => pourquoi
     */
    public const EXCLUDED_FROM_ERASURE = [
        'audit_log' => 'accountability : l\'effacement du club ÉCRIT lui-même une ligne d\'audit (CLUB_PURGED) — le journal a sa propre rétention (app:audit:purge)',
        'coach_wish_token' => 'part par la FK ON DELETE CASCADE de sa campagne (supprimée avec chaque saison par SeasonDataPurger)',
    ];

    /**
     * Entités CLUB-scoped SANS saison, purgées par club_id à l'effacement RGPD (les
     * tables tenant+saison partent, elles, par SeasonDataPurger itéré sur chaque saison).
     * La boucle de purge itère cette constante.
     *
     * @var list<class-string>
     */
    private const PURGED_BY_CLUB = [
        // SolverMetric est APPEND-ONLY (SA2-stats, 2026-07-18) : ni la validation ni
        // le reset de saison ne le purgent plus. CE chemin est donc sa SEULE porte de
        // sortie — « seule l'identité FFBB survit » doit être vrai à la lettre, et la
        // suppression par clubId emporte tout l'historique, rattaché ou orphelin.
        SolverMetric::class,
        // RGPD : un club effacé ne garde pas ses signalements (P5-6). Delete par
        // clubId, comme les autres tables club-scoped sans saison.
        Feedback::class,
        TeamTag::class,
        SportCategory::class,
        ClubUser::class,
        // Cache de trajets club-scoped (C4) : un club effacé ne garde pas les distances
        // dérivées de son siège. Delete par clubId, comme les autres tables sans saison.
        ClubTravelCache::class,
        // Appariements « libellé FBI → gymnase » club-scoped SANS saison (amendement
        // 2026-09-20). CE chemin est leur seule porte de sortie ; le décrément du compteur
        // partagé des liens MANUAL ({@see decrementSharedVenueChoices}) tourne AVANT ce
        // DELETE (les liens doivent encore exister).
        OpponentVenueLink::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeasonDataPurger $seasonDataPurger,
        private readonly AuditTrail $auditTrail,
        private readonly OpponentVenueSuggestionRepository $venueSuggestions,
    ) {}

    /** @return int nombre de lignes supprimées (workspace complet) */
    public function purge(Club $club): int
    {
        $clubId = $club->getId();
        $deleted = 0;

        // 1. Toutes les saisons du club, ligne Season comprise.
        $this->disableTenantFilters($this->entityManager);
        $seasons = $this->entityManager->getRepository(Season::class)->findBy(['clubId' => $clubId]);
        foreach ($seasons as $season) {
            $deleted += $this->seasonDataPurger->purge($clubId, $season->getId(), deleteSeasonRow: true) + 1;
        }

        // 2. Club-scoped sans saison. SeasonDataPurger::purge fait un clear()
        //    final → les filtres Doctrine doivent être re-désactivés.
        $this->disableTenantFilters($this->entityManager);

        // P4-209(b) — un appariement MANUAL a incrémenté le compteur PARTAGÉ
        // (opponent_venue_suggestion). L'effacer sans décrémenter volerait le compte des
        // autres clubs. Pré-passe AVANT le DELETE des liens ci-dessous (les lignes doivent
        // encore exister). Suit le nouveau grain : club-scoped, sans saison.
        $this->decrementSharedVenueChoices($clubId);

        foreach (self::PURGED_BY_CLUB as $entityClass) {
            $deleted += (int) $this->entityManager->createQueryBuilder()
                ->delete($entityClass, 'e')
                ->where('e.clubId = :clubId')
                ->setParameter('clubId', $clubId)
                ->getQuery()
                ->execute();
        }

        // 3. La fiche club survit (identité publique FFBB : nom, code, logo,
        //    ligue/comité, contacts FFBB) — mais l'état d'ABONNEMENT n'est pas
        //    de l'identité publique : plan, cycle de facturation et compteurs
        //    sont remis à zéro (revue sécurité PR-1 — « seule l'identité FFBB
        //    survit » doit être vrai à la lettre).
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if ($club instanceof Club) {
            $club->setErasureScheduledAt(null);
            $club->setUnsubscribedAt(new DateTimeImmutable);
            $club->setOnboardingCompleted(false);
            $club->setPlanId(null);
            $club->setBillingCycle(null);
            $club->setPlanExpiresAt(null);
            $club->setGenerationCountSeason(0);
            $this->entityManager->flush();
        }
        $this->entityManager->clear();

        // Audit APRÈS le clear (l'insert DBAL ne touche pas l'unit of work).
        $this->auditTrail->record(AuditAction::CLUB_PURGED, null, $clubId, 'Club', $clubId, ['rowsDeleted' => $deleted]);

        return $deleted;
    }

    /**
     * P4-209(b) — décrémente le compteur PARTAGÉ pour chaque appariement MANUAL du club
     * qui épingle un gymnase fédéral (`venue_external_ref` non nul), AVANT que la purge ne
     * supprime ces liens. Sémantique identique à l'ancien décrément d'opponent_travel
     * (MANUAL + ref effectif seuls ; le décrément est idempotent, GREATEST(0, …)). SQL brut :
     * les lignes sont supprimées en DQL de masse juste après, on ne veut pas d'entités
     * gérées. Tourne sous le GUC du club (RLS borne la table tenant).
     */
    private function decrementSharedVenueChoices(string $clubId): void
    {
        /** @var list<array{opponent_organisme_code: string, venue_external_ref: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT opponent_organisme_code, venue_external_ref FROM opponent_venue_link'
            . ' WHERE club_id = :clubId AND source = :source AND venue_external_ref IS NOT NULL',
            ['clubId' => $clubId, 'source' => OpponentVenueLinkSource::MANUAL->value],
        );

        foreach ($rows as $row) {
            $this->venueSuggestions->decrement($row['opponent_organisme_code'], $row['venue_external_ref']);
        }
    }
}

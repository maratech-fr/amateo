<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Season;
use App\Enum\AuditAction;
use App\Enum\OpponentVenueLinkSource;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Storage\FeedbackScreenshotStorage;
use App\Structure\FamilyCatalog;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RGPD — purge du workspace d'un club effacé (délai de grâce échu).
 *
 * Vide TOUTES les données d'exploitation du tenant : chaque saison via
 * SeasonDataPurger (ligne Season incluse), puis les familles de niveau club que la
 * déclaration range dans l'effacement ({@see FamilyCatalog::purgedWithClub()} — étiquettes,
 * catégories, adhésions, télémétrie, signalements…), effacées par clubId. Une famille
 * épargnée l'est par sa déclaration (#[Family(keptAtErasure: …)]), raison comprise.
 * Le compte rendu CLUB_PURGED (`rowsDeleted`) compte chaque ligne effacée, y compris les
 * créneaux et occupations d'un plan de période que la cascade emportait autrefois hors du compte.
 *
 * ÉPARGNE l'identité publique FFBB du club (décision fondateur 2026-07-11) :
 * nom, ffbbClubCode, logo, ligue/comité, contacts président/correspondant tels
 * que publiés par la FFBB (source FfbbClubPopulator, base légale intérêt
 * légitime — organisation des rencontres, futur annuaire adverse, win-back).
 * MAIS l'adresse du SIÈGE (adresse/CP/ville/lat/lon, saisie par le gestionnaire)
 * n'est PAS de l'identité publique FFBB : elle est remise à NULL (RGPD-02).
 * Les comptes User des membres NE sont PAS touchés : ils appartiennent à leurs
 * titulaires (responsable de traitement = Maratech), qui peuvent les
 * effacer eux-mêmes via DELETE /api/me.
 *
 * Tourne sous le GUC du club (posé par l'appelant, pattern PurgeSeasonsCommand).
 */
final class ErasedClubPurger
{
    use DisablesTenantFilters;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeasonDataPurger $seasonDataPurger,
        private readonly AuditTrail $auditTrail,
        private readonly OpponentVenueSuggestionRepository $venueSuggestions,
        private readonly OrphanAccountNotifier $orphanAccountNotifier,
        private readonly FeedbackScreenshotStorage $feedbackScreenshots,
        private readonly FamilyCatalog $familyCatalog,
    ) {}

    /** @return int nombre de lignes supprimées (workspace complet) */
    public function purge(Club $club): int
    {
        [$deleted, $memberUserIds] = $this->purgeAndCollectMembers($club);
        $this->notifyMembersOfDeletion($memberUserIds);

        return $deleted;
    }

    /**
     * La purge SANS le préavis « espace supprimé » : rend les titulaires des adhésions du club
     * pour que l'appelant qui purge DANS une transaction (app:clubs:purge-erased, sous le verrou
     * d'approbation) ne les prévienne qu'APRÈS le commit ({@see notifyMembersOfDeletion}).
     *
     * @return array{0: int, 1: list<string>} [lignes supprimées, ids des titulaires d'adhésion]
     */
    public function purgeAndCollectMembers(Club $club): array
    {
        $clubId = $club->getId();
        $deleted = 0;

        // P4-301 — les titulaires des adhésions de ce club, COLLECTÉS AVANT le DELETE de
        // masse des `club_user` (étape 2 ci-dessous les supprime). On les préviendra APRÈS
        // la purge (hors toute transaction), variante « espace supprimé » : ceux qui restent
        // sans aucun accès deviennent orphelins. Le dernier gestionnaire qui s'est effacé est
        // déjà anonymisé → isOrphan l'écarte, pas de second mail.
        $memberUserIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT user_id FROM club_user WHERE club_id = :clubId',
            ['clubId' => $clubId],
        );

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

        // RGPD art. 17 — les captures d'écran des signalements vivent HORS table
        // (FeedbackScreenshotStorage, hors RLS) : le DELETE de masse des lignes `feedback`
        // ci-dessous ne les toucherait pas. On détruit chaque blob par id AVANT le DELETE,
        // tant que les lignes existent encore pour en donner les ids. PurgeCompletenessTest
        // garde la ligne `feedback` ; ce geste garde le blob, invisible à ce balayage de tables.
        $this->deleteFeedbackScreenshots($clubId);

        // Les lignes de saison dont la saison n'existe plus : `season_id` n'a aucune clé
        // étrangère, aucune purge de saison ne les voit. Effacées par clubId, enfant avant
        // parent, AVANT les familles de club (un coach orphelin retiendrait sa fiche de
        // personne, clé RESTRICT).
        $deleted += $this->deleteSeasonRowsWithoutSeason($clubId);

        foreach ($this->familyCatalog->purgedWithClub() as $entityClass) {
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
        //
        //    RGPD-02 (lot robustesse) — l'adresse du SIÈGE n'est PAS de l'identité
        //    publique FFBB : c'est la localisation opérationnelle saisie par le
        //    gestionnaire (ClubSiegeController, re-géocodée). Adresse + CP + ville +
        //    latitude + longitude sont remis à NULL à l'effacement — sinon ils
        //    survivaient à la purge, promis détruits et jamais effacés.
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if ($club instanceof Club) {
            $club->setErasureScheduledAt(null);
            $club->setUnsubscribedAt(new DateTimeImmutable);
            $club->setOnboardingCompleted(false);
            $club->setPlanId(null);
            $club->setBillingCycle(null);
            $club->setPlanExpiresAt(null);
            $club->setGenerationCountSeason(0);
            $club->setAddress(null);
            $club->setPostalCode(null);
            $club->setCity(null);
            $club->setLatitude(null);
            $club->setLongitude(null);
            $this->entityManager->flush();
        }
        $this->entityManager->clear();

        // Audit APRÈS le clear (l'insert DBAL ne touche pas l'unit of work).
        $this->auditTrail->record(AuditAction::CLUB_PURGED, null, $clubId, 'Club', $clubId, ['rowsDeleted' => $deleted]);

        return [$deleted, array_values(array_filter($memberUserIds, \is_string(...)))];
    }

    /**
     * P4-301 — préavis « compte sans club » aux membres devenus orphelins par une purge
     * (adhésions du club déjà supprimées). Best-effort, HORS transaction : chaque appel
     * vérifie lui-même isOrphan et n'envoie rien sinon.
     *
     * @param list<string> $memberUserIds
     */
    public function notifyMembersOfDeletion(array $memberUserIds): void
    {
        foreach ($memberUserIds as $userId) {
            $this->orphanAccountNotifier->notifyClubDeleted($userId);
        }
    }

    /**
     * Efface, pour chaque famille de la purge de saison ({@see FamilyCatalog::purgedWithSeason()},
     * enfant avant parent), les lignes du club dont la saison n'est plus en base.
     */
    private function deleteSeasonRowsWithoutSeason(string $clubId): int
    {
        $seasons = $this->entityManager->createQueryBuilder()
            ->select('s.id')
            ->from(Season::class, 's')
            ->where('s.clubId = :clubId')
            ->getDQL();

        $deleted = 0;
        foreach ($this->familyCatalog->purgedWithSeason() as $entityClass) {
            $deleted += (int) $this->entityManager->createQueryBuilder()
                ->delete($entityClass, 'e')
                ->where('e.clubId = :clubId')
                ->andWhere(\sprintf('e.seasonId NOT IN (%s)', $seasons))
                ->setParameter('clubId', $clubId)
                ->getQuery()
                ->execute();
        }

        return $deleted;
    }

    /**
     * Détruit les captures d'écran (blobs hors table) des signalements du club, AVANT le DELETE
     * de masse de leurs lignes. Tourne sous le GUC du club (RLS borne la table tenant) ; on ne
     * lit que les ids des lignes porteuses d'une capture (`has_screenshot = TRUE`).
     */
    private function deleteFeedbackScreenshots(string $clubId): void
    {
        /** @var list<string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM feedback WHERE club_id = :clubId AND has_screenshot = TRUE',
            ['clubId' => $clubId],
        );

        foreach ($ids as $id) {
            $this->feedbackScreenshots->delete($id);
        }
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

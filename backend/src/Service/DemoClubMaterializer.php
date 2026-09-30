<?php

declare(strict_types=1);

namespace App\Service;

use App\Command\DemoCreateCommand;
use App\Controller\DevDemoRegisterController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Exception\DemoTeardownRefusedException;
use App\Service\Basketball\FfbbClubPopulator;
use App\Service\Basketball\FfbbTeamImporter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * P2-4 — le CŒUR de la création d'un club de DÉMONSTRATION, extrait de
 * {@see DemoCreateCommand} pour être partagé avec le raccourci démo
 * du register ({@see DevDemoRegisterController}).
 *
 * `materialize()` est le geste inchangé de la commande (déplacement de l'animateur,
 * club is_demo non onboardé, saison + plan + adhésion MANAGER, populate FFBB +
 * import d'équipes, best-effort synchrone). `teardownPreviousDemo()` n'existe QUE
 * pour la route : il libère le code FFBB du club démo précédent en le PURGEANT puis
 * en SUPPRIMANT sa ligne `club` — la commande garde, elle, son refus d'un ARA déjà
 * pris ({@see DemoCreateCommand}), comportement inchangé.
 *
 * ⚠ RLS : `club_user` est une table hybride (SEC-12) — la LECTURE cross-club est
 * ouverte hors contexte tenant, mais l'ÉCRITURE est tenant-scopée : chaque DELETE/
 * INSERT se fait SOUS le contexte de SON club, sinon la policy l'ignore en silence.
 */
final class DemoClubMaterializer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubProvisioner $clubProvisioner,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly FfbbClubPopulator $ffbbClubPopulator,
        private readonly FfbbTeamImporter $ffbbTeamImporter,
        private readonly ErasedClubPurger $erasedClubPurger,
    ) {}

    /**
     * Crée le club démo et y (re)pointe l'animateur. Comportement IDENTIQUE à celui
     * qu'exécutait DemoCreateCommand. Les deux rappels optionnels laissent la CLI
     * émettre ses notes/avertissements FFBB sans que la logique en dépende (la route
     * les passe à null).
     *
     * @param (callable(string): void)|null $onNote    progression best-effort (import d'équipes)
     * @param (callable(string): void)|null $onWarning échec best-effort (populate / import FFBB)
     */
    public function materialize(string $ffbb, string $name, User $animator, ?callable $onNote = null, ?callable $onWarning = null): Club
    {
        // L'animateur est DÉPLACÉ : ses adhésions précédentes partent (le backend
        // résout le club depuis l'adhésion ACTIVE du JWT — deux actives = ambigu).
        // club_user est une table RLS HYBRIDE : l'ÉCRITURE est tenant-scopée, donc
        // chaque DELETE se fait SOUS le contexte de SON club, sinon la policy
        // l'ignore en silence.
        foreach ($this->entityManager->getRepository(ClubUser::class)->findBy(['userId' => $animator->getId()]) as $membership) {
            $this->tenantConnectionContext->setClubId($membership->getClubId());

            try {
                $this->entityManager->remove($membership);
                $this->entityManager->flush();
            } finally {
                $this->tenantConnectionContext->clear();
            }
        }

        $club = $this->clubProvisioner->createClub(trim($name), $ffbb);
        $club->setIsDemo(true);
        $this->entityManager->flush();
        // Même séquence que l'approbation (ClubApprovalService) : GUC posé dès que
        // le club existe, puis adhésion + workspace sous ce contexte (RLS).
        $this->tenantConnectionContext->setClubId($club->getId());

        try {
            $this->clubProvisioner->createMembership($club->getId(), $animator->getId(), true, ClubRole::MANAGER);
            $this->clubProvisioner->seedWorkspace($club);
            $this->entityManager->flush();

            // Le « boum » : fiche + logo + comité/ligue depuis la FFBB, en direct.
            // Best-effort comme au register — une API FFBB muette ne casse pas la démo,
            // le nom saisi reste.
            try {
                $this->ffbbClubPopulator->populate($club);
                $this->entityManager->flush();
            } catch (Throwable $ffbbError) {
                if (null !== $onWarning) {
                    $onWarning(\sprintf('FFBB populate failed (%s) — the demo club keeps the manual name.', $ffbbError->getMessage()));
                }
            }

            // Les ÉQUIPES engagées, comme au vrai register (PopulateClubFromFfbbHandler
            // fait les deux étages) : sans elles, la démo montrait une étape Équipes VIDE
            // là où un vrai club inscrit l'aurait pleine. Étage best-effort SÉPARÉ : hors
            // saison des poules, 0 équipe = no-op naturel, le prospect saisit comme tout
            // le monde.
            try {
                $created = $this->ffbbTeamImporter->importEngagedTeams($club);
                if (null !== $onNote) {
                    $onNote(0 === $created
                        ? 'FFBB engagements: no team returned (pools not out yet?) — the Teams step starts empty.'
                        : \sprintf('FFBB engagements: %d team(s) created — the Teams step is pre-filled.', $created));
                }
            } catch (Throwable $teamsError) {
                if (null !== $onWarning) {
                    $onWarning(\sprintf('FFBB team import failed (%s) — enter teams manually.', $teamsError->getMessage()));
                }
            }
        } finally {
            $this->tenantConnectionContext->clear();
        }

        return $club;
    }

    /**
     * Libère le code FFBB des clubs démo précédents de l'animateur : chaque adhésion
     * de l'animateur est examinée ; un club NON démo ou un club démo PARTAGÉ (un
     * autre membre) fait échouer le geste SANS RIEN détruire (protège un vrai club et
     * « Démo Basket Club »). Sinon le workspace est purgé sous le GUC du club, puis la
     * ligne `club` elle-même est supprimée (table hors RLS, aucune FK entrante) — sans
     * quoi un futur inscrit hériterait d'un club is_demo par le chemin de reprise RGPD.
     *
     * ⚠ La route SEULE l'appelle : la commande garde son propre refus d'ARA pris.
     *
     * PASSE 1 (validation) lève AVANT que la moindre ligne soit détruite : l'appelant qui
     * catch l'exception n'a donc rien perdu (E-2 — il l'appelle AVANT toute écriture de
     * compte). Retourne les ids des clubs effectivement détruits (trace d'audit M-3).
     *
     * @return list<string> ids des clubs démo libérés
     */
    public function teardownPreviousDemo(User $animator): array
    {
        $animatorId = $animator->getId();

        // PASSE 1 — VALIDER avant de rien détruire : un seul club non-démo ou démo
        // partagé fait échouer TOUT le geste, sans qu'aucun club n'ait été touché.
        $clubIds = [];
        foreach ($this->entityManager->getRepository(ClubUser::class)->findBy(['userId' => $animatorId]) as $membership) {
            $clubId = $membership->getClubId();
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            if (!$club instanceof Club) {
                continue;
            }
            if (!$club->isDemo()) {
                throw new DemoTeardownRefusedException(\sprintf('Refusing to tear down the non-demo club "%s" — a real club is never destroyed.', $club->getName()));
            }
            if ($this->hasOtherMember($clubId, $animatorId)) {
                throw new DemoTeardownRefusedException(\sprintf('Refusing to tear down the shared demo club "%s" — it has members other than the demo animator.', $club->getName()));
            }
            $clubIds[] = $clubId;
        }

        // PASSE 2 — DÉTRUIRE : purge du workspace sous le GUC du club (même routine que
        // l'effacement RGPD, la fiche club survivrait), puis suppression de la ligne club.
        foreach ($clubIds as $clubId) {
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            if (!$club instanceof Club) {
                continue;
            }
            $this->tenantConnectionContext->setClubId($clubId);

            try {
                $this->erasedClubPurger->purge($club);
            } finally {
                $this->tenantConnectionContext->clear();
            }

            // ErasedClubPurger::purge fait un clear() final : re-charger la ligne club
            // dans une unit of work propre avant de la supprimer. La table `club` est
            // hors RLS et sans FK entrante — la suppression est mécaniquement sûre.
            // F-1 (choix fondateur) : la ligne `club` PART (libère le code FFBB, empêche
            // qu'un futur inscrit hérite d'un club is_demo). Les lignes `audit_log` du club
            // deviennent alors orphelines (RLS sur un club_id disparu) — on ne les purge
            // PAS : le journal est append-only (preuve RGPD) et ne contient aucune PII ;
            // la trace LISIBLE du remplacement est l'entrée GLOBALE DEMO_SHORTCUT posée par
            // l'appelant, hors scope club.
            $fresh = $this->entityManager->getRepository(Club::class)->find($clubId);
            if ($fresh instanceof Club) {
                $this->entityManager->remove($fresh);
                $this->entityManager->flush();
            }
        }

        return $clubIds;
    }

    /**
     * Purge NOCTURNE (app:demo:purge-stale) : détruit les clubs démo de l'animateur
     * PROSPECT créés AVANT $cutoff, par le MÊME chemin sûr que teardownPreviousDemo.
     * Différence : ici on ITÈRE plusieurs clubs et un club non éligible est SAUTÉ, jamais
     * détruit (le geste nocturne ne doit pas s'abattre sur tout au premier cas douteux) :
     *  - un club NON démo est sauté (un vrai club n'est JAMAIS détruit) ;
     *  - un club démo PARTAGÉ (un autre membre) est sauté — c'est le garde-fou qui met la
     *    démo BCCL permanente hors d'atteinte quel que soit le chemin (ARA9999999 / compte
     *    demo-bccl@ : elle n'est de toute façon PAS une adhésion de l'animateur prospect) ;
     *  - un club démo créé le JOUR MÊME est gardé ($cutoff = début du jour courant) : la
     *    réactivation de la fenêtre le même jour réutilise ce club, jamais un neuf.
     * Détruire = purger le workspace sous le GUC du club puis supprimer la ligne club (libère
     * le code FFBB du prospect), exactement comme la passe 2 de teardownPreviousDemo.
     *
     * @return list<string> ids des clubs démo effectivement libérés
     */
    public function teardownStaleDemos(User $animator, DateTimeImmutable $cutoff): array
    {
        $animatorId = $animator->getId();

        // PASSE 1 — sélection + validation, aucune destruction : on ne retient que les
        // clubs démo, non partagés, créés avant le cutoff. Tout le reste est SAUTÉ.
        $clubIds = [];
        foreach ($this->entityManager->getRepository(ClubUser::class)->findBy(['userId' => $animatorId]) as $membership) {
            $club = $this->entityManager->getRepository(Club::class)->find($membership->getClubId());
            if (!$club instanceof Club) {
                continue;
            }
            if (!$club->isDemo() || $club->getCreatedAt() >= $cutoff) {
                continue;
            }
            if ($this->hasOtherMember($club->getId(), $animatorId)) {
                continue;
            }
            $clubIds[] = $club->getId();
        }

        // PASSE 2 — destruction (miroir de la passe 2 de teardownPreviousDemo) : purge du
        // workspace sous le GUC du club, puis suppression de la ligne club dans une unit of
        // work propre. La table `club` est hors RLS et sans FK entrante — suppression sûre.
        foreach ($clubIds as $clubId) {
            $club = $this->entityManager->getRepository(Club::class)->find($clubId);
            if (!$club instanceof Club) {
                continue;
            }
            $this->tenantConnectionContext->setClubId($clubId);

            try {
                $this->erasedClubPurger->purge($club);
            } finally {
                $this->tenantConnectionContext->clear();
            }

            $fresh = $this->entityManager->getRepository(Club::class)->find($clubId);
            if ($fresh instanceof Club) {
                $this->entityManager->remove($fresh);
                $this->entityManager->flush();
            }
        }

        return $clubIds;
    }

    /** Le club porte-t-il un membre autre que l'animateur ? (raw DBAL — club_user se lit cross-tenant). */
    private function hasOtherMember(string $clubId, string $animatorId): bool
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM club_user WHERE club_id = :cid AND user_id <> :uid',
            ['cid' => $clubId, 'uid' => $animatorId],
        );

        return (int) $count > 0;
    }
}

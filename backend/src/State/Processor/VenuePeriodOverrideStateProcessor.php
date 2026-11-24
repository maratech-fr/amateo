<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenuePeriodOverrideResource;
use App\Dto\VenuePeriodOverrideInput;
use App\Entity\PlanVenue;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\VenuePeriodMode;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\VenuePeriodGrid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Le MODE + le MASQUE d'un gymnase pour une période (décisions fondateur 2026-07-24 puis
 * 2026-08-18 « l'indisponibilité devient informative »), écrits sur la ligne du gymnase dans
 * le plan de période (lot 4, PR 3b : chaque gymnase a déjà sa ligne dans chaque plan de
 * période). Publier le réglage d'un couple (période, gymnase) met sa ligne à jour, même
 * réponse qu'une création ; le plan et le gymnase doivent être du club : un couple sans ligne
 * est refusé, rien n'est écrit.
 *
 * Une période POSSÈDE sa grille : ses créneaux ont été copiés depuis la saison à la
 * naissance du plan, et un gymnase n'a jamais deux jeux de créneaux dans une période.
 * Le `mode` est donc une ACTION sur cette grille, bornée à UN gymnase :
 *  - VIERGE (BLANK, « effet de fermeture levé ») : on vide le gymnase, le gestionnaire
 *    ressaisit tout ;
 *  - HÉRITER (défaut) : la ligne reprend le défaut (DELETE) ; depuis VIERGE, on vide le
 *    gymnase puis on RECOPIE le modèle de saison ;
 *  - DÉSACTIVÉ (« ne participe pas ») : le gymnase ne sert pas ; ses créneaux restent mais il
 *    sort du payload et des sélecteurs (« tout est lié au gymnase, son indisponibilité les
 *    impacte »).
 * Seul un CHANGEMENT de mode agit sur la grille : republier le mode déjà en place est sans
 * effet (rejouer la purge détruirait les créneaux que le gestionnaire vient de saisir à la
 * main — revue #8, une action d'apparence idempotente était destructrice à chaque répétition).
 *
 * Le `dayOverrides` (masque manuel tri-état) n'agit PAS sur la grille : c'est une métadonnée
 * SPARSE que la composition ({@see App\Service\PlanVenueClosures}) lit à la génération. Le
 * changer ne vide ni ne recopie rien — seul le `mode` touche la grille.
 *
 * Vider passe par EntityCascadeDeleter::purgeChildrenOfSlot : supprimer un créneau
 * emporte ses réservations et les verrous qu'elles ont matérialisés. Un `remove()` nu
 * les laisserait orphelins (défaut relevé en revue de #285).
 *
 * @extends AbstractStateProcessor<PlanVenue, VenuePeriodOverrideInput, VenuePeriodOverrideResource>
 */
class VenuePeriodOverrideStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    /** Le mode de la ligne avant la publication en cours, relevé par {@see createEntityFromInput}. */
    private ?VenuePeriodMode $modeBeforePost = null;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly VenuePeriodGrid $venuePeriodGrid,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return PlanVenue::class;
    }

    /**
     * @param VenuePeriodOverrideInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        // L'existence du plan se contrôle AVANT la fermeture : le garde de fermeture
        // interroge la base avec l'id reçu, et un id vide sur une colonne `guid` remonterait
        // un 500 opaque au lieu du 422 attendu. La validation du DTO l'interdit aujourd'hui,
        // mais l'ordre rend l'invariant vrai par construction plutôt que par dépendance —
        // relevé en revue sécurité (2026-08-18). Un plan absent est refusé par la validation du DTO.
        if (null !== $input->schedulePlanId) {
            $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
        }

        $this->modeBeforePost = null;
        $output = $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            /** @var VenuePeriodOverrideResource $output */
            // P4-34 — filet de la COURSE : entre le contrôle d'existence et le flush, le plan
            // peut disparaître (suppression de période, reprise du socle). La FK remonterait
            // alors une violation que personne n'attrape → 500 opaque. Même 422 qu'un plan
            // déjà absent au contrôle.
            $output = $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
            if ($this->modeBeforePost?->value !== $output->mode) {
                $this->applyMode($output);
            }

            return $output;
        });

        // RGPD — réglage d'un gymnase de période : trace `grid.changed` avec le mode avant et après.
        $this->recordGridChanged($clubId, $output, before: $this->modeBeforePost?->value);

        return $output;
    }

    /**
     * @param array<string, mixed>     $uriVariables
     * @param VenuePeriodOverrideInput $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        $id = $uriVariables['id'] ?? null;
        $existing = \is_string($id) ? $this->entityManager->getRepository(PlanVenue::class)->find($id) : null;
        $before = $existing?->mode();

        $output = $this->entityManager->wrapInTransaction(function () use ($input, $uriVariables, $clubId, $seasonId, $before): object {
            /** @var VenuePeriodOverrideResource $output */
            $output = $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPut($input, $uriVariables, $clubId, $seasonId));
            // Seul un CHANGEMENT de mode agit sur la grille (voir la classe).
            if ($before?->value !== $output->mode) {
                $this->applyMode($output);
            }

            return $output;
        });

        // RGPD — changement de réglage d'un gymnase de période : trace `grid.changed` avec le
        // mode AVANT (relevé pré-écriture) et APRÈS (la ressource de sortie).
        $this->recordGridChanged($clubId, $output, before: $before?->value);

        return $output;
    }

    /**
     * DELETE = retour au défaut « hériter » : la ligne reprend le défaut (participe, fermeture
     * non levée, sans masque), elle ne disparaît pas (tout gymnase a sa ligne dans chaque plan
     * de période). Ce que cela coûte dépend de l'état qu'on quitte :
     *
     *  - depuis VIERGE, la grille du gymnase a été VIDÉE : il n'y a rien à préserver et
     *    « hériter » veut dire reprendre le modèle de saison — on vide (ce qui a pu être
     *    ressaisi à la main) puis on RECOPIE. L'ordre importe : recopier avant de vider
     *    supprimerait la copie fraîche.
     *  - depuis DÉSACTIVÉ, la grille est INTACTE — c'est toute la promesse du mode
     *    (« réactiver rend la grille telle quelle ; désactiver ne doit pas coûter la
     *    saisie qu'on avait faite »). Or revenir au défaut est la SEULE façon de
     *    réactiver un gymnase. Y rejouer la purge détruisait les réservations et les
     *    verrous HARD que le gestionnaire avait posés avant de désactiver, en lui
     *    rendant une grille d'apparence identique et sans un mot (revue #8, round 4).
     *    Réactiver ne touche donc à rien.
     *
     * « Reprendre la grille de saison » sur un gymnase désactivé n'est pas ce DELETE — c'est
     * un passage explicite par VIERGE, avec sa confirmation, puisque c'est une destruction.
     *
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $line = $this->entityManager->find(PlanVenue::class, $uriVariables['id'] ?? null);
        if (!$line instanceof PlanVenue || (null !== $clubId && $line->getClubId() !== $clubId)) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
        $wasBlank = VenuePeriodMode::BLANK === $line->mode();

        $this->entityManager->wrapInTransaction(function () use ($line, $wasBlank): void {
            $line->setParticipating(true)->setClosureLifted(false)->setDayOverrides(null);
            $this->entityManager->flush();
            if ($wasBlank) {
                $this->venuePeriodGrid->resetFromSeason($line->getSchedulePlanId(), $line->getVenueId());
            }
        });

        // RGPD — retirer un réglage de gymnase de période reste une suppression tracée (patron du parent).
        $actor = $this->actorSecurity?->getUser();
        $this->auditTrail?->record(AuditAction::ENTITY_DELETED, $actor instanceof User ? $actor->getId() : null, $clubId, 'VenuePeriodOverride', $line->getId());
    }

    /**
     * Le couple (période, gymnase) a déjà sa ligne : la publier la met à jour.
     *
     * @param VenuePeriodOverrideInput $input
     */
    protected function createEntityFromInput(object $input): PlanVenue
    {
        if (null === $input->schedulePlanId || null === $input->venueId) {
            $this->refuse('Le réglage d\'un gymnase pour une période nomme la période et le gymnase.');
        }

        // Le mode est un réglage DE PÉRIODE. Le viser sur le plan de saison rendrait ses
        // actions destructrices : « vierge » viderait la grille du planning principal, et
        // le retour à « hériter » recopierait les créneaux de saison PAR-DESSUS eux-mêmes.
        // Le planning principal n'est JAMAIS modifié par une période (invariant n°1) —
        // on le rend impossible ici plutôt que d'en dépendre côté UI.
        if ($this->schedulePlanProvisioner->planIsSeason($input->schedulePlanId)) {
            $this->refuse('Un mode de gymnase se règle sur une période, pas sur le planning de la saison.');
        }

        // P4-34 — l'ANCRE doit exister, et être du club : lue sous le filtre du club.
        $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
        // La ligne est lue sous le filtre du club : un gymnase d'un autre club n'en a aucune dans ce plan.
        $line = $this->entityManager->getRepository(PlanVenue::class)->findOneBy(['schedulePlanId' => $input->schedulePlanId, 'venueId' => $input->venueId]);
        if (!$line instanceof PlanVenue) {
            $this->refuse('Ce gymnase n\'appartient pas à la saison de cette période.');
        }
        $this->modeBeforePost = $line->mode();
        $this->writeInput($line, $input);

        return $line;
    }

    /**
     * @param PlanVenue                $entity
     * @param VenuePeriodOverrideInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // schedulePlanId + venueId identifient la ligne — jamais remappés à l'édition. Le PUT
        // REMPLACE les réglages : mode et masque suivent le corps (omis = effacé), la validation
        // du DTO garantit qu'au moins l'un des deux reste présent.
        $this->writeInput($entity, $input);
    }

    /**
     * @param PlanVenue $entity
     */
    protected function mapEntityToOutput(object $entity): VenuePeriodOverrideResource
    {
        return VenuePeriodOverrideResource::fromEntity($entity);
    }

    /**
     * Le mode publié devient les deux réglages de la ligne — DISABLED : ne participe pas ;
     * BLANK : effet de fermeture levé ; aucun : participe, fermeture non levée — et le masque
     * la remplace.
     */
    private function writeInput(PlanVenue $line, VenuePeriodOverrideInput $input): void
    {
        $mode = null === $input->mode ? null : VenuePeriodMode::from($input->mode);
        $line->setParticipating(VenuePeriodMode::DISABLED !== $mode)
            ->setClosureLifted(VenuePeriodMode::BLANK === $mode)
            ->setDayOverrides($this->normalizeMask($input->dayOverrides));
    }

    /**
     * RGPD — écrit UNE ligne `grid.changed` pour un réglage de gymnase de période (pose ou
     * changement). Details sans PII : id du gymnase, plan de période, mode avant/après.
     */
    private function recordGridChanged(?string $clubId, VenuePeriodOverrideResource $output, ?string $before): void
    {
        $actor = $this->actorSecurity?->getUser();
        $this->auditTrail?->record(
            AuditAction::GRID_CHANGED,
            $actor instanceof User ? $actor->getId() : null,
            $clubId,
            'VenuePeriodOverride',
            $output->id,
            [
                'venueId' => $output->venueId,
                'schedulePlanId' => $output->schedulePlanId,
                'before' => $before,
                'after' => $output->mode,
            ],
        );
    }

    /**
     * Le masque validé, réduit à ses clés jour ISO (int) et à null quand il est vide — pas de
     * `[]` stocké qui se lirait comme un masque « présent mais sans jour ».
     *
     * @param array<int|string, string>|null $mask
     *
     * @return array<int, string>|null
     */
    private function normalizeMask(?array $mask): ?array
    {
        if (null === $mask || [] === $mask) {
            return null;
        }
        $normalized = [];
        foreach ($mask as $day => $state) {
            $normalized[(int) $day] = $state;
        }

        return $normalized;
    }

    /**
     * L'ancre vient de la ressource PERSISTÉE, jamais du corps de la requête : au PUT,
     * schedulePlanId/venueId identifient la ligne et ne sont pas remappés — s'y fier
     * viderait la grille d'une AUTRE période (défaut relevé en revue de #285).
     */
    private function applyMode(VenuePeriodOverrideResource $output): void
    {
        // DÉSACTIVÉ CONSERVE LA GRILLE, il ne la SERT pas (décision fondateur
        // 2026-07-24) : le gestionnaire voit à l'écran ce qu'il a désactivé, et le
        // gymnase est simplement absent du payload envoyé à l'engine
        // (ScheduleConstraintBuilder::buildForOverlay). Réactiver rend la grille telle
        // quelle — désactiver ne doit pas coûter la saisie qu'on avait faite.
        // VIERGE, lui, vide bel et bien : c'est ce qu'on lui demande.
        if (VenuePeriodMode::BLANK->value === $output->mode) {
            $this->venuePeriodGrid->clear($output->schedulePlanId, $output->venueId);
        }
    }
}

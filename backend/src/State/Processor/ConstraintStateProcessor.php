<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ConstraintResource;
use App\Dto\ConstraintInput;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Team;
use App\Entity\TeamTag;
use App\Entity\Venue;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\ConstraintConfigValidator;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\TeamTagResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @extends AbstractStateProcessor<Constraint, ConstraintInput, ConstraintResource>
 */
class ConstraintStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly ConstraintConfigValidator $configValidator,
        private readonly TeamTagResolver $tagResolver,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return Constraint::class;
    }

    // Plus d'afterPersist ici : depuis « le plan de période naît du geste d'Adapter » le plan
    // n'est pas encore créé au POST de la datée `venue_closed`, donc le recalage de nom qui
    // vivait ici était INOPÉRANT (0 ligne touchée). Et il n'a plus d'objet : le nom du plan de
    // période naît désormais du TITRE de son entrée de calendrier (décision fondateur 2026-08-23),
    // il n'y a plus de nom générique à recaler quand le gymnase devient connu.

    /**
     * @param ConstraintInput $input
     */
    protected function createEntityFromInput(object $input): Constraint
    {
        $entity = new Constraint;
        $entity->setName($input->name ?? '');
        $entity->setDescription($input->description);
        $entity->setScope($this->parseScope($input->scope));
        $entity->setScopeTargetId($input->scopeTargetId);
        $entity->setFamily($this->parseFamily($input->family));
        $entity->setRuleType($this->parseRuleType($input->ruleType));
        // SEC-13 : le `config` était le seul champ sans contrôle — une clé mal
        // orthographiée entrait en base et le solveur l'ignorait en silence.
        $this->assertConfigIsValid($entity->getFamily(), $input->config ?? []);
        $entity->setConfig($input->config ?? []);
        $entity->setCreatedBy($input->createdBy);
        $entity->setSource($input->source);
        $entity->setSourceOccurrenceId($input->sourceOccurrenceId);
        $entity->setCalendarEntryId($input->calendarEntryId);
        $entity->setIsActive($input->isActive ?? true);
        $entity->setSortOrder($input->sortOrder ?? 0);

        return $entity;
    }

    /**
     * @param Constraint      $entity
     * @param ConstraintInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->description) {
            $entity->setDescription($input->description);
        }
        if (null !== $input->scope) {
            $entity->setScope($this->parseScope($input->scope));
        }
        if (null !== $input->scopeTargetId) {
            $entity->setScopeTargetId($input->scopeTargetId);
        }
        // Invariant: a CLUB-scoped constraint has NO target. A PUT that widens a
        // TEAM/COACH rule to CLUB sends scopeTargetId=null, but a null field means
        // "leave unchanged" above — so clear it explicitly, else a stale target id
        // survives with scope=CLUB (mis-read by expandClosedVenues, ScheduleConstraintBuilder).
        if (ConstraintScope::CLUB === $entity->getScope()) {
            $entity->setScopeTargetId(null);
        }
        if (null !== $input->family) {
            $entity->setFamily($this->parseFamily($input->family));
        }
        if (null !== $input->ruleType) {
            $entity->setRuleType($this->parseRuleType($input->ruleType));
        }
        if (null !== $input->config) {
            // La famille FINALE, pas celle du payload : un PUT peut changer l'une
            // sans l'autre, et un `config` valide pour TIME ne l'est pas pour DAY.
            $this->assertConfigIsValid($entity->getFamily(), $input->config);
            $entity->setConfig($input->config);
        }
        if (null !== $input->createdBy) {
            $entity->setCreatedBy($input->createdBy);
        }
        if (null !== $input->source) {
            $entity->setSource($input->source);
        }
        if (null !== $input->sourceOccurrenceId) {
            $entity->setSourceOccurrenceId($input->sourceOccurrenceId);
        }
        if (null !== $input->calendarEntryId) {
            $entity->setCalendarEntryId($input->calendarEntryId);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->sortOrder) {
            $entity->setSortOrder($input->sortOrder);
        }
    }

    /**
     * @param Constraint $entity
     */
    protected function mapEntityToOutput(object $entity): ConstraintResource
    {
        return ConstraintResource::fromEntity($entity);
    }

    /**
     * @param Constraint $entity
     */
    protected function cascadeBeforeDelete(object $entity): void
    {
        // A period may have disabled this permanent constraint for its window; those
        // toggles are keyed on constraintId and would orphan on a bare delete.
        foreach ($this->entityManager->getRepository(ConstraintPeriodOverride::class)->findBy(['constraintId' => $entity->getId()]) as $override) {
            $this->entityManager->remove($override);
        }
    }

    /**
     * SEC-13 — refuse un `config` hors liste blanche, en NOMMANT la clé fautive.
     *
     * 422 et non 400 : la requête est bien formée, c'est son contenu métier qui
     * est irrecevable — même sémantique que les autres refus de validation de
     * l'API. Le message est destiné au gestionnaire ET au développeur : il liste
     * les réglages acceptés pour la famille, ce qui suffit à corriger une faute
     * de frappe sans ouvrir le code.
     *
     * @param array<string, mixed> $config
     */
    private function assertConfigIsValid(ConstraintFamily $family, array $config): void
    {
        $errors = $this->configValidator->errors($family, $config);
        if ([] !== $errors) {
            throw new UnprocessableEntityHttpException(implode(' ', $errors));
        }

        $this->assertVenuesExist($config);
        $this->assertTagsResolve($config);
    }

    /**
     * P2-29 D10 — la NOUVELLE forme de ciblage par tag (`targetTags`/`excludeTags`) est
     * jugée CONTRE LA SAISON COURANTE : un groupe inconnu du club, ou une résolution vide
     * (intersection sans équipe commune, exclusion qui vide la cible), sont refusés à
     * l'écriture — le gestionnaire saurait tout de suite que la contrainte n'aurait aucun
     * effet, au lieu de la découvrir muette au solve.
     *
     * ⚠ Le legacy `targetTag` (un seul tag) GARDE son comportement : aucune validation DB,
     * rétro-compat stricte — un tag inconnu y reste un NO-OP + warning au build. La nouvelle
     * forme seule (présence de `targetTags`/`excludeTags`) déclenche ce contrôle.
     *
     * Comme `assertVenuesExist`, on s'appuie sur le `TenantFilter` (listener priorité 7,
     * après le firewall) : la lecture est déjà bornée au club courant, un tag d'un autre
     * club se signale INCONNU, jamais « interdit ».
     *
     * @param array<string, mixed> $config
     */
    private function assertTagsResolve(array $config): void
    {
        $isNewForm = \array_key_exists('targetTags', $config) || \array_key_exists('excludeTags', $config);
        if (!$isNewForm) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return; // hors contexte HTTP tenant (fixtures, CLI) : rien à résoudre ici.
        }
        $rawSeason = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');
        $seasonId = $this->resolveSeasonId($clubId, \is_string($rawSeason) ? $rawSeason : null);
        if (!\is_string($seasonId) || '' === $seasonId) {
            return;
        }

        // Groupe inconnu du club → 422 (le TenantFilter borne au club courant).
        foreach ([...TeamTagResolver::targetTagNames($config), ...TeamTagResolver::excludeTagNames($config)] as $tagName) {
            if (!$this->entityManager->getRepository(TeamTag::class)->findOneBy(['name' => $tagName]) instanceof TeamTag) {
                throw new UnprocessableEntityHttpException(\sprintf('Le groupe « %s » n\'existe pas dans ce club.', $tagName));
            }
        }

        // Résolution vide contre la saison courante → 422 nommant les groupes.
        $seasonTeamIds = array_map(
            static fn (Team $team): string => $team->getId(),
            $this->entityManager->getRepository(Team::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]),
        );
        if ([] === $this->tagResolver->resolveConstraintTeamIds($config, $seasonId, $clubId, $seasonTeamIds)) {
            throw new UnprocessableEntityHttpException(\sprintf('Le ciblage « %s » ne désigne aucune équipe cette saison — la contrainte n\'aurait aucun effet.', TeamTagResolver::tagTargetLabel($config)));
        }
    }

    /**
     * AUD-BCK-13 — un UUID de gymnase du `config` était validé EN FORME seulement.
     *
     * ⚑ Mesuré sur le moteur avant d'écrire une ligne : un `forcedVenueId` qui ne
     * correspond à aucun gymnase du payload fait forcer à 0 **toutes** les affectations de
     * l'équipe (`add_forced_venue_constraints` : « quand un gymnase est imposé, tous les
     * autres sont fixés à 0 » — et aucun n'est celui-là). Résultat observé : `completed`,
     * **zéro créneau**, l'équipe absente du planning.
     *
     * ⚠ Et le diagnostic ment sur la cause. Il tombe bien en ERROR, mais annonce « tous les
     * créneaux compatibles étaient déjà occupés par des équipes plus prioritaires, ou en
     * conflit avec ses contraintes (coach indisponible, gymnase fermé, jour interdit) » —
     * quatre pistes, toutes fausses. Le gestionnaire chercherait une indisponibilité qui
     * n'existe pas.
     *
     * Aucune fuite : RLS borne la lecture, un UUID étranger ne rend rien. Le défaut est
     * qu'on ENREGISTRE une contrainte inapplicable au lieu de la refuser tout de suite.
     *
     * @param array<string, mixed> $config
     */
    private function assertVenuesExist(array $config): void
    {
        // La liste des clés porteuses d'un uuid de gymnase se DÉRIVE du SPEC du
        // validateur (`uuidKeys()`), jamais d'une copie : c'est déjà le foyer que D-??
        // avait établi après avoir trouvé deux listes divergentes ailleurs.
        foreach ($this->configValidator->uuidKeys() as $key) {
            $venueId = $config[$key] ?? null;
            if (!\is_string($venueId) || '' === $venueId) {
                continue;
            }

            // ⚑ Aucun `clubId` passé ici, et c'est VOULU : le `TenantFilter` Doctrine est
            // déjà actif (listener priorité 7, après le firewall) et borne la requête au
            // club courant. Un gymnase d'un autre club ne se trouve donc pas, et se
            // signale comme INCONNU — jamais comme « interdit », ce qui révélerait son
            // existence. Re-filtrer à la main dupliquerait la frontière tenant et ferait
            // croire qu'elle se tient ici.
            $venue = $this->entityManager->getRepository(Venue::class)->findOneBy(['id' => $venueId]);
            if (!$venue instanceof Venue) {
                throw new UnprocessableEntityHttpException(\sprintf('« %s » désigne un gymnase inconnu (%s). Une contrainte qui impose un gymnase inexistant rend l\'équipe impossible à placer, sans que le planning le dise.', $key, $venueId));
            }
        }
    }

    /**
     * AUD-BCK-12 — ces trois champs retombaient EN SILENCE sur CLUB / TIME / HARD.
     *
     * ⚑ **Le finding est réfuté sur le chemin réel** : `ConstraintInput` porte
     * `Assert\NotBlank` + `Assert\Choice` sur les trois, donc un `family` fautif est
     * rejeté en 422 par le validateur AVANT d'atteindre ce processeur — mesuré, pas
     * déduit (`ConstraintApiTest::testAnInvalidEnumValueIsRefusedNeverSilentlyCoerced`).
     * Le repli était donc inatteignable, pas dangereux.
     *
     * ⚠ Il restait néanmoins **muet sur sa propre inutilité** : rien ne reliait le repli à
     * la validation qui le rend mort. Le jour où un `Assert\Choice` saute — un DTO
     * réécrit, une famille ajoutée sans son entrée — une contrainte « DAYS » deviendrait
     * une contrainte TIME, enregistrée, envoyée au solveur, et honorée comme telle. Le
     * gestionnaire aurait posé une règle de jour et obtenu une règle d'heure, sans un mot.
     *
     * Défense en profondeur : on échoue bruyamment. Cette exception ne peut pas se
     * déclencher aujourd'hui ; c'est exactement ce qu'on veut d'un filet.
     */
    private function parseScope(?string $value): ConstraintScope
    {
        return ConstraintScope::tryFrom($value ?? '') ?? throw $this->unknownEnumValue('scope', $value, ConstraintScope::values());
    }

    private function parseFamily(?string $value): ConstraintFamily
    {
        return ConstraintFamily::tryFrom($value ?? '') ?? throw $this->unknownEnumValue('family', $value, ConstraintFamily::values());
    }

    private function parseRuleType(?string $value): ConstraintRuleType
    {
        return ConstraintRuleType::tryFrom($value ?? '') ?? throw $this->unknownEnumValue('ruleType', $value, ConstraintRuleType::values());
    }
}

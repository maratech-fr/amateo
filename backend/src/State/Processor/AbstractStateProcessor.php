<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Service\AuditTrail;
use App\Service\EntityCascadeDeleter;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Error;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @template TEntity of object
 * @template TInput of object
 * @template TOutput of object
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
abstract class AbstractStateProcessor implements ProcessorInterface
{
    /**
     * Set via #[Required] (not the constructor) so the four cascade-delete
     * subclasses share it without every processor rewriting the base ctor.
     */
    protected ?EntityCascadeDeleter $cascadeDeleter = null;

    protected ?AuditTrail $auditTrail = null;

    protected ?Security $actorSecurity = null;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly RequestStack $requestStack,
        protected readonly SeasonResolver $seasonResolver,
        protected readonly SeasonAccessGuard $seasonAccessGuard,
        protected readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Required]
    public function setCascadeDeleter(EntityCascadeDeleter $cascadeDeleter): void
    {
        $this->cascadeDeleter = $cascadeDeleter;
    }

    #[Required]
    public function setAuditTrail(AuditTrail $auditTrail): void
    {
        $this->auditTrail = $auditTrail;
    }

    #[Required]
    public function setActorSecurity(Security $actorSecurity): void
    {
        $this->actorSecurity = $actorSecurity;
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');

        // SEC-07 — before the season guard so 403 wins over 409 (Import idiom).
        if ($this->requiresManagementRole()) {
            $this->managementAccessGuard->assertManager();
        }

        // Archived-season writes are refused (409). Only season-scoped entities
        // are gated — Club/User/Season carry no seasonId and stay editable.
        // SEC-13 : on passe aussi la saison de l'ENTITÉ ciblée, pas seulement celle
        // du header. Inobservable en HTTP aujourd'hui (le provider est déjà
        // season-filtré), mais une garde qui ne vaut que sur un chemin est une garde
        // qu'on croira valable sur les deux ; falsifiée au niveau unitaire.
        if (method_exists($this->getEntityClass(), 'setSeasonId')) {
            $this->seasonAccessGuard->assertWritable($request, $this->targetSeasonIdOf($data));
        }

        if ($operation instanceof DeleteOperationInterface) {
            $this->processDelete($uriVariables, $clubId);

            return null;
        }

        $method = $operation instanceof HttpOperation ? $operation->getMethod() : '';
        if ('POST' === $method) {
            return $this->processPost($data, $clubId, $seasonId);
        }

        if (\in_array($method, ['PUT', 'PATCH'], true)) {
            return $this->processPut($data, $uriVariables, $clubId, $seasonId);
        }

        return $data;
    }

    /**
     * P1-1: the club role model is in place — every API Platform write is
     * management by default. Any POST/PUT/PATCH/DELETE requires an owner/admin
     * membership (ManagementAccessGuard). Wizard/cockpit entities are all
     * management-sensitive, so this is the correct default; a processor only
     * opts OUT explicitly, with its reason (see UserStateProcessor: self-edit).
     *
     * (The former opt-in `true` overrides on the six cockpit processors are now
     * redundant but kept — removing them is out of this PR's scope.)
     */
    protected function requiresManagementRole(): bool
    {
        return true;
    }

    /**
     * @return class-string<TEntity>
     */
    abstract protected function getEntityClass(): string;

    /**
     * @param TInput $input
     *
     * @return TEntity
     */
    abstract protected function createEntityFromInput(object $input): object;

    /**
     * @param TEntity $entity
     * @param TInput  $input
     */
    abstract protected function updateEntityFromInput(object $entity, object $input): void;

    /**
     * @param TEntity $entity
     *
     * @return TOutput
     */
    abstract protected function mapEntityToOutput(object $entity): object;

    protected function resolveSeasonId(?string $clubId, ?string $seasonId): ?string
    {
        if (null !== $seasonId) {
            return $seasonId;
        }

        if (null === $clubId) {
            return null;
        }

        // Fallback when the listener set no _season_id (e.g. non-HTTP context):
        // the calendar-derived current season, same rule as the listener.
        $season = $this->seasonResolver->currentSeason($clubId);

        return $season?->getId();
    }

    /**
     * @param TInput $input
     *
     * @return TOutput
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        $entity = $this->createEntityFromInput($input);
        $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);

        if (null !== $clubId && $entity instanceof TenantOwnedInterface) {
            $entity->setClubId($clubId);
        }
        if (null !== $resolvedSeasonId && method_exists($entity, 'setSeasonId')) {
            $entity->setSeasonId($resolvedSeasonId);
        }

        $this->entityManager->persist($entity);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // P4-67 — les contraintes d'unicité en base (Competition, TeamTag…)
            // transforment un doublon en erreur SQL : la nommer en 409 plutôt
            // que de laisser fuir un 500 (le doublon vient toujours du client —
            // double-clic, re-soumission).
            throw new ConflictHttpException('Cet élément existe déjà (doublon).');
        }
        $this->afterPersist($entity);

        return $this->mapEntityToOutput($entity);
    }

    /**
     * @param TInput               $input
     * @param array<string, mixed> $uriVariables
     *
     * @return TOutput
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        $id = $uriVariables['id'] ?? null;
        $entity = $this->entityManager->find($this->getEntityClass(), $id);

        if (!$entity) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        if (null !== $clubId && $entity instanceof TenantOwnedInterface && $entity->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Accès refusé.');
        }

        // BCK-09: a PUT must NEVER migrate an existing row to the request's current
        // season — that silently moves data across seasons (same club). Only stamp a
        // season if the entity somehow has none; never override its own.
        if (method_exists($entity, 'setSeasonId') && method_exists($entity, 'getSeasonId') && null === $entity->getSeasonId()) {
            $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);
            if (null !== $resolvedSeasonId) {
                $entity->setSeasonId($resolvedSeasonId);
            }
        }

        $this->assertNotStale($entity);

        $this->updateEntityFromInput($entity, $input);
        $this->entityManager->flush();
        $this->afterPersist($entity);

        return $this->mapEntityToOutput($entity);
    }

    /** @param array<string, mixed> $uriVariables */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $id = $uriVariables['id'] ?? null;
        $entity = $this->entityManager->find($this->getEntityClass(), $id);

        if (!$entity) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        if (null !== $clubId && $entity instanceof TenantOwnedInterface && $entity->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Accès refusé.');
        }

        // Entities carry no ORM/DB cascade — a subclass may purge the deleted
        // row's logical children (reservations, coach links, constraints…) here
        // so a bare delete never orphans them. No-op by default.
        $this->cascadeBeforeDelete($entity);

        $this->entityManager->remove($entity);
        $this->entityManager->flush();

        // RGPD audit trail : toute suppression API d'entité passe ici — un seul
        // point d'écriture couvre coach/équipe/gymnase/planning/contrainte….
        $actor = $this->actorSecurity?->getUser();
        $this->auditTrail?->record(
            AuditAction::ENTITY_DELETED,
            $actor instanceof User ? $actor->getId() : null,
            $clubId,
            new ReflectionClass($this->getEntityClass())->getShortName(),
            \is_string($id) ? $id : null,
        );
    }

    /** Purge the logical children of $entity before it is removed. No-op unless overridden. */
    protected function cascadeBeforeDelete(object $entity): void {}

    /**
     * Hook post-flush (POST/PUT) — no-op par défaut. Permet à un processor de réagir à un
     * effet de bord une fois l'entité persistée (ex. recaler un nom dérivé d'une donnée qui
     * arrive dans une requête voisine). Ne jamais y placer de logique métier obligatoire.
     */
    protected function afterPersist(object $entity): void {}

    /**
     * Une valeur d'enum PRÉSENTE mais inconnue → 422 nommé, jamais un repli silencieux.
     *
     * ⚑ Maison unique du libellé depuis AUD-BCK-14 : deux processeurs le rendent (contraintes
     * et entrées de calendrier), et deux formulations qui dérivent, c'est un message d'erreur
     * qui ne se reconnaît plus d'un écran à l'autre.
     *
     * @param list<string> $accepted
     */
    protected function unknownEnumValue(string $field, ?string $value, array $accepted): UnprocessableEntityHttpException
    {
        return new UnprocessableEntityHttpException(\sprintf(
            '« %s » n\'est pas une valeur connue pour %s. Valeurs acceptées : %s.',
            $value ?? '(absent)',
            $field,
            implode(', ', $accepted),
        ));
    }

    /**
     * 422 NOMMÉ — maison unique du refus de saisie. ⚠ `new ValidationException('chaîne')` (le
     * constructeur chaîne d'API Platform) construit une `ConstraintViolationList` VIDE, et le
     * normalizer dérive `detail` + `violations[]` EXCLUSIVEMENT de cette liste : un message nu
     * rend `violations: []`, `detail: ""` et le titre générique « An error occurred » — la cause
     * n'atteint JAMAIS l'écran. Le front lit `detail` puis `violations[].message`
     * (`errorMessage.ts`) : seule une VRAIE `ConstraintViolationList` voyage jusqu'au toast.
     * Doctrine P5-14 : jamais « une erreur est survenue » quand la cause est connue.
     *
     * Pas de `propertyPath` : aucun des sites appelants n'en portait, et le front ne le lit pas.
     * Le jour où un champ devra être surligné, l'ajouter ici est une ligne — une seule fois.
     *
     * L'idiome nu `new ValidationException('…')` est INTERDIT hors de cette classe
     * (`ValidationExceptionCarriesViolationsTest`) : une liste ad hoc construite ailleurs
     * recréerait une deuxième maison du même piège muet.
     */
    protected function refuse(string $message): never
    {
        throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], null, null, null)]));
    }

    /**
     * SEC-13 — la saison de l'entité ciblée, ou null quand elle n'en porte pas encore
     * une : sur un POST frais la propriété typée `seasonId` n'est pas initialisée (elle
     * l'est plus loin, dans processPost), et y accéder lèverait une Error. On retombe
     * alors sur le header/saison courante (aucune régression).
     */
    private function targetSeasonIdOf(mixed $data): ?string
    {
        if (!\is_object($data) || !method_exists($data, 'getSeasonId')) {
            return null;
        }

        try {
            $seasonId = $data->getSeasonId();
        } catch (Error) {
            return null;
        }

        return \is_string($seasonId) && '' !== $seasonId ? $seasonId : null;
    }

    /**
     * P4-25 — le verrou optimiste, câblé une fois pour toutes.
     *
     * ⚑ Le défaut : `processPut` remplaçait tous les champs éditables sans jamais regarder
     * la colonne `version`. Deux onglets sur la même ligne, ou un cache react-query périmé
     * de 30 s, et la seconde écriture écrase la première — **sans un mot**. Le premier
     * éditeur ne l'apprend pas, le second ne sait pas qu'il a effacé quelque chose. Constaté
     * sur `CoachWish`, mais universel : 30 entités sur 49 portent `#[ORM\Version]`.
     *
     * ⚠ Le mécanisme Doctrine EXISTAIT déjà — la colonne est mappée `#[ORM\Version]` partout.
     * Il n'était simplement jamais engagé : `processPut` recharge l'entité juste avant
     * d'écrire, donc la version concorde toujours au flush. Une protection présente et
     * inerte, ce qui est pire qu'absente : on croit l'avoir.
     *
     * **`If-Match` plutôt qu'un champ de payload**, et c'est un choix de coût autant que de
     * standard : un en-tête se lit en UN endroit, un champ aurait demandé de toucher les 30
     * DTO d'entrée. Son absence conserve exactement le comportement actuel — aucune écriture
     * existante ne casse, la protection s'active client par client, au rythme du front.
     */
    private function assertNotStale(object $entity): void
    {
        $ifMatch = $this->requestStack->getCurrentRequest()?->headers->get('If-Match');
        if (null === $ifMatch || '' === trim($ifMatch)) {
            return;
        }

        $expected = filter_var(trim($ifMatch, " \t\n\r\0\x0B\"'W/"), \FILTER_VALIDATE_INT);
        if (false === $expected) {
            throw new UnprocessableEntityHttpException('If-Match doit porter le numéro de version de la ressource.');
        }

        try {
            $this->entityManager->lock($entity, LockMode::OPTIMISTIC, $expected);
        } catch (OptimisticLockException) {
            // 409 et pas 412 : le client n'a rien à re-négocier, il a une version périmée
            // d'une ressource qui a bougé. Le message dit quoi faire — recharger — parce
            // qu'un « conflit » sans conduite à tenir renvoie le gestionnaire à lui-même.
            throw new ConflictHttpException('Cette fiche a été modifiée entre-temps par quelqu\'un d\'autre. Rechargez la page avant d\'enregistrer, sinon vous écraseriez sa saisie.');
        }
    }
}

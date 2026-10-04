<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ImplicitRuleSettingResource;
use App\Dto\ImplicitRuleSettingInput;
use App\Entity\ImplicitRuleSetting;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Repository\ImplicitRuleSettingRepository;
use App\Service\ImplicitRuleResolver;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\State\Provider\ReadsUuidQueryParamTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Upsert (PUT) et réinitialisation (DELETE) d'un réglage de règle implicite, PAR `ruleKey`.
 *
 * Écriture = management (403 AVANT le 409 de saison archivée, idiome AbstractStateProcessor).
 * Les seuils sont bornés et le couple règle↔seuil vérifié ici (message 422 français
 * actionnable, sans identifiant interne) : ce que le moteur accepte a UNE maison côté backend,
 * l'enum `ImplicitRuleKey`.
 *
 * @implements ProcessorInterface<mixed, ImplicitRuleSettingResource|null>
 */
final class ImplicitRuleSettingStateProcessor implements ProcessorInterface
{
    use AssertsSchedulePlanExistsTrait;
    use ReadsUuidQueryParamTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ImplicitRuleSettingRepository $repository,
        private readonly ImplicitRuleResolver $resolver,
        private readonly SeasonResolver $seasonResolver,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ImplicitRuleSettingResource
    {
        $request = $this->requestStack->getCurrentRequest();

        // SEC-07 — management (403) AVANT saison archivée (409) : l'autorisation gagne.
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);

        [$clubId, $seasonId] = $this->resolveScope();
        if (null === $clubId || null === $seasonId) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        $ruleKey = ImplicitRuleKey::tryFrom((string) ($uriVariables['ruleKey'] ?? ''));
        if (null === $ruleKey) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        if ($operation instanceof DeleteOperationInterface) {
            // ADR-0002 inv. 5 — la portée d'un DELETE se lit en QUERY (`?schedulePlanId=`).
            $planId = $request instanceof Request ? $this->uuidQueryParam($request, 'schedulePlanId') : null;
            $this->assertSchedulePlanExists($this->entityManager, $planId);
            $this->reset($clubId, $seasonId, $planId, $ruleKey);

            return null;
        }

        \assert($data instanceof ImplicitRuleSettingInput);

        // La portée d'un PUT se lit dans le CORPS (`schedulePlanId`) — jamais mélanger les deux.
        $planId = $data->schedulePlanId;
        $this->assertSchedulePlanExists($this->entityManager, $planId);

        return $this->upsert($clubId, $seasonId, $planId, $ruleKey, $data);
    }

    /**
     * Réinitialiser une règle. Portée SAISON (plan NULL) : SUPPRIMER la ligne → retour au défaut
     * (comportement historique). Portée PLAN : on GARDE l'invariant 4 lignes — on RE-COPIE la
     * valeur SAISON courante (résolue : stockée saison, sinon défaut) dans la ligne du plan, on
     * ne la supprime pas.
     */
    private function reset(string $clubId, string $seasonId, ?string $planId, ImplicitRuleKey $ruleKey): void
    {
        if (null === $planId) {
            // Idempotent : réinitialiser une règle de saison déjà au défaut n'a rien à supprimer.
            $existing = $this->repository->findOneByScopeKey($clubId, $seasonId, null, $ruleKey);
            if ($existing instanceof ImplicitRuleSetting) {
                $this->entityManager->remove($existing);
                $this->entityManager->flush();
            }

            return;
        }

        // Un plan legacy sans copie : la matérialiser AVANT (la copie totale vaut déjà la
        // valeur saison, donc « réinitialiser » y est un no-op — mais l'invariant 4 lignes tient).
        $this->repository->materializeForPlan($clubId, $seasonId, $planId);

        $planRow = $this->repository->findOneByScopeKey($clubId, $seasonId, $planId, $ruleKey);
        if (!$planRow instanceof ImplicitRuleSetting) {
            return; // matérialisation impossible (plan hors club/saison) — rien à réinitialiser
        }
        $seasonRow = $this->repository->findOneByScopeKey($clubId, $seasonId, null, $ruleKey);
        $planRow->setIntensity($seasonRow instanceof ImplicitRuleSetting ? $seasonRow->getIntensity() : ImplicitRuleIntensity::HARD);
        $planRow->setParams($seasonRow instanceof ImplicitRuleSetting ? $seasonRow->getParams() : null);
        $this->entityManager->flush();
    }

    private function upsert(string $clubId, string $seasonId, ?string $planId, ImplicitRuleKey $ruleKey, ImplicitRuleSettingInput $input): ImplicitRuleSettingResource
    {
        $intensity = ImplicitRuleIntensity::tryFrom($input->intensity ?? '')
            ?? throw new UnprocessableEntityHttpException(\sprintf('« %s » n\'est pas une intensité connue. Valeurs acceptées : %s.', $input->intensity ?? '(absente)', implode(', ', ImplicitRuleIntensity::values())));

        $params = $this->validateAndBuildParams($ruleKey, $input);

        // Portée PLAN : matérialiser la copie totale au PREMIER réglage (un plan legacy passe de
        // 0 à 4 lignes ; un plan déjà matérialisé est inchangé — NOT EXISTS). La lecture, elle,
        // ne matérialise JAMAIS (le build overlay n'écrit pas).
        if (null !== $planId) {
            $this->repository->materializeForPlan($clubId, $seasonId, $planId);
        }

        $entity = $this->repository->findOneByScopeKey($clubId, $seasonId, $planId, $ruleKey)
            ?? (new ImplicitRuleSetting)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setSchedulePlanId($planId)
                ->setRuleKey($ruleKey);

        $entity->setIntensity($intensity);
        $entity->setParams($params);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $resolved = null === $planId
            ? $this->resolver->resolve($clubId, $seasonId)
            : $this->resolver->resolveForPlan($clubId, $seasonId, $planId);

        return ImplicitRuleSettingResource::fromResolved($ruleKey, $resolved[$ruleKey->value], false);
    }

    /**
     * Vérifie le couple règle↔seuil et les bornes, puis compose le `params` stocké.
     *
     * @return array<string, mixed>|null
     */
    private function validateAndBuildParams(ImplicitRuleKey $ruleKey, ImplicitRuleSettingInput $input): ?array
    {
        // Un seuil envoyé à une règle qui n'en porte pas est un contresens : on le NOMME,
        // plutôt que de l'ignorer en silence (le gestionnaire croirait l'avoir réglé).
        if (ImplicitRuleKey::COACH_REST_DAY !== $ruleKey && null !== $input->minRestDays) {
            throw new UnprocessableEntityHttpException('Le seuil de repos coach ne concerne que la règle « repos des coachs ».');
        }
        if (ImplicitRuleKey::MAX_CONSECUTIVE_SESSIONS !== $ruleKey && null !== $input->maxConsecutive) {
            throw new UnprocessableEntityHttpException('Le plafond de créneaux consécutifs ne concerne que la règle « créneaux consécutifs ».');
        }

        $paramKey = $ruleKey->paramKey();
        if (null === $paramKey) {
            return null;
        }

        $value = ImplicitRuleKey::COACH_REST_DAY === $ruleKey ? $input->minRestDays : $input->maxConsecutive;
        if (null === $value) {
            // Seuil laissé au défaut : aucune valeur stockée, le résolveur pourvoit le défaut.
            return null;
        }

        $min = (int) $ruleKey->paramMin();
        $max = (int) $ruleKey->paramMax();
        if ($value < $min || $value > $max) {
            throw new UnprocessableEntityHttpException(\sprintf('Cette valeur doit être comprise entre %d et %d.', $min, $max));
        }

        return [$paramKey => $value];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveScope(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');
        if (!\is_string($clubId) || '' === $clubId) {
            return [null, null];
        }

        $seasonId = $request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id');
        if (!\is_string($seasonId) || '' === $seasonId) {
            $seasonId = $this->seasonResolver->currentSeason($clubId)?->getId();
        }

        return [$clubId, \is_string($seasonId) && '' !== $seasonId ? $seasonId : null];
    }
}

<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\VenueTravelRuleSettingResource;
use App\Dto\VenueTravelRuleSettingInput;
use App\Entity\VenueTravelRuleSetting;
use App\Enum\TeamLinkIntensity;
use App\Repository\VenueTravelRuleSettingRepository;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Upsert (PUT) du levier de trajet — SINGLETON par club+saison (P2-53 RMM-8 PR-4).
 *
 * Écriture = management (403 AVANT le 409 de saison archivée, idiome AbstractStateProcessor).
 * L'intensité n'accepte que PREFERRED|MANDATORY : HARD/OFF (vocabulaire bien-être) rendent 422.
 *
 * @implements ProcessorInterface<mixed, VenueTravelRuleSettingResource>
 */
final class VenueTravelRuleSettingStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly VenueTravelRuleSettingRepository $repository,
        private readonly SeasonResolver $seasonResolver,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VenueTravelRuleSettingResource
    {
        // Revue sécurité 2026-08-26 (F-1) : même garde que le provider — une clé
        // inconnue fait 404, jamais une écriture aliasée sur le réglage réel.
        if (VenueTravelRuleSettingResource::RULE_KEY !== ($uriVariables['ruleKey'] ?? null)) {
            throw new NotFoundHttpException('Réglage inconnu.');
        }
        $request = $this->requestStack->getCurrentRequest();

        // SEC-07 — management (403) AVANT saison archivée (409) : l'autorisation gagne.
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);

        [$clubId, $seasonId] = $this->resolveScope();
        if (null === $clubId || null === $seasonId) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }

        \assert($data instanceof VenueTravelRuleSettingInput);

        $intensity = TeamLinkIntensity::tryFrom($data->intensity ?? '')
            ?? throw new UnprocessableEntityHttpException(\sprintf('« %s » n\'est pas une intensité connue. Valeurs acceptées : %s.', $data->intensity ?? '(absente)', implode(', ', TeamLinkIntensity::values())));

        $entity = $this->repository->findOneByClubSeason($clubId, $seasonId)
            ?? (new VenueTravelRuleSetting)
                ->setClubId($clubId)
                ->setSeasonId($seasonId);
        $entity->setIntensity($intensity);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return VenueTravelRuleSettingResource::from($intensity, false);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveScope(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return [null, null];
        }

        $seasonId = $request?->attributes->get('_season_id') ?? $request?->headers->get('X-Season-Id');
        if (!\is_string($seasonId) || '' === $seasonId) {
            $seasonId = $this->seasonResolver->currentSeason($clubId)?->getId();
        }

        return [$clubId, \is_string($seasonId) && '' !== $seasonId ? $seasonId : null];
    }
}

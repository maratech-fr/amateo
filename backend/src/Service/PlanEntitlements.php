<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\DemoRegime;
use App\Entity\Club;
use App\Entity\ClubSubscription;
use App\Entity\Season;
use App\Entity\SubscriptionPlan;
use App\Entity\SubscriptionPlanTier;
use App\Entity\Team;
use App\Repository\ClubSubscriptionRepository;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionPlanTierRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P1-3 PR A — l'offre EFFECTIVE d'un club pour une saison, calculée à la LECTURE (pas de
 * cron). Grille des offres : specs/courantes/etat-des-lieux.md §1.11 (Découverte, l'offre
 * complète à trois paliers — 20, 34, illimité —, la Bêta). Règles :
 *   - l'offre d'une saison ne dépend que de SA ligne d'abonnement ({@see ClubSubscription},
 *     clé club + année de la saison lue) : aucune ligne, ligne Découverte ou ligne non réglée
 *     → Découverte ; régler une saison ne couvre aucune autre ;
 *   - le plafond d'équipes d'une saison est celui du PALIER de sa ligne ({@see SubscriptionPlanTier}) ;
 *     Découverte et la Bêta n'ont pas de palier, donc pas de plafond ;
 *   - club `isDemo` → droits pleins TOUJOURS (exempt de tout gate).
 *
 * Rend le socle de droits (DTO tableau) consommé par /api/me — l'ENFORCEMENT
 * (débit des crédits, cap d'équipes) est la PR B, PAS ici : ce service ne fait que
 * DIRE ce que l'offre autorise.
 */
final readonly class PlanEntitlements
{
    private const string DECOUVERTE_CODE = 'decouverte';

    public function __construct(
        private SubscriptionPlanRepository $plans,
        private EntityManagerInterface $entityManager,
        private DemoRegime $demoRegime,
        private ClubSubscriptionRepository $subscriptions,
        private SubscriptionPlanTierRepository $tiers,
    ) {}

    /**
     * La règle PURE de l'offre effective d'une saison (code seul, sur primitives) — MAISON
     * UNIQUE, partagée avec `AdminMonitoringService` (badge de la console SA) et la bascule.
     * Découverte si la saison n'a pas de ligne, si sa ligne est Découverte ou si elle n'est pas
     * réglée ; sinon l'offre de la ligne.
     */
    public static function effectivePlanCode(?string $lineCode, bool $paid): string
    {
        if (null === $lineCode || self::DECOUVERTE_CODE === $lineCode || !$paid) {
            return self::DECOUVERTE_CODE;
        }

        return $lineCode;
    }

    /**
     * La saison d'année $seasonYear est-elle réglée d'une offre autre que Découverte ? C'est ce
     * qu'exige la bascule vers elle : une ligne Découverte, même réglée, ne l'ouvre jamais.
     */
    public function isSeasonPaid(string $clubId, int $seasonYear): bool
    {
        return self::DECOUVERTE_CODE !== $this->effectiveCodeOf($this->subscriptions->forSeasonYear($clubId, $seasonYear));
    }

    /**
     * @return array{
     *     planCode: string,
     *     planName: string,
     *     tierCode: string|null,
     *     maxTeams: int|null,
     *     teamsUsed: int,
     *     creditsMax: int|null,
     *     creditsUsed: int,
     *     canGenerate: bool,
     *     canPlaceMatches: bool,
     *     canExportPdf: bool,
     *     seasonTransition: bool
     * }
     */
    public function forClub(Club $club, Season $season): array
    {
        $decouverte = $this->plans->findOneBy(['code' => self::DECOUVERTE_CODE]);
        [$effective, $tier] = $this->effective($club, $season, $decouverte);

        // Découverte effective = le seul régime bridé. Un club démo n'est JAMAIS
        // bridé, quelle que soit son offre effective.
        $restricted = !$this->demoRegime->isUnlimited($club) && self::DECOUVERTE_CODE === $effective?->getCode();

        // $restricted implique une offre effective non-nulle (code === decouverte),
        // narrowing que PHPStan suit depuis la condition ci-dessus.
        $creditsMax = $restricted ? $this->unlimitedToNull($effective->getMaxGenerations()) : null;
        $creditsUsed = $club->getOutputCreditsUsed();
        // Solde > 0 : en Découverte bridée on n'a de sorties QUE tant qu'il reste des
        // crédits (creditsMax null = illimité => solde toujours ouvert).
        $hasOutput = !$restricted || null === $creditsMax || $creditsUsed < $creditsMax;

        return [
            'planCode' => $effective?->getCode() ?? self::DECOUVERTE_CODE,
            'planName' => $effective?->getName() ?? 'Découverte',
            'tierCode' => $tier?->getCode(),
            'maxTeams' => $this->unlimitedToNull($tier?->getMaxTeams() ?? 0),
            'teamsUsed' => $this->teamsUsed($club, $season),
            'creditsMax' => $creditsMax,
            'creditsUsed' => $creditsUsed,
            'canGenerate' => $hasOutput,
            'canPlaceMatches' => $hasOutput,
            'canExportPdf' => $hasOutput,
            // Bascule de saison : ouverte en payant/bêta courant ou en démo, fermée
            // en Découverte effective (le seul interrupteur fermé du gratuit).
            'seasonTransition' => !$restricted,
        ];
    }

    /**
     * P1-3 PR B — le budget de SORTIE (pool de crédits) de l'offre effective, lu par le
     * contrôle central (`ClubAccessListener`) et le registre (`OutputCreditLedger`). Une seule source pour « le club est-il bridé ? » et « combien
     * de crédits ? » — aucun ne réplique la règle.
     *
     * @return array{restricted: bool, max: int, used: int}
     *                                                      restricted = régime Découverte bridé (offre effective Découverte, club NON démo, pool > 0) ;
     *                                                      max = taille du pool (convention 0 = illimité → non bridé) ; used = compteur du club
     */
    public function outputBudget(Club $club, Season $season): array
    {
        $decouverte = $this->plans->findOneBy(['code' => self::DECOUVERTE_CODE]);
        [$effective] = $this->effective($club, $season, $decouverte);

        // Même narrowing que forClub : $restricted vrai implique $effective non-null.
        $restricted = !$this->demoRegime->isUnlimited($club) && self::DECOUVERTE_CODE === $effective?->getCode();
        $max = $restricted ? $effective->getMaxGenerations() : 0;

        return [
            // Un pool à 0 = illimité (convention) : pas de bride, même en Découverte.
            'restricted' => $restricted && $max > 0,
            'max' => $max,
            'used' => $club->getOutputCreditsUsed(),
        ];
    }

    /**
     * Le plafond d'ÉQUIPES du palier de la saison — null si aucun plafond ne s'applique
     * (Découverte, Bêta, palier 3, ou club démo). Centralise la règle des plafonds.
     */
    public function teamCap(Club $club, Season $season): ?int
    {
        if ($this->demoRegime->isUnlimited($club)) {
            return null;
        }
        $decouverte = $this->plans->findOneBy(['code' => self::DECOUVERTE_CODE]);
        [, $tier] = $this->effective($club, $season, $decouverte);

        return $this->unlimitedToNull($tier?->getMaxTeams() ?? 0);
    }

    /** Places d'équipes restantes avant le cap ; null si illimité (aucun cap). */
    public function remainingTeamSlots(Club $club, Season $season): ?int
    {
        $cap = $this->teamCap($club, $season);
        if (null === $cap) {
            return null;
        }

        return max(0, $cap - $this->teamsUsed($club, $season));
    }

    /**
     * Message de refus si créer $additional équipe(s) de plus franchit le plafond du palier ;
     * null si OK (ou aucun plafond). Dit le plafond du palier ET celui du palier suivant.
     */
    public function teamCapExceededMessage(Club $club, Season $season, int $additional = 1): ?string
    {
        if ($this->demoRegime->isUnlimited($club)) {
            return null;
        }
        $decouverte = $this->plans->findOneBy(['code' => self::DECOUVERTE_CODE]);
        [, $tier] = $this->effective($club, $season, $decouverte);
        $cap = $this->unlimitedToNull($tier?->getMaxTeams() ?? 0);
        if (null === $cap || !$tier instanceof SubscriptionPlanTier) {
            return null;
        }
        if ($this->teamsUsed($club, $season) + $additional <= $cap) {
            return null;
        }

        $next = $this->tiers->nextOf($tier);
        if (!$next instanceof SubscriptionPlanTier) {
            return \sprintf('Votre palier permet %d équipes.', $cap);
        }
        if (0 === $next->getMaxTeams()) {
            return \sprintf('Votre palier permet %d équipes — le palier suivant retire la limite.', $cap);
        }

        return \sprintf('Votre palier permet %d équipes — le palier suivant monte à %d.', $cap, $next->getMaxTeams());
    }

    /** Toutes les équipes du club dans la saison — inactives comprises (elles comptent au cap). */
    public function teamsUsed(Club $club, Season $season): int
    {
        return $this->entityManager->getRepository(Team::class)
            ->count(['clubId' => $club->getId(), 'seasonId' => $season->getId()]);
    }

    /**
     * L'offre effective de la saison et son palier : Découverte (sans palier) si la ligne manque,
     * est Découverte ou n'est pas réglée ; sinon l'offre de la ligne et son palier (null pour la Bêta).
     *
     * @return array{0: ?SubscriptionPlan, 1: ?SubscriptionPlanTier}
     */
    private function effective(Club $club, Season $season, ?SubscriptionPlan $decouverte): array
    {
        $line = $this->subscriptions->forSeasonYear($club->getId(), SeasonResolver::seasonYear($season->getStartDate()));
        $plan = $line instanceof ClubSubscription ? $this->plans->find($line->getPlanId()) : null;
        if (!$plan instanceof SubscriptionPlan || self::DECOUVERTE_CODE === $this->effectiveCodeOf($line)) {
            return [$decouverte, null];
        }
        $tierId = $line->getTierId();

        return [$plan, null === $tierId ? null : $this->tiers->find($tierId)];
    }

    private function effectiveCodeOf(?ClubSubscription $line): string
    {
        if (!$line instanceof ClubSubscription) {
            return self::DECOUVERTE_CODE;
        }

        return self::effectivePlanCode($this->plans->find($line->getPlanId())?->getCode(), $line->isPaid());
    }

    /** 0 en base = illimité → null côté lecture. */
    private function unlimitedToNull(int $value): ?int
    {
        return 0 === $value ? null : $value;
    }
}

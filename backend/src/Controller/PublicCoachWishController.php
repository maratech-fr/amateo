<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\TeamCoach;
use App\Repository\CoachWishTokenRepository;
use App\Service\ClubDay;
use App\Service\CoachWishFormPresenter;
use App\Service\CoachWishMutualizationUpserter;
use App\Service\CoachWishSeasonGuard;
use App\Service\CoachWishUpserter;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique de collecte des doléances (feature #10, lot C2) — SANS LOGIN.
 *
 * Le coach ouvre son lien personnel `/doleances/{token}` : GET rend son contexte (prénom,
 * ses équipes retenues, semaines, deadline, doléances existantes), POST dépose/met à jour
 * ses souhaits. Le token porte l'identité et le club — la requête n'a pas de JWT.
 *
 * Défense (cf. security-review) :
 *  - route PUBLIC_ACCESS → jamais de 401 (le client ky redirige vers /login sur 401) ;
 *  - rate limit PAR IP avant tout lookup (GET compris — le pré-remplissage est énumérable) ;
 *  - forme du token validée + réponse 404 BYTE-IDENTIQUE pour inconnu et malformé (anti-énumération) ;
 *  - deadline dépassée → 410 (l'extension côté gestionnaire ranime le lien) ;
 *  - écriture BORNÉE au périmètre du token (ce coach, ses équipes ∩ campagne, les semaines
 *    de la campagne) — une seule violation → 422 et RIEN d'écrit ;
 *  - GUC `app.club_id` posé depuis le token, TOUJOURS relâché en finally (patron verifyEmail).
 */
#[AsController]
final class PublicCoachWishController extends AbstractController
{
    /** Plafond dur du nombre de sections soumises — largement au-dessus d'un périmètre réel. */
    private const MAX_SUBMISSIONS = 200;

    public function __construct(
        private readonly CoachWishTokenRepository $tokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly CoachWishUpserter $upserter,
        private readonly CoachWishMutualizationUpserter $mutualizationUpserter,
        private readonly CoachWishFormPresenter $formPresenter,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactory $coachWishPublicLimiter,
        private readonly CoachWishSeasonGuard $seasonGuard,
        private readonly ClubDay $clubDay,
    ) {}

    #[Route('/api/coach-wishes/public/{token}', name: 'public_coach_wish_get', methods: ['GET'])]
    public function show(string $token, Request $request): JsonResponse
    {
        // Clé PAR IP. `getClientIp()` peut être null → repli explicite, sinon toutes ces
        // requêtes tomberaient dans le même compartiment. L'IP réelle derrière le reverse-proxy
        // dépend de `trusted_proxies` (framework.yaml, repli `private_ranges`).
        if (!$this->coachWishPublicLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }
        $entity = $this->resolveToken($token);
        if (null === $entity) {
            return $this->notFound();
        }

        try {
            $this->tenantConnectionContext->setClubId($entity['token']->getClubId());
            $campaign = $this->entityManager->getRepository(CoachWishCampaign::class)->find($entity['token']->getCampaignId());
            if (!$campaign instanceof CoachWishCampaign) {
                return $this->notFound();
            }
            if ($this->isExpired($campaign) || $this->seasonGuard->isReadonly($campaign)) {
                return $this->json(['error' => 'expired'], Response::HTTP_GONE);
            }
            $coach = $this->entityManager->getRepository(Coach::class)->find($entity['token']->getCoachId());
            if (!$coach instanceof Coach) {
                return $this->notFound();
            }

            // Contexte COACH (prénom, équipes, partenaires possibles, passerelles, doléances et
            // mutualisations déjà saisies) — foyer unique partagé avec l'aperçu gestionnaire
            // (CoachWishFormPresenter). Ici `includeExisting: true` + le respondedAt du token.
            return $this->json($this->formPresenter->build(
                $campaign,
                $coach,
                includeExisting: true,
                respondedAt: $entity['token']->getRespondedAt()?->format(DateTimeInterface::ATOM),
            ));
        } finally {
            $this->tenantConnectionContext->clear();
        }
    }

    #[Route('/api/coach-wishes/public/{token}', name: 'public_coach_wish_post', methods: ['POST'])]
    public function submit(string $token, Request $request): JsonResponse
    {
        // Clé PAR IP. `getClientIp()` peut être null → repli explicite, sinon toutes ces
        // requêtes tomberaient dans le même compartiment. L'IP réelle derrière le reverse-proxy
        // dépend de `trusted_proxies` (framework.yaml, repli `private_ranges`).
        if (!$this->coachWishPublicLimiter->create($request->getClientIp() ?? 'unknown')->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives — réessayez dans quelques minutes.'], 429);
        }
        $entity = $this->resolveToken($token);
        if (null === $entity) {
            return $this->notFound();
        }

        $payload = json_decode((string) $request->getContent(), true);
        $submissions = \is_array($payload) && isset($payload['submissions']) && \is_array($payload['submissions']) ? $payload['submissions'] : null;
        if (null === $submissions) {
            return $this->json(['error' => 'submissions requis.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Mutualisations (D2) : clé OPTIONNELLE, rétro-compatible (un client C2/C3 ne l'envoie
        // pas). Validée AVANT toute écriture, comme les doléances — une violation → 422, rien
        // d'écrit.
        // `$payload` est déjà prouvé tableau ici (le guard `submissions` ci-dessus l'exige).
        $mutualizations = isset($payload['mutualizations']) && \is_array($payload['mutualizations']) ? $payload['mutualizations'] : [];
        // Borne de cardinalité AVANT toute itération : le périmètre réel d'un coach est petit
        // (ses équipes × les semaines de la campagne). Un plafond large mais fini coupe l'abus
        // O(N) d'un tableau géant sur un endpoint sans login (le reste est déjà borné par le
        // rate-limit et post_max_size).
        if (\count($submissions) > self::MAX_SUBMISSIONS || \count($mutualizations) > self::MAX_SUBMISSIONS) {
            return $this->json(['error' => 'Trop de lignes.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->tenantConnectionContext->setClubId($entity['token']->getClubId());
            $campaign = $this->entityManager->getRepository(CoachWishCampaign::class)->find($entity['token']->getCampaignId());
            if (!$campaign instanceof CoachWishCampaign) {
                return $this->notFound();
            }
            if ($this->isExpired($campaign) || $this->seasonGuard->isReadonly($campaign)) {
                return $this->json(['error' => 'expired'], Response::HTTP_GONE);
            }
            $coachId = $entity['token']->getCoachId();
            $perimeter = array_flip($this->perimeterTeamIds($coachId, $campaign));
            // La semaine doit encore recouper la période mère À L'ÉCRITURE (parité avec le
            // chemin authentifié) : `campaign.weeks` est un instantané non re-purgé si le
            // gestionnaire raccourcit la période après le lancement.
            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($campaign->getCalendarEntryId());

            // Validation COMPLÈTE avant toute écriture : une violation → 422, rien d'écrit.
            // Clé par (équipe, semaine) : deux lignes du même couple partageraient la clé
            // naturelle de CoachWish (violation d'unicité au flush → 500). On déduplique, la
            // dernière l'emporte (idempotent, cohérent avec « écrase »).
            $clean = [];
            foreach ($submissions as $item) {
                $teamId = \is_array($item) && \is_string($item['teamId'] ?? null) ? $item['teamId'] : '';
                $weekStart = \is_array($item) && \is_string($item['weekStart'] ?? null) ? $item['weekStart'] : '';
                if (!isset($perimeter[$teamId])) {
                    return $this->json(['error' => 'Équipe hors de votre périmètre.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                if (!\in_array($weekStart, $campaign->getWeeks(), true) || !$this->weekIntersectsPeriod($weekStart, $entry)) {
                    return $this->json(['error' => 'Semaine hors de la collecte.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $slots = \is_array($item) ? (int) ($item['slotsWanted'] ?? 0) : 0;
                if ($slots < 0 || $slots > 7) {
                    return $this->json(['error' => 'Nombre de créneaux invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $days = [];
                foreach ((\is_array($item) && \is_array($item['unavailableDays'] ?? null) ? $item['unavailableDays'] : []) as $d) {
                    $d = (int) $d;
                    if ($d < 1 || $d > 7) {
                        return $this->json(['error' => 'Jour invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }
                    $days[] = $d;
                }
                $wished = [];
                foreach ((\is_array($item) && \is_array($item['wishedDays'] ?? null) ? $item['wishedDays'] : []) as $d) {
                    $d = (int) $d;
                    if ($d < 1 || $d > 7) {
                        return $this->json(['error' => 'Jour invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }
                    $wished[] = $d;
                }
                $days = array_values(array_unique($days));
                $wished = array_values(array_unique($wished));
                // Un jour ne peut pas être à la fois souhaité ET indisponible : une violation → 422,
                // rien d'écrit (le front décoche déjà l'un quand l'autre est coché — garde serveur).
                if ([] !== array_intersect($wished, $days)) {
                    return $this->json(['error' => 'Un jour ne peut pas être à la fois souhaité et indisponible.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $comment = \is_array($item) && \is_string($item['comment'] ?? null) ? mb_substr($item['comment'], 0, 2000) : null;
                // Volet B : booléen NU. Le périmètre (équipe ∩ campagne, semaines de la campagne)
                // est déjà vérifié ci-dessus — cocher « garder mes créneaux » n'élargit RIEN et
                // n'inclut aucune équipe au plan (ça, c'est un geste gestionnaire).
                $keepSeasonSlots = \is_array($item) && true === ($item['keepSeasonSlots'] ?? false);
                $clean[$teamId . '|' . $weekStart] = ['teamId' => $teamId, 'weekStart' => $weekStart, 'slots' => $slots, 'days' => $days, 'wished' => $wished, 'comment' => $comment, 'keepSeasonSlots' => $keepSeasonSlots];
            }

            // Mutualisations (D2) : une par équipe DU COACH, partenaires PARMI les équipes de la
            // campagne. Clé par teamId (unique métier (entrée, équipe)) — la dernière l'emporte.
            $campaignTeams = array_flip($campaign->getTeamIds());
            $cleanMut = [];
            foreach ($mutualizations as $item) {
                $teamId = \is_array($item) && \is_string($item['teamId'] ?? null) ? $item['teamId'] : '';
                if (!isset($perimeter[$teamId])) {
                    return $this->json(['error' => 'Équipe hors de votre périmètre.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $partners = [];
                foreach ((\is_array($item) && \is_array($item['partnerTeamIds'] ?? null) ? $item['partnerTeamIds'] : []) as $p) {
                    // Message 422 UNIFORME : qu'un partenaire soit inconnu, d'un autre club ou
                    // hors campagne, le corps est le même (anti-énumération du périmètre).
                    if (!\is_string($p) || !isset($campaignTeams[$p]) || $p === $teamId) {
                        return $this->json(['error' => 'Équipe partenaire hors de la collecte.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }
                    if (!\in_array($p, $partners, true)) {
                        $partners[] = $p;
                    }
                }
                $sharedSlots = \is_array($item) ? (int) ($item['sharedSlots'] ?? 0) : 0;
                // Un nombre de séances n'a de sens qu'avec au moins un partenaire ; sans partenaire
                // l'upserter supprimera la ligne (le coach ne mutualise plus), sharedSlots ignoré.
                if ([] !== $partners && ($sharedSlots < 1 || $sharedSlots > 7)) {
                    return $this->json(['error' => 'Nombre de séances à mutualiser invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $cleanMut[$teamId] = ['teamId' => $teamId, 'partners' => $partners, 'sharedSlots' => $sharedSlots];
            }

            $this->entityManager->wrapInTransaction(function () use ($clean, $cleanMut, $campaign, $coachId, $entity): void {
                foreach ($clean as $c) {
                    $this->upserter->upsert($campaign, $c['teamId'], new DateTimeImmutable($c['weekStart'] . ' 00:00:00'), $coachId, $c['slots'], $c['days'], $c['wished'], $c['comment'], $c['keepSeasonSlots']);
                }
                foreach ($cleanMut as $m) {
                    $this->mutualizationUpserter->upsert($campaign, $m['teamId'], $coachId, $m['partners'], $m['sharedSlots']);
                }
                $entity['token']->markResponded($this->clock->now());
                $this->entityManager->flush();
            });

            return $this->json(['deadline' => $campaign->getDeadline()->format('Y-m-d')], Response::HTTP_OK);
        } finally {
            $this->tenantConnectionContext->clear();
        }
    }

    /**
     * Résout le token (forme + existence). Réponse 404 identique pour malformé et inconnu.
     *
     * @return array{token: CoachWishToken}|null
     */
    private function resolveToken(string $token): ?array
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $entity = $this->tokenRepository->findOneByToken($token);

        return $entity instanceof CoachWishToken ? ['token' => $entity] : null;
    }

    private function isExpired(CoachWishCampaign $campaign): bool
    {
        // Deadline INCLUSIVE : le jour même est encore ouvert — dans le fuseau DU CLUB
        // (P4-46). Sur le jour du serveur, un coach en Guadeloupe (UTC−4) prenait 410 le
        // soir du dernier jour : 21h chez lui, déjà le lendemain à Paris. La promesse
        // « le jour même est ouvert » vaut pour le jour que LE CLUB vit.
        $club = $this->entityManager->find(Club::class, $campaign->getClubId());

        return ($club instanceof Club ? $this->clubDay->todayYmdFor($club) : $this->clock->now()->format('Y-m-d'))
            > $campaign->getDeadline()->format('Y-m-d');
    }

    /** La semaine (lundi→dimanche) recoupe-t-elle encore la période mère, date à date ? */
    private function weekIntersectsPeriod(string $weekStart, ?CalendarEntry $entry): bool
    {
        if (!$entry instanceof CalendarEntry) {
            return false;
        }
        $monday = new DateTimeImmutable($weekStart . ' 00:00:00');
        $sunday = $monday->modify('+6 days');

        return $monday <= $entry->getEndDate() && $sunday >= $entry->getStartDate();
    }

    /**
     * Les équipes du coach (TeamCoach) ∩ équipes de la campagne.
     *
     * @return list<string>
     */
    private function perimeterTeamIds(string $coachId, CoachWishCampaign $campaign): array
    {
        $campaignTeams = array_flip($campaign->getTeamIds());
        $result = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['coachId' => $coachId]) as $link) {
            if (isset($campaignTeams[$link->getTeamId()]) && !\in_array($link->getTeamId(), $result, true)) {
                $result[] = $link->getTeamId();
            }
        }

        return $result;
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'not found'], Response::HTTP_NOT_FOUND);
    }
}

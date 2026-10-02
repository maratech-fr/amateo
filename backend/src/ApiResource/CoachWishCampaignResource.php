<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\CoachWishCampaignInput;
use App\State\Processor\CoachWishCampaignStateProcessor;
use App\State\Provider\CoachWishCampaignStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * La campagne de collecte des doléances coachs d'une période (feature #10, lot C2). Writes
 * management-only. La sortie porte les compteurs du radar et la liste des coachs
 * avec leur token (le front construit l'URL publique) — jamais les doléances elles-mêmes.
 */
// DELETE retiré (nettoyage API) : non appelé par le front.
#[ApiResource(shortName: 'CoachWishCampaign', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    // C3 — actions d'envoi (patron reset-grid : ACTION, pas état ; SEC-07 dans le contrôleur).
    // « Envoyer les liens » : coachs à email PAS ENCORE servis, ou coachIds ciblés (D2).
    new Post(
        uriTemplate: '/coach_wish_campaigns/{id}/send-links',
        controller: 'App\\Controller\\CoachWishCampaignActionController',
        input: false,
        read: false,
        name: 'send_links_coach_wish_campaign',
    ),
    // « Relancer les silencieux » : une relance par jour Europe/Paris (D3) → 422 sinon.
    new Post(
        uriTemplate: '/coach_wish_campaigns/{id}/remind',
        controller: 'App\\Controller\\CoachWishCampaignActionController',
        input: false,
        read: false,
        name: 'remind_coach_wish_campaign',
    ),
], input: CoachWishCampaignInput::class, paginationEnabled: false, provider: CoachWishCampaignStateProvider::class, processor: CoachWishCampaignStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['calendarEntryId' => 'exact'])]
class CoachWishCampaignResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public string $calendarEntryId = '';

    /** Y-m-d. */
    #[Groups(['read'])]
    public string $deadline = '';

    /** @var list<string> */
    #[Groups(['read'])]
    public array $weeks = [];

    /** @var list<string> */
    #[Groups(['read'])]
    public array $teamIds = [];

    /** Coachs distincts du périmètre COURANT. */
    #[Groups(['read'])]
    public int $totalCoachCount = 0;

    /** Ceux qui ont soumis au moins une fois. */
    #[Groups(['read'])]
    public int $respondedCoachCount = 0;

    /** Doléances non traitées (done=false) de la période — le « à traiter » du radar. */
    #[Groups(['read'])]
    public int $openWishCount = 0;

    /** Dernière relance manuelle (C3) — le bouton se bloque le reste de la journée. */
    #[Groups(['read'])]
    public ?string $lastReminderAt = null;

    /**
     * @var list<array{coachId: string, firstName: string, lastName: string, email: string|null, token: string, respondedAt: string|null, sentAt: string|null}>
     */
    #[Groups(['read'])]
    public array $coaches = [];
}

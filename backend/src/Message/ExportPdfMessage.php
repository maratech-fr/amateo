<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ExportPdfMessage
{
    public function __construct(
        private string $scheduleId,
        // RLS: the worker has no HTTP request, so no tenant GUC is set. The
        // handler needs the club id up-front to scope its own connection —
        // it cannot read the schedule to discover it (RLS would return nothing).
        // Nullable ONLY for backward compatibility: messages queued before this
        // field existed deserialize with an uninitialized property; the handler
        // fails those gracefully instead of crashing on getClubId().
        private ?string $clubId = null,
        // Export scope: null = all venues, else a single venue id.
        private ?string $venueId = null,
        // Chantier E — « afficher le nom des coachs » dans la grille du PDF. Nullable pour la
        // MÊME garde legacy que clubId/venueId : un message enfilé AVANT l'ajout de ce champ se
        // désérialise avec la propriété readonly NON initialisée ; le getter retombe sur false.
        private ?bool $showCoaches = false,
        // « Afficher les occupations » : même garde legacy (un message enfilé avant ce champ → false).
        private ?bool $showOccupancies = false,
        // Identifiant de la demande, posé par le contrôleur à chaque geste : un message relu par
        // Messenger porte le MÊME, une nouvelle demande un autre. Le handler le compare à celui de
        // la dernière livraison du planning pour ne jamais rendre ni décompter deux fois la même
        // demande. Null : message enfilé avant ce champ (même garde legacy), jamais vu comme relu.
        private ?string $requestId = null,
    ) {}

    public function getScheduleId(): string
    {
        return $this->scheduleId;
    }

    public function getClubId(): ?string
    {
        // isset() guards the uninitialized-readonly case of a legacy payload.
        return $this->clubId ?? null;
    }

    public function getVenueId(): ?string
    {
        return $this->venueId ?? null;
    }

    public function getShowCoaches(): bool
    {
        // ?? guards the uninitialized-readonly case of a legacy payload (see getClubId()).
        return $this->showCoaches ?? false;
    }

    public function getShowOccupancies(): bool
    {
        // ?? guards the uninitialized-readonly case of a legacy payload (see getClubId()).
        return $this->showOccupancies ?? false;
    }

    public function getRequestId(): ?string
    {
        // ?? guards the uninitialized-readonly case of a legacy payload (see getClubId()).
        return $this->requestId ?? null;
    }
}

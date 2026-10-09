<?php

declare(strict_types=1);

namespace App\Dto;

use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ReservationInput
{
    // XOR teamId/label (lot 4bis) : une réservation cible une équipe OU un créneau libre nommé,
    // jamais les deux ni aucun — tranché dans ReservationStateProcessor (422 nommé). `teamId` n'est
    // donc plus NotBlank : un créneau libre le laisse null. NotBlank(allowNull) court-circuite
    // quand même la chaîne vide (sinon `""` filerait dans une colonne uuid → 500).
    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    /**
     * Libellé d'un créneau LIBRE (court, ≤ 40). XOR avec `teamId`. Length(max) seul : le XOR et le
     * « non vide » d'un libellé réellement posé vivent dans le processor, avec le reste de la règle.
     */
    #[Assert\Length(max: 40)]
    #[Groups(['write'])]
    public ?string $label = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    // Nullable + NotNull so an omitted startTime is a clean 422, not a 500 from
    // reading an uninitialized typed property.
    #[Assert\NotNull]
    #[Groups(['write'])]
    public ?DateTimeImmutable $startTime = null;

    // Plafond retiré (décision fondateur) : un créneau peut couvrir un événement de club
    // (9h-17h = 480 min), au-delà des 5 h de l'ancienne borne. La SEULE borne haute est
    // « début + durée ≤ minuit », vérifiée dans ReservationStateProcessor (422 nommé) — en
    // parité avec le rail batch (GroupReservationController) et la saisie « Autre… » du front.
    #[Assert\Range(min: 15)]
    #[Groups(['write'])]
    public ?int $durationMinutes = 90;

    /**
     * NULL = base plan; set = a period overlay.
     *
     * NotBlank(allowNull) EN PLUS de Uuid : le validateur Uuid de Symfony
     * court-circuite sur la chaîne vide, donc `""` passerait la validation et
     * partirait dans une colonne uuid → 500. `null` reste licite (socle).
     */
    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;
}

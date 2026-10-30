<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateScheduleMessage
{
    public function __construct(
        private string $scheduleId,
        private string $clubId,
        private int $timeoutSeconds = 750,
        // P3-21 — la version que le gestionnaire REGARDE quand il régénère (source de
        // `previousAssignments`, terme de stabilité moteur). Null = première génération /
        // repli sur la dernière COMPLETED du plan, résolu côté handler. Défaut obligatoire :
        // le transport `async` utilise le PhpSerializer natif (aucun `serializer` configuré),
        // les messages déjà en file avant ce déploiement doivent rester désérialisables.
        private ?string $sourceScheduleId = null,
        // P2-44 PR-3 (comblement) — la version de PÉRIODE dont les placements sont ÉPINGLÉS
        // HARD dans le payload de ce solve : « tout ce qui est déjà placé reste EXACTEMENT en
        // place, seuls les trous sont placés ». Non-null ⇒ mode fill (solve PARTIEL) ; le
        // handler injecte ces épingles et saute `previousAssignments` (un HARD n'a pas de
        // variable côté moteur, le terme de stabilité serait un no-op). Même défaut/isset-safe
        // que `sourceScheduleId` ci-dessus (PhpSerializer, messages en vol).
        private ?string $fillSourceScheduleId = null,
    ) {}

    public function getScheduleId(): string
    {
        return $this->scheduleId;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getSourceScheduleId(): ?string
    {
        // ⚠ `unserialize()` NE rappelle PAS le constructeur : sur une propriété PROMUE, le
        // défaut `= null` n'existe qu'au niveau du constructeur, jamais au niveau déclaration.
        // Un message enfilé avant l'ajout de cette propriété se désérialise donc avec
        // `$sourceScheduleId` NON initialisée, et un accès direct lèverait
        // « must not be accessed before initialization ». Le `?? null` (sémantique isset,
        // sûre y compris sur readonly non initialisé) rend ces messages en vol lisibles.
        return $this->sourceScheduleId ?? null;
    }

    public function getFillSourceScheduleId(): ?string
    {
        // ⚠ Même précaution que `getSourceScheduleId` : `unserialize()` ne rappelle pas le
        // constructeur, une propriété promue ajoutée après coup est NON initialisée sur un
        // message en vol. Le `?? null` (sémantique isset) rend ces messages lisibles.
        return $this->fillSourceScheduleId ?? null;
    }

    public function getClubRoutingKey(): string
    {
        return 'club_id:' . $this->clubId;
    }
}

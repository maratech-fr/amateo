<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConflictMailReceiptRepository;
use App\Service\ConflictCoachMailing;
use App\Service\SeasonDataPurger;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Envoi des conflits aux coachs — la mémoire PAR CONFLIT : tel conflit (empreinte) est parti à tel
 * coach, avec tel contenu affiché (`contentDigest`, {@see ConflictCoachMailing::digest}), à tel
 * instant. Une ligne par (club, saison, empreinte, coach). Sert à dire, avant un nouvel envoi, ce
 * qui est nouveau, modifié ou déjà envoyé ({@see ConflictCoachMailing::classify}).
 *
 * `coachId` est SCALAIRE (patron {@see CoachWishToken}) ; la FK SQL `ON DELETE CASCADE` vers
 * `coach` emporte les reçus d'un coach supprimé. Écrite par SQL brut
 * ({@see ConflictMailReceiptRepository}) dans la même transaction que la mémoire
 * {@see ConflictMailing} ; purgée avec la saison ({@see SeasonDataPurger}).
 */
#[ORM\Entity(repositoryClass: ConflictMailReceiptRepository::class)]
#[ORM\Table(name: 'conflict_mail_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_conflict_mail_receipt', columns: ['club_id', 'season_id', 'fingerprint', 'coach_id'])]
#[ORM\Index(name: 'idx_conflict_mail_receipt_coach', columns: ['club_id', 'season_id', 'coach_id'])]
class ConflictMailReceipt implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $fingerprint;

    #[ORM\Column(type: 'guid')]
    private string $coachId;

    #[ORM\Column(type: 'string', length: 40)]
    private string $contentDigest;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $sentAt;

    public function __construct()
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $this->id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        $this->sentAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): self
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function getCoachId(): string
    {
        return $this->coachId;
    }

    public function setCoachId(string $coachId): self
    {
        $this->coachId = $coachId;

        return $this;
    }

    public function getContentDigest(): string
    {
        return $this->contentDigest;
    }

    public function setContentDigest(string $contentDigest): self
    {
        $this->contentDigest = $contentDigest;

        return $this;
    }

    public function getSentAt(): DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }
}

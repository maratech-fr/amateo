<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Clock\ClubClock;
use App\Entity\Club;
use App\Entity\ClubMailboxMessage;
use App\Repository\ClubMailboxMessageRepository;
use App\Service\ClubDay;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * P4-16 — un club à horloge SIMULÉE n'envoie JAMAIS de vrai e-mail : chaque message est rangé
 * dans sa « boîte aux lettres » (option A fondateur 2026-10-02). L'animateur de démo rejoue
 * « à trois semaines des vacances » et VOIT les relances/rappels dans l'app, sans spammer les
 * gestionnaires réels.
 *
 * On branche `Symfony\Mailer` au `MessageEvent`, à PRIORITÉ HAUTE, et SEULEMENT à l'ENFILAGE
 * (`isQueued()` — les envois de l'app passent par le bus Messenger, messenger.yaml route
 * `SendEmailMessage` en async). Rejeter l'événement à l'enfilage empêche la mise en file : le
 * message ne part donc jamais sur le bus, et le worker ne le voit jamais. On NE touche PAS à la
 * phase worker (`isQueued()` faux) : c'est l'envoi réel d'un club SANS horloge, qui doit partir.
 *
 * Le club courant vient du GUC `app.club_id` (posé par TenantFilterListener en requête, par
 * TenantConnectionContext dans les crons) — jamais d'un header. Hors contexte club, le GUC est
 * vide : les e-mails SANS club (reset de mot de passe, vérification d'e-mail, feedback, health)
 * partent donc RÉELLEMENT, c'est voulu. Un club dont l'horloge n'est pas active
 * ({@see ClubClock::simulatedTodayFor()} = null) envoie lui aussi normalement.
 *
 * Le corps est capté TEL QU'IL EST à l'enfilage, donc AVANT la signature de marque
 * (EmailSignatureListener la pose chez le worker, qui ne tourne jamais ici) : `body_text` porte
 * le texte métier, `body_html` est en général nul. Décision assumée (plan horloge, 2026-10-02).
 */
final readonly class ClockedClubMailInterceptor implements EventSubscriberInterface
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClubClock $clubClock,
        private ClubDay $clubDay,
        private ClubMailboxMessageRepository $mailbox,
    ) {}

    /**
     * @return array<class-string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Priorité HAUTE : décider de l'interception AVANT tout autre travail sur le message.
        return [MessageEvent::class => ['onMessage', 100]];
    }

    public function onMessage(MessageEvent $event): void
    {
        // Seulement à l'enfilage : rejeter ici empêche la mise en file (le worker ne verra
        // jamais le message). La phase worker est l'envoi réel d'un club sans horloge.
        if (!$event->isQueued()) {
            return;
        }

        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }

        $clubId = $this->currentClubId();
        if (null === $clubId) {
            return; // hors contexte club → e-mail réel (reset mdp, vérif e-mail, feedback, health)
        }

        $club = $this->entityManager->find(Club::class, $clubId);
        if (!$club instanceof Club) {
            return;
        }
        if (!$this->clubClock->simulatedTodayFor($club) instanceof DateTimeImmutable) {
            return; // club sans horloge active → envoi normal
        }

        // Horloge active → jamais d'envoi réel : ranger dans la boîte, puis rejeter l'enfilage.
        $this->mailbox->record($this->capture($message, $clubId, $this->clubDay->todayFor($club)));
        $event->reject();
    }

    private function currentClubId(): ?string
    {
        $clubId = (string) $this->connection->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')');

        return '' === $clubId ? null : $clubId;
    }

    private function capture(Email $email, string $clubId, DateTimeImmutable $simulatedDate): ClubMailboxMessage
    {
        $message = new ClubMailboxMessage;
        $message->setClubId($clubId);
        $message->setSimulatedDate($simulatedDate);
        $message->setFromAddress(mb_substr($this->joinAddresses($email->getFrom()), 0, 255));
        $message->setToAddress(mb_substr($this->joinAddresses($email->getTo()), 0, 1000));
        $message->setSubject(mb_substr($email->getSubject() ?? '', 0, 998));
        $message->setBodyText($this->asString($email->getTextBody()));
        $message->setBodyHtml($this->asString($email->getHtmlBody()));

        return $message;
    }

    /**
     * @param array<Address> $addresses
     */
    private function joinAddresses(array $addresses): string
    {
        return implode(', ', array_map(static fn (Address $a): string => $a->toString(), $addresses));
    }

    /**
     * `Email::getTextBody()`/`getHtmlBody()` rendent `resource|string|null` : un corps posé comme
     * flux doit être lu en chaîne. On rejette ce message (jamais envoyé), lire le flux du clone
     * d'enfilage est donc sans effet de bord.
     */
    private function asString(mixed $body): ?string
    {
        if (\is_resource($body)) {
            $contents = stream_get_contents($body);

            return \is_string($contents) ? $contents : null;
        }

        return \is_string($body) ? $body : null;
    }
}

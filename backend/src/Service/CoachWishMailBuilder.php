<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\CoachWishCampaign;
use App\Mail\ClubBusinessMail;
use App\Mail\ClubMailMetadata;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

/**
 * Emails de la collecte des doléances (feature #10, lot C3) — texte V0, patron
 * PeriodReminderMailBuilder (rendu isolé du walker, testable seul).
 *
 * 4 gabarits : le LIEN personnel au coach, le DIGEST 7h aux gestionnaires (seulement si
 * nouvelles réponses — silence = rien), la RELANCE manuelle des silencieux, et le RÉCAP
 * final envoyé une fois après la deadline quel que soit l'état (décision fondateur D5).
 */
final class CoachWishMailBuilder
{
    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
    ) {}

    /** Le lien personnel du coach — envoi initial ou relance (même contenu, sujet dédié). */
    public function buildCoachLink(string $to, string $coachFirstName, string $clubName, CoachWishCampaign $campaign, string $periodTitle, string $token, bool $isReminder = false, ?Club $club = null): Email
    {
        $subject = $isReminder
            ? \sprintf('Rappel — vos disponibilités pour %s', $periodTitle)
            : \sprintf('Vos disponibilités pour %s', $periodTitle);

        $lines = [
            \sprintf('Bonjour %s,', $coachFirstName),
            '',
            \sprintf('%s prépare le planning de « %s » et a besoin de vos souhaits d\'entraînement.', $clubName, $periodTitle),
            \sprintf('Merci de répondre avant le %s — le lien reste modifiable jusque-là.', $campaign->getDeadline()->format('d/m/Y')),
        ];
        // Sans base front configurée, un chemin nu « /doleances/… » n'est pas cliquable :
        // on OMET le lien plutôt que d'envoyer une URL inutilisable (précédent des crons).
        $link = $this->publicLink($token);
        if (null !== $link) {
            $lines[] = '';
            $lines[] = $link;
        }
        $lines[] = '';
        $lines[] = 'C\'est un souhait, pas un engagement : le club arbitre ensuite.';

        return $this->email($to, $subject, $lines, $club);
    }

    /**
     * Digest 7h au gestionnaire : les NOUVEAUX répondants depuis le dernier digest + l'état
     * complet (répondants / silencieux).
     *
     * @param list<string> $newNames
     * @param list<string> $respondedNames
     * @param list<string> $silentNames
     */
    public function buildDigest(string $to, string $clubName, string $periodTitle, array $newNames, array $respondedNames, array $silentNames, ?Club $club = null): Email
    {
        $subject = \sprintf('Doléances « %s » — %s', $periodTitle, 1 === \count($newNames) ? $newNames[0] . ' a répondu' : \count($newNames) . ' nouvelles réponses');

        $lines = [
            \sprintf('Club : %s', $clubName),
            \sprintf('Collecte : %s', $periodTitle),
            '',
            \sprintf('Nouvelles réponses : %s', implode(', ', $newNames)),
            '',
            \sprintf('Ont répondu (%d) : %s', \count($respondedNames), [] === $respondedNames ? '—' : implode(', ', $respondedNames)),
            \sprintf('En attente (%d) : %s', \count($silentNames), [] === $silentNames ? '—' : implode(', ', $silentNames)),
        ];
        $lines = [...$lines, ...$this->cockpitFooter()];

        return $this->email($to, $subject, $lines, $club);
    }

    /**
     * Récap final, le lendemain de la deadline — part TOUJOURS, même à zéro réponse (D5) :
     * c'est la clôture, le signal d'agir autrement si la collecte est restée muette.
     *
     * @param list<string> $respondedNames
     * @param list<string> $silentNames
     */
    public function buildFinalRecap(string $to, string $clubName, string $periodTitle, array $respondedNames, array $silentNames, int $openWishCount, ?Club $club = null): Email
    {
        $total = \count($respondedNames) + \count($silentNames);
        $subject = \sprintf('Collecte close « %s » — %d/%d coachs ont répondu', $periodTitle, \count($respondedNames), $total);

        $lines = [
            \sprintf('Club : %s', $clubName),
            \sprintf('La collecte « %s » est close (deadline dépassée).', $periodTitle),
            '',
            \sprintf('Ont répondu (%d) : %s', \count($respondedNames), [] === $respondedNames ? '—' : implode(', ', $respondedNames)),
            \sprintf('Sans réponse (%d) : %s', \count($silentNames), [] === $silentNames ? '—' : implode(', ', $silentNames)),
            \sprintf('Doléances à traiter : %d', $openWishCount),
        ];
        $lines = [...$lines, ...$this->cockpitFooter()];

        return $this->email($to, $subject, $lines, $club);
    }

    /** Lien public absolu, ou null si aucune base front n'est configurée (lien non cliquable). */
    private function publicLink(string $token): ?string
    {
        if ('' === $this->frontendBaseUrl) {
            return null;
        }

        return rtrim($this->frontendBaseUrl, '/') . '/doleances/' . $token;
    }

    /** @return list<string> */
    private function cockpitFooter(): array
    {
        if ('' === $this->frontendBaseUrl) {
            return [];
        }

        return ['', rtrim($this->frontendBaseUrl, '/') . '/'];
    }

    /** @param list<string> $lines */
    private function email(string $to, string $subject, array $lines, ?Club $club): Email
    {
        // E-mail MÉTIER club (lien coach, digest/relance gestionnaire, récap) : candidat à
        // l'interception « boîte aux lettres » d'un club à horloge simulée (cf. ClubBusinessMail).
        // Le destinataire coach peut être un NON-utilisateur : l'intercepteur vérifie aussi
        // l'appartenance via Coach.email, pas seulement les membres.
        $email = ClubBusinessMail::mark(
            (new Email)
                ->from($this->mailFrom->address())
                ->to($to)
                ->subject($subject)
                ->text(implode("\n", $lines)),
        );
        // Identité du club (logo + nom court) sur la carte d'e-mail (D1), quand elle est connue.
        if ($club instanceof Club) {
            ClubMailMetadata::mark($email, $club);
        }

        return $email;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\CoachWishCampaign;
use App\Mail\ClubBusinessMail;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
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
    /**
     * Le libellé du bouton d'action du HTML — le TEXTE garde le lien nu à côté ; le HTML
     * rend un bouton (le renderer valide l'URL `https?://` et l'échappe). Interne, jamais lu
     * par un humain hors e-mail.
     */
    private const string CTA_LABEL = 'Donner mes disponibilités';

    public function __construct(
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl = '',
        // Expéditeur unique (P5-15) — le défaut ne sert qu'aux tests unitaires.
        private readonly MailFrom $mailFrom = new MailFrom,
        // Identité produit (« via Amateo ») — le défaut ne sert qu'aux tests unitaires.
        private readonly ProductIdentity $productIdentity = new ProductIdentity,
    ) {}

    /**
     * Le lien personnel du coach — envoi initial ou relance (même contenu, sujet dédié).
     *
     * `$senderFirstName` = le prénom du GESTIONNAIRE qui déclenche l'envoi/la relance (Formule
     * A, D1) : « {Prénom} ({libellé club}) prépare le planning … ». Le libellé club est
     * {@see Club::emailLabel} quand le club est connu, sinon le nom passé en repli.
     */
    public function buildCoachLink(string $to, string $coachFirstName, string $clubName, CoachWishCampaign $campaign, string $periodTitle, string $token, string $senderFirstName, bool $isReminder = false, ?Club $club = null): Email
    {
        $subject = $isReminder
            ? \sprintf('Rappel — vos disponibilités pour %s', $periodTitle)
            : \sprintf('Vos disponibilités pour %s', $periodTitle);

        $label = $club instanceof Club ? $club->emailLabel() : $clubName;

        $lines = [
            \sprintf('Bonjour %s,', $coachFirstName),
            '',
            \sprintf('%s (%s) prépare le planning de « %s » et a besoin de vos souhaits d\'entraînement.', $senderFirstName, $label, $periodTitle),
            \sprintf('Merci de répondre avant le %s — le lien reste modifiable jusque-là.', $campaign->getDeadline()->format('d/m/Y')),
        ];
        // Sans base front configurée, un chemin nu « /doleances/… » n'est pas cliquable :
        // on OMET le lien plutôt que d'envoyer une URL inutilisable (précédent des crons).
        // Le TEXTE garde TOUJOURS le lien nu (le masquage démo l'attend, et un client sans
        // HTML doit rester cliquable) — le bouton HTML est posé EN PLUS via les en-têtes CTA.
        $link = $this->publicLink($token);
        if (null !== $link) {
            $lines[] = '';
            $lines[] = $link;
        }
        $lines[] = '';
        $lines[] = 'C\'est un souhait, pas un engagement : le club arbitre ensuite.';

        // Expéditeur personnalisé : « {Prénom} ({libellé}) via {produit} » (D1, lien + relance
        // seulement). L'adresse ne change pas — seul le nom affiché est posé à la source.
        $from = $this->mailFrom->addressAs(\sprintf('%s (%s) via %s', $senderFirstName, $label, $this->productIdentity->name()));

        $email = $this->email($to, $subject, $lines, $club, $from);

        // Bouton CTA du HTML : en-têtes internes (patron ClubMailMetadata) lus/retirés au worker
        // par EmailSignatureListener, qui rend le bouton. L'URL EST le lien personnel — jamais
        // dupliqué en clair sous le bouton (le TEXTE le porte déjà), jamais stocké en boîte démo
        // (c'est un en-tête, pas un corps).
        if (null !== $link) {
            $headers = $email->getHeaders();
            $headers->addTextHeader(EmailTemplateRenderer::CTA_URL_HEADER, $link);
            $headers->addTextHeader(EmailTemplateRenderer::CTA_LABEL_HEADER, self::CTA_LABEL);
        }

        return $email;
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
    private function email(string $to, string $subject, array $lines, ?Club $club, ?Address $from = null): Email
    {
        // E-mail MÉTIER club (lien coach, digest/relance gestionnaire, récap) : candidat à
        // l'interception « boîte aux lettres » d'un club à horloge simulée (cf. ClubBusinessMail).
        // Le destinataire coach peut être un NON-utilisateur : l'intercepteur vérifie aussi
        // l'appartenance via Coach.email, pas seulement les membres.
        // L'expéditeur par défaut est l'adresse nue (digest/récap, impersonnels) ; le lien coach
        // passe un `from` personnalisé « Prénom (libellé) via produit » (D1).
        $email = ClubBusinessMail::mark(
            (new Email)
                ->from($from ?? $this->mailFrom->address())
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

<?php

declare(strict_types=1);

namespace App\Tests\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\AdminJob\AdminActionCatalog;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * P4-83 — aucun identifiant INTERNE ne doit fuir dans un texte lu par un humain.
 *
 * Les jetons de suivi du projet (`Pn-x`, `SEC-n`, `ENG-n`, `ADR-nnnn`, `AUD-*`,
 * `DOC-n`, `ALIGN-n`, `D-n`, `SAn`) sont une langue d'auteur : ils orientent un
 * développeur dans le récit du dépôt, jamais un gestionnaire de club ni un
 * consommateur du contrat. La SUBSTANCE d'un message reste ; la RÉFÉRENCE part
 * (« Management role required (SEC-07) » → « Management role required »). Décision
 * fondateur : le contrat OpenAPI (`/api/docs`) compte, le catalogue support compte,
 * les descriptions CLI (`bin/console list`) comptent. Les COMMENTAIRES de code
 * (lignes `//`, blocs, docblocks NON sérialisés) restent — ils sont la langue
 * d'auteur, à leur place.
 *
 * ⚠ Ce garde lit le contrat GÉNÉRÉ (`OpenApiFactoryInterface`), pas le snapshot :
 * « corrigé dans le snapshot mais pas la source » ne peut donc pas le tromper.
 *
 * ⚠ Contrôle positif EMBARQUÉ : {@see testThePatternActuallyMatchesKnownIdentifiers}
 * prouve que le motif MORD sur des exemples réels avant de conclure « rien ne
 * fuit » — un motif cassé passerait vert sur tout, sinon.
 *
 * Gating : `tests/OpenApi/` tourne dans le job `backend-tests` (`phpunit tests/`), donc
 * bloque le merge via « Backend Tests (suite + couverture) », PAS `build-docker`. Aucun axe structurant
 * (§7.1) n'est touché → pas de step `blocking-tests`.
 */
#[Group('phase1')]
final class PublicTextIsFreeOfInternalIdentifiersTest extends KernelTestCase
{
    /**
     * Les identifiants internes du dépôt. Un `\b` de part et d'autre pour ne mordre
     * que sur le jeton entier (« ADR-0002 », pas « GRADR-0002x »).
     */
    private const string PATTERN = '/\b(?:P\d-\d{1,3}|SEC-\d{1,3}|ENG-\d{1,3}|ADR-\d{4}|AUD-[A-Z]{2,5}-\d{1,3}|DOC-\d{1,3}|ALIGN-\d{1,3}|D-\d{1,3}|SA\d)\b/';

    /**
     * Exceptions justifiées — VIDE, et c'est l'état à défendre (modèle
     * `KNOWN_UNDOCUMENTED`). Un texte public ne s'exempte pas : il se purge. Ajouter
     * une ligne ici demande une raison écrite « ce jeton EST le sens, pas une
     * référence » (aucune connue à ce jour).
     *
     * @var list<string>
     */
    private const array ALLOWED = [];

    public function testThePatternActuallyMatchesKnownIdentifiers(): void
    {
        foreach ([
            'Management role required (SEC-07)',
            'Enregistre le paiement (abonnement par saison, P1-5)',
            'Support action (SA4).',
            'ADR-0002 — point this version as the plan in force',
            'a demo club (P4-16/P2-4)',
            'un exemple factice P9-99 introduit pour la preuve',
        ] as $sample) {
            self::assertMatchesRegularExpression(
                self::PATTERN,
                $sample,
                \sprintf('Le motif DOIT mordre sur « %s » — sinon il passerait vert sur tout.', $sample),
            );
        }

        // ...et NE mord PAS sur la même phrase une fois la référence retirée.
        self::assertDoesNotMatchRegularExpression(self::PATTERN, 'Management role required');
        self::assertDoesNotMatchRegularExpression(self::PATTERN, 'Support action.');
    }

    public function testTheGeneratedOpenApiContractIsFreeOfInternalIdentifiers(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        \assert($factory instanceof OpenApiFactoryInterface);

        // Le MÊME chemin que `api:openapi:export` (donc que le snapshot) : l'objet
        // OpenApi ne se JSON-sérialise pas de lui-même — il faut le normaliser.
        $normalizer = self::getContainer()->get('api_platform.openapi.normalizer');
        \assert($normalizer instanceof NormalizerInterface);
        $document = $normalizer->normalize($factory(), 'json', ['spec_version' => '3']);

        $offenders = [];
        $this->collectOffenders($document, '$', $offenders);

        self::assertSame([], $offenders, \sprintf(
            "Le contrat OpenAPI GÉNÉRÉ porte des identifiants internes, lus par tout\n"
            . "consommateur de `/api/docs` :\n  - %s\n\n"
            . "Retirez le jeton, gardez la phrase — la référence vit dans un commentaire de\n"
            . 'code, jamais dans un summary/description/response sérialisé.',
            implode("\n  - ", $offenders),
        ));
    }

    public function testTheAdminSupportCatalogIsFreeOfInternalIdentifiers(): void
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(AdminActionCatalog::class);
        \assert($catalog instanceof AdminActionCatalog);

        $offenders = [];
        foreach ($catalog->all() as $action) {
            $this->flag($action->label, \sprintf('action[%s].label', $action->key), $offenders);
            $this->flag($action->description, \sprintf('action[%s].description', $action->key), $offenders);

            foreach ($action->argumentSchema?->arguments ?? [] as $argument) {
                $this->flag($argument->label, \sprintf('action[%s].arg[%s].label', $action->key, $argument->key), $offenders);
                foreach ($argument->choices as $choice) {
                    $this->flag($choice, \sprintf('action[%s].arg[%s].choice', $action->key, $argument->key), $offenders);
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Le catalogue d'actions support (affiché dans la modale SA0) porte des\n"
            . "identifiants internes :\n  - %s",
            implode("\n  - ", $offenders),
        ));
    }

    public function testConsoleCommandDescriptionsAreFreeOfInternalIdentifiers(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $offenders = [];
        foreach ($application->all() as $name => $command) {
            $this->flag($command->getDescription(), \sprintf('command[%s].description', $name), $offenders);
            $this->flag($command->getHelp(), \sprintf('command[%s].help', $name), $offenders);
        }

        self::assertSame([], $offenders, \sprintf(
            "Des commandes console exposent des identifiants internes (lus par\n"
            . "`bin/console list`) :\n  - %s",
            implode("\n  - ", $offenders),
        ));
    }

    /**
     * Descend récursivement dans le document décodé et retient toute chaîne portant
     * un identifiant, avec son chemin JSON.
     *
     * @param list<string> $offenders
     */
    private function collectOffenders(mixed $node, string $path, array &$offenders): void
    {
        if (\is_string($node)) {
            $this->flag($node, $path, $offenders);

            return;
        }

        if (\is_array($node)) {
            foreach ($node as $key => $child) {
                $this->collectOffenders($child, \sprintf('%s.%s', $path, (string) $key), $offenders);
            }
        }
    }

    /** @param list<string> $offenders */
    private function flag(string $text, string $where, array &$offenders): void
    {
        if (preg_match_all(self::PATTERN, $text, $matches) < 1) {
            return;
        }

        foreach ($matches[0] as $token) {
            if (\in_array($token, self::ALLOWED, true)) {
                continue;
            }
            $offenders[] = \sprintf('%s : « %s » dans "%s"', $where, $token, $this->excerpt($text, $token));
        }
    }

    private function excerpt(string $text, string $token): string
    {
        $normalised = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (mb_strlen($normalised) <= 100) {
            return $normalised;
        }

        $at = mb_strpos($normalised, $token);
        $start = max(0, ($at ?: 0) - 30);

        return '…' . mb_substr($normalised, $start, 90) . '…';
    }
}

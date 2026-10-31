<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dependency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Promesse : deux stacks Docker cohabitent sur le poste sans se marcher dessus — noms, réseau et
 * ports viennent de l'environnement, la valeur par défaut reste celle du fondateur, et la
 * surcharge d'un worktree nu ne publie AUCUN port.
 *
 * Volet YAML/.env seulement. La résolution et le routage des cibles :
 * `backend/scripts/lib/stack-resolve-test.sh` (banc à faux docker).
 */
#[Group('phase1')]
final class ComposeStackParametrizationTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private const array NAMED_SERVICES = [
        'frontend', 'php-fpm', 'nginx', 'postgres', 'redis', 'messenger-worker',
        'cron-runner', 'engine', 'pdf-worker', 'mercure', 'mailpit',
    ];

    /** Les services qui publient un port : un nu ne doit en publier aucun. */
    private const array PUBLISHING_SERVICES = [
        'postgres', 'redis', 'engine', 'mercure', 'mailpit', 'nginx', 'frontend', 'frontend-dev',
    ];

    private const array EPREUVE_PORTS = [
        'NGINX_PORT' => '8180',
        'FRONTEND_PORT' => '8181',
        'ENGINE_PORT' => '8100',
        'POSTGRES_PORT' => '15432',
        'REDIS_PORT' => '16379',
        'MERCURE_PORT' => '13100',
        'MAILPIT_SMTP_PORT' => '11025',
        'MAILPIT_WEB_PORT' => '18025',
        'VITE_PORT' => '5273',
    ];

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function namedServices(): iterable
    {
        foreach (self::NAMED_SERVICES as $service) {
            yield $service => [$service];
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function publishingServices(): iterable
    {
        foreach (self::PUBLISHING_SERVICES as $service) {
            yield $service => [$service];
        }
    }

    /** Un `container_name` en dur collisionnerait avec la stack du fondateur. */
    #[DataProvider('namedServices')]
    public function testContainerNameComesFromTheStackVariable(string $service): void
    {
        self::assertSame(
            '${AMATEO_STACK:-amateo}-' . $service,
            $this->services('docker-compose.yml')[$service]['container_name'] ?? null,
        );
    }

    public function testExactlyTheNamedServicesCarryAContainerName(): void
    {
        $named = array_keys(array_filter(
            $this->services('docker-compose.yml'),
            static fn (array $service): bool => isset($service['container_name']),
        ));
        sort($named);
        $expected = self::NAMED_SERVICES;
        sort($expected);

        self::assertSame($expected, $named);
    }

    /** Un réseau partagé rendrait `postgres` résoluble vers la base du fondateur. */
    public function testNetworkNameComesFromTheStackVariable(): void
    {
        $compose = $this->yaml('docker-compose.yml');

        self::assertSame('${AMATEO_STACK:-amateo}_network', $compose['networks']['amateo_network']['name'] ?? null);
    }

    public function testViteHostPortIsAVariable(): void
    {
        self::assertSame(
            ['127.0.0.1:${VITE_PORT:-5173}:5173'],
            $this->services('docker-compose.yml')['frontend-dev']['ports'] ?? null,
        );
    }

    /** Une stack d'épreuve sur d'autres ports serait refusée par le hub sans ces origines. */
    public function testMercureCorsFollowsThePorts(): void
    {
        $directives = $this->services('docker-compose.yml')['mercure']['environment']['MERCURE_EXTRA_DIRECTIVES'] ?? '';
        self::assertIsString($directives);

        self::assertStringContainsString(
            'cors_origins http://localhost:${VITE_PORT:-5173} http://localhost:${FRONTEND_PORT:-8081}',
            $directives,
        );
    }

    public function testNoHostPortIsALiteral(): void
    {
        $seen = 0;
        foreach ($this->services('docker-compose.yml') as $name => $service) {
            foreach ($service['ports'] ?? [] as $port) {
                ++$seen;
                self::assertIsString($port);
                self::assertMatchesRegularExpression(
                    '/^127\.0\.0\.1:\$\{[A-Z_]+(:-\d+)?\}:\d+$/',
                    $port,
                    \sprintf('Service « %s » : port hôte littéral ou publié hors de la boucle locale.', $name),
                );
            }
        }

        self::assertSame(9, $seen, 'Témoin : les 9 ports publiés de la stack de dev.');
    }

    /** `!reset []` et non `[]` : compose FUSIONNE les listes, un `[]` nu laisserait le port publié. */
    #[DataProvider('publishingServices')]
    public function testPrivateOverrideResetsThePublishedPorts(string $service): void
    {
        $ports = $this->taggedServices()[$service]['ports'] ?? null;

        self::assertInstanceOf(TaggedValue::class, $ports, \sprintf('Service « %s » : `ports: !reset []` attendu.', $service));
        self::assertSame('reset', $ports->getTag());
        self::assertSame([], $ports->getValue());
    }

    public function testPrivateOverrideDisablesRestartOnEveryService(): void
    {
        $override = $this->taggedServices();

        foreach (array_keys($this->services('docker-compose.yml')) as $service) {
            self::assertSame(
                'no',
                $override[$service]['restart'] ?? null,
                \sprintf('Service « %s » : une stack de test ne doit pas renaître seule au reboot.', $service),
            );
        }
    }

    public function testEpreuveEnvNamesItsStack(): void
    {
        $env = $this->env('.env.epreuve.dist');

        self::assertSame('amateo-epreuve', $env['COMPOSE_PROJECT_NAME'] ?? null);
        self::assertSame('amateo-epreuve', $env['AMATEO_STACK'] ?? null);
    }

    public function testEpreuveEnvPortsAreTheAgreedOnes(): void
    {
        $env = $this->env('.env.epreuve.dist');

        foreach (self::EPREUVE_PORTS as $key => $port) {
            self::assertSame($port, $env[$key] ?? null, $key);
        }
    }

    /** Une clé manquante ferait échouer `compose` à l'interpolation (`${POSTGRES_DB}`…). */
    public function testEpreuveEnvCarriesEveryKeyOfTheDevEnv(): void
    {
        $missing = array_diff_key($this->env('.env.dist'), $this->env('.env.epreuve.dist'));

        self::assertSame([], array_keys($missing));
    }

    public function testEpreuvePortsNeverCollideWithTheFoundersPorts(): void
    {
        $ports = static fn (array $env): array => array_filter(
            $env,
            static fn (string $key): bool => str_ends_with($key, '_PORT'),
            \ARRAY_FILTER_USE_KEY,
        );
        $founder = $ports($this->env('.env.dist'));
        $epreuve = $ports($this->env('.env.epreuve.dist'));

        self::assertCount(\count(self::EPREUVE_PORTS), $epreuve);
        self::assertSame([], array_values(array_intersect($epreuve, $founder + ['VITE_PORT' => '5173'])));
    }

    /** CI et stack du fondateur s'appuient sur le projet par défaut (`ci.yml:36`) et les noms `amateo-*`. */
    public function testDevEnvStaysUnparameterized(): void
    {
        $env = $this->env('.env.dist');

        self::assertArrayNotHasKey('COMPOSE_PROJECT_NAME', $env);
        self::assertArrayNotHasKey('AMATEO_STACK', $env);
    }

    public function testProdKnowsNothingOfTheStackVariables(): void
    {
        foreach (['docker-compose.prod.yml', '.env.prod.dist'] as $file) {
            $contents = file_get_contents(self::ROOT . '/' . $file);
            self::assertIsString($contents);
            self::assertStringNotContainsString('AMATEO_STACK', $contents, $file);
            self::assertStringNotContainsString('VITE_PORT', $contents, $file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function yaml(string $file): array
    {
        self::assertFileExists(self::ROOT . '/' . $file);
        $parsed = Yaml::parseFile(self::ROOT . '/' . $file, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($parsed);

        /* @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function services(string $file): array
    {
        $services = $this->yaml($file)['services'] ?? null;
        self::assertIsArray($services, $file . ' n’a plus de bloc `services`.');

        /* @var array<string, array<string, mixed>> $services */
        return $services;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function taggedServices(): array
    {
        return $this->services('docker-compose.test.yml');
    }

    /**
     * @return array<string, string>
     */
    private function env(string $file): array
    {
        self::assertFileExists(self::ROOT . '/' . $file);
        $lines = file(self::ROOT . '/' . $file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        $env = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $env[$key] = $value;
        }

        return $env;
    }
}

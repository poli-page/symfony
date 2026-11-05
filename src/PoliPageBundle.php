<?php

declare(strict_types=1);

namespace PoliPage\Symfony;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class PoliPageBundle extends AbstractBundle
{
    protected string $extensionAlias = 'poli_page';

    public function configure(DefinitionConfigurator $definition): void
    {
        self::arrayRoot($definition->rootNode())
            ->children()
                ->scalarNode('api_key')->isRequired()->end()
                ->scalarNode('base_url')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static function (?string $v): bool {
                            // Why: treat empty string like null (unset → SDK default).
                            // Symfony's ValidateEnvPlaceholdersPass validates a
                            // '%env(POLI_PAGE_BASE_URL)%' placeholder with a synthetic ""
                            // at compile time, so rejecting empty would forbid env-based
                            // base_url config entirely.
                            if (null === $v || '' === $v) {
                                return false;
                            }
                            $scheme = parse_url($v, \PHP_URL_SCHEME);

                            return !\in_array($scheme, ['http', 'https'], true);
                        })
                        ->thenInvalid('base_url must use http or https scheme. Got: %s')
                    ->end()
                ->end()
                ->floatNode('timeout')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (?float $v): bool => null !== $v && ($v <= 0 || $v > 600))
                        ->thenInvalid('timeout must be > 0 and <= 600 seconds. Got: %s')
                    ->end()
                ->end()
                ->arrayNode('retries')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        // `max_attempts` is the deprecated former name of `max_retries`: same
                        // meaning (retries after the first attempt), so it fills `max_retries`.
                        ->ifTrue(static fn (mixed $v): bool => \is_array($v) && isset($v['max_attempts']))
                        ->then(static function (array $v): array {
                            if (isset($v['max_retries']) && $v['max_retries'] !== $v['max_attempts']) {
                                throw new InvalidConfigurationException(\sprintf('retries.max_retries (%s) and its deprecated alias retries.max_attempts (%s) are both set, to different values. Keep retries.max_retries only.', json_encode($v['max_retries']), json_encode($v['max_attempts'])));
                            }
                            $v['max_retries'] = $v['max_attempts'];

                            return $v;
                        })
                    ->end()
                    ->children()
                        ->integerNode('max_retries')
                            ->info('Retries after the first attempt, so a call makes at most max_retries + 1 attempts.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (?int $v): bool => null !== $v && ($v < 0 || $v > 10))
                                ->thenInvalid('retries.max_retries must be between 0 and 10. Got: %s')
                            ->end()
                        ->end()
                        ->integerNode('max_attempts')
                            ->setDeprecated('poli-page/symfony', '0.13.1', 'The "%node%" option is deprecated: it is the number of retries after the first attempt, not of attempts. Use "retries.max_retries" (same value).')
                            ->defaultNull()
                        ->end()
                        ->floatNode('delay_seconds')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (?float $v): bool => null !== $v && ($v < 0 || $v > 30))
                                ->thenInvalid('retries.delay_seconds must be between 0 and 30 seconds. Got: %s')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('http_client')->defaultNull()->end()
                ->scalarNode('request_factory')->defaultNull()->end()
                ->scalarNode('stream_factory')->defaultNull()->end()
                ->scalarNode('logger')->defaultNull()->end()
                ->scalarNode('on_retry')->defaultNull()->end()
                ->scalarNode('on_error')->defaultNull()->end()
                ->arrayNode('exception_listener')
                    ->info('Answers an uncaught PoliPageException with its toPayload() JSON body and status.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Why: Symfony 6.4 types rootNode() as NodeDefinition|ArrayNodeDefinition
     * (7.x narrows it in PHPDoc), but the bundle's root is always an array
     * node. Narrowing here, behind a NodeDefinition parameter, type-checks on
     * both versions.
     */
    private static function arrayRoot(NodeDefinition $node): ArrayNodeDefinition
    {
        \assert($node instanceof ArrayNodeDefinition);

        return $node;
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__.'/../config/services.php');

        $builder->setParameter('poli_page.api_key', $config['api_key']);
        $builder->setParameter('poli_page.base_url', $config['base_url']);
        $builder->setParameter('poli_page.timeout', $config['timeout']);
        $builder->setParameter('poli_page.retries.max_retries', $config['retries']['max_retries']);
        // Kept for compatibility: apps may reference %poli_page.retries.max_attempts%. Same value.
        $builder->setParameter('poli_page.retries.max_attempts', $config['retries']['max_retries']);
        $builder->setParameter('poli_page.retries.delay_seconds', $config['retries']['delay_seconds']);
        $builder->setParameter('poli_page.on_retry', $config['on_retry']);
        $builder->setParameter('poli_page.on_error', $config['on_error']);

        if (null !== $config['http_client']) {
            $builder->setAlias('poli_page.http_client', (string) $config['http_client']);
        }
        if (null !== $config['request_factory']) {
            $builder->setAlias('poli_page.request_factory', (string) $config['request_factory']);
        }
        if (null !== $config['stream_factory']) {
            $builder->setAlias('poli_page.stream_factory', (string) $config['stream_factory']);
        }
        if (null !== $config['logger']) {
            $builder->setAlias('poli_page.logger', (string) $config['logger']);
        }
        if (null !== $config['on_retry']) {
            $builder->setAlias('poli_page.retry_listener', (string) $config['on_retry'])->setPublic(true);
        }
        if (null !== $config['on_error']) {
            $builder->setAlias('poli_page.error_listener', (string) $config['on_error'])->setPublic(true);
        }

        if (true === ($config['exception_listener']['enabled'] ?? false)) {
            $builder
                ->register('poli_page.exception_listener', EventListener\PoliPageExceptionListener::class)
                ->addTag('kernel.event_subscriber')
                ->setPublic(true);
        }
    }
}

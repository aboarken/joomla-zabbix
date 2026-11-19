<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Extension\Service\Provider\RouterFactory;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessService;
use ZabbixPortal\Component\Zabbix\Administrator\Api\CurlTransport;
use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditLogInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\DbAuditLog;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\CacheInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\DbCache;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\DashboardService;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\WidgetDataService;
use ZabbixPortal\Component\Zabbix\Administrator\Discovery\EndpointProbe;
use ZabbixPortal\Component\Zabbix\Administrator\Extension\ZabbixComponent;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DbUserMapRepository;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\IdentityResolver;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\MappingManager;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapRepositoryInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\ZabbixUserDirectory;
use ZabbixPortal\Component\Zabbix\Administrator\Log\JoomlaLogger;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\AccessGuard;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HistoryService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HostService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\ProblemService;
use ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVault;
use ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVaultException;
use ZabbixPortal\Component\Zabbix\Administrator\Security\RateLimiter;
use ZabbixPortal\Component\Zabbix\Administrator\Security\SecretProvider;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\SettingsRepository;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $namespace = '\\ZabbixPortal\\Component\\Zabbix';

        $container->registerServiceProvider(new MVCFactory($namespace));
        $container->registerServiceProvider(new ComponentDispatcherFactory($namespace));
        $container->registerServiceProvider(new RouterFactory($namespace));

        $container->share(
            SettingsRepository::class,
            static fn (Container $c): SettingsRepository => new SettingsRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            JoomlaLogger::class,
            static fn (Container $c): JoomlaLogger => new JoomlaLogger($c->get(SettingsRepository::class)->load()->detailedLog())
        );

        $container->share(
            SecretProvider::class,
            static fn (): SecretProvider => new SecretProvider((string) Factory::getApplication()->get('secret', ''))
        );

        // Encrypts the API token at rest; throws KeyVaultException without a usable secret.
        $container->share(
            KeyVault::class,
            static fn (Container $c): KeyVault => new KeyVault($c->get(SecretProvider::class)->getSecret())
        );

        $container->share(
            DbAuditLog::class,
            static fn (Container $c): DbAuditLog => new DbAuditLog($c->get(DatabaseInterface::class), $c->get(JoomlaLogger::class))
        );
        $container->alias(AuditLogInterface::class, DbAuditLog::class);

        $container->share(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter($c->get(DbAuditLog::class)));

        $container->share(
            CacheInterface::class,
            static fn (Container $c): CacheInterface => new DbCache(
                $c->get(DatabaseInterface::class),
                $c->get(JoomlaLogger::class),
                $c->get(SettingsRepository::class)->load()->cacheTtl()
            )
        );

        // One client per page load, created when first needed. Throws ZabbixException
        // (NOT_CONFIGURED) when the address or token is missing or cannot be decrypted.
        $container->share(
            JsonRpcClient::class,
            static function (Container $c): JsonRpcClient {
                $settings = $c->get(SettingsRepository::class)->load();

                if (!$settings->isConfigured()) {
                    throw new ZabbixException(ZabbixException::NOT_CONFIGURED, 'The Zabbix connection is not set up');
                }

                try {
                    $vault = $c->get(KeyVault::class);
                } catch (KeyVaultException $e) {
                    throw new ZabbixException(ZabbixException::NOT_CONFIGURED, 'No encryption secret for the API token', '', '', $e);
                }

                return new JsonRpcClient(
                    $settings->apiUrl(),
                    $settings->apiToken($vault),
                    new CurlTransport(),
                    $settings->transportOptions(),
                    $c->get(JoomlaLogger::class)
                );
            }
        );

        $container->share(
            ZabbixUserDirectory::class,
            static fn (Container $c): ZabbixUserDirectory => new ZabbixUserDirectory($c->get(JsonRpcClient::class), $c->get(CacheInterface::class))
        );
        $directory = static fn (Container $c): \Closure => static fn (): ZabbixUserDirectory => $c->get(ZabbixUserDirectory::class);

        $container->share(
            UserMapRepositoryInterface::class,
            static fn (Container $c): UserMapRepositoryInterface => new DbUserMapRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            IdentityResolver::class,
            static fn (Container $c): IdentityResolver => new IdentityResolver(
                $c->get(UserMapRepositoryInterface::class),
                $directory($c),
                $c->get(AuditLogInterface::class),
                $c->get(JoomlaLogger::class),
                $c->get(SettingsRepository::class)->load()->autoMatch(),
                $c->get(SettingsRepository::class)->load()->matchMode(),
                null,
                $c->get(SettingsRepository::class)->load()->autoApprove()
            )
        );

        $container->share(
            MappingManager::class,
            static fn (Container $c): MappingManager => new MappingManager(
                $c->get(UserMapRepositoryInterface::class),
                $directory($c),
                $c->get(AuditLogInterface::class),
                $c->get(SettingsRepository::class)->load()->matchMode(),
                null,
                $c->get(SettingsRepository::class)->load()->autoApprove()
            )
        );

        $container->share(
            AccessService::class,
            static fn (Container $c): AccessService => new AccessService($c->get(JsonRpcClient::class), $c->get(CacheInterface::class))
        );

        $container->share(AccessGuard::class, static fn (Container $c): AccessGuard => new AccessGuard($c->get(JsonRpcClient::class)));
        $container->share(ProblemService::class, static fn (Container $c): ProblemService => new ProblemService($c->get(JsonRpcClient::class)));
        $container->share(HistoryService::class, static fn (Container $c): HistoryService => new HistoryService($c->get(JsonRpcClient::class)));

        $container->share(
            HostService::class,
            static fn (Container $c): HostService => new HostService($c->get(JsonRpcClient::class), $c->get(ProblemService::class))
        );

        $container->share(
            DashboardService::class,
            static fn (Container $c): DashboardService => new DashboardService($c->get(JsonRpcClient::class), $c->get(CacheInterface::class))
        );

        $container->share(
            WidgetDataService::class,
            static fn (Container $c): WidgetDataService => new WidgetDataService(
                $c->get(JsonRpcClient::class),
                $c->get(AccessGuard::class),
                $c->get(ProblemService::class),
                $c->get(HostService::class),
                $c->get(HistoryService::class)
            )
        );

        $container->share(EndpointProbe::class, static fn (Container $c): EndpointProbe => new EndpointProbe($c->get(JsonRpcClient::class)));

        $container->set(
            ComponentInterface::class,
            static function (Container $c): ZabbixComponent {
                $component = new ZabbixComponent($c->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($c->get(MVCFactoryInterface::class));
                $component->setRouterFactory($c->get(RouterFactoryInterface::class));
                $component->setServiceContainer($c);

                return $component;
            }
        );
    }
};

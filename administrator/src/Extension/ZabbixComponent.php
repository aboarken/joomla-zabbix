<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessService;
use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditLogInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\CacheInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\DashboardService;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\WidgetDataService;
use ZabbixPortal\Component\Zabbix\Administrator\Discovery\EndpointProbe;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\IdentityResolver;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\MappingManager;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapRepositoryInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\ZabbixUserDirectory;
use ZabbixPortal\Component\Zabbix\Administrator\Log\JoomlaLogger;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\AccessGuard;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HistoryService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HostService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\SeverityColours;
use ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVault;
use ZabbixPortal\Component\Zabbix\Administrator\Security\RateLimiter;
use ZabbixPortal\Component\Zabbix\Administrator\Security\SecretProvider;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\SettingsRepository;

/**
 * Component class. Gives controllers, models and views the services registered in
 * services/provider.php. Services that talk to Zabbix throw ZabbixException (NOT_CONFIGURED)
 * while Integration Settings are incomplete.
 */
final class ZabbixComponent extends MVCComponent implements RouterServiceInterface
{
    use RouterServiceTrait;

    private ?ContainerInterface $services = null;

    public function setServiceContainer(ContainerInterface $services): void
    {
        $this->services = $services;
    }

    /** @throws \ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVaultException without a usable secret */
    public function getKeyVault(): KeyVault
    {
        return $this->service(KeyVault::class);
    }

    public function getSecretProvider(): SecretProvider
    {
        return $this->service(SecretProvider::class);
    }

    public function getSettingsRepository(): SettingsRepository
    {
        return $this->service(SettingsRepository::class);
    }

    public function getAuditLog(): AuditLogInterface
    {
        return $this->service(AuditLogInterface::class);
    }

    public function getRateLimiter(): RateLimiter
    {
        return $this->service(RateLimiter::class);
    }

    public function getLogger(): JoomlaLogger
    {
        return $this->service(JoomlaLogger::class);
    }

    public function getCache(): CacheInterface
    {
        return $this->service(CacheInterface::class);
    }

    /** @throws \ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException */
    public function getClient(): JsonRpcClient
    {
        return $this->service(JsonRpcClient::class);
    }

    public function getUserDirectory(): ZabbixUserDirectory
    {
        return $this->service(ZabbixUserDirectory::class);
    }

    public function getUserMapRepository(): UserMapRepositoryInterface
    {
        return $this->service(UserMapRepositoryInterface::class);
    }

    public function getIdentityResolver(): IdentityResolver
    {
        return $this->service(IdentityResolver::class);
    }

    public function getMappingManager(): MappingManager
    {
        return $this->service(MappingManager::class);
    }

    public function getAccessService(): AccessService
    {
        return $this->service(AccessService::class);
    }

    public function getAccessGuard(): AccessGuard
    {
        return $this->service(AccessGuard::class);
    }

    public function getHostService(): HostService
    {
        return $this->service(HostService::class);
    }

    public function getHistoryService(): HistoryService
    {
        return $this->service(HistoryService::class);
    }

    public function getSeverityColours(): SeverityColours
    {
        return $this->service(SeverityColours::class);
    }

    public function getDashboardService(): DashboardService
    {
        return $this->service(DashboardService::class);
    }

    public function getWidgetDataService(): WidgetDataService
    {
        return $this->service(WidgetDataService::class);
    }

    public function getEndpointProbe(): EndpointProbe
    {
        return $this->service(EndpointProbe::class);
    }

    /**
     * @template T of object
     * @param  class-string<T>  $id
     * @return T
     */
    private function service(string $id): object
    {
        if ($this->services === null) {
            throw new \LogicException('Service container not set');
        }

        return $this->services->get($id);
    }
}

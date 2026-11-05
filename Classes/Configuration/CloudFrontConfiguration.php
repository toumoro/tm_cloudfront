<?php

declare(strict_types=1);

/*
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Toumoro\TmCloudfront\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Typed access to the extension configuration ($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront']).
 *
 * "distributionIds" accepts either
 *  - a JSON object mapping a domain to one or more comma separated distribution ids:
 *    {"www.example.com":"DIST1", "cdn.example.com":"DIST2, DIST3", "disabled.example.com":""}
 *  - or a plain comma separated list of distribution ids: DIST1,DIST2
 *
 * The configuration is read on each access (cheap array lookup), so it always reflects the active values.
 */
class CloudFrontConfiguration
{
    public const EXTENSION_KEY = 'tm_cloudfront';

    /**
     * CloudFront hard limit of paths per invalidation batch.
     */
    public const MAX_PATHS_PER_INVALIDATION = 3000;

    /**
     * @var array<string, array<int|string, string>> decoded mappings, indexed by their raw configuration value
     */
    private array $distributionMappings = [];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function getApiKey(): string
    {
        return (string)($this->getSettings()['apikey'] ?? '');
    }

    public function getApiSecret(): string
    {
        return (string)($this->getSettings()['apisecret'] ?? '');
    }

    public function getRegion(): string
    {
        return (string)($this->getSettings()['region'] ?? '') ?: 'us-east-1';
    }

    public function getVersion(): string
    {
        return (string)($this->getSettings()['version'] ?? '') ?: 'latest';
    }

    /**
     * "live": invalidations are sent immediately, anything else: invalidations are queued in the database
     * and sent by the scheduler task.
     */
    public function isLiveMode(): bool
    {
        return ($this->getSettings()['mode'] ?? '') === 'live';
    }

    public function isLogDisabled(): bool
    {
        return (bool)($this->getSettings()['disableLog'] ?? false);
    }

    /**
     * Above this number of paths for a single distribution, a global "/*" invalidation is sent instead.
     */
    public function getMaxPathsPerInvalidation(): int
    {
        $max = (int)($this->getSettings()['maxInvalidationPaths'] ?? 0);
        return $max > 0 ? min($max, self::MAX_PATHS_PER_INVALIDATION) : self::MAX_PATHS_PER_INVALIDATION;
    }

    /**
     * Cache tags handled with CloudFront tag invalidation: table names or tag prefixes,
     * e.g. ["tx_news_domain_model_news", "tx_news_pid"]. Empty: the feature is disabled.
     *
     * @return list<string>
     */
    public function getCacheTagPrefixes(): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string)($this->getSettings()['cacheTags'] ?? ''))),
            static fn(string $prefix): bool => $prefix !== ''
        )));
    }

    /**
     * Response header holding the cache tags (CacheTagConfig.HeaderName of the distributions).
     */
    public function getCacheTagHeader(): string
    {
        return trim((string)($this->getSettings()['cacheTagHeader'] ?? '')) ?: 'x-amz-meta-cache-tag';
    }

    /**
     * True if the tag is a configured table / prefix ("tx_news_domain_model_news") or one of its records
     * ("tx_news_domain_model_news_12").
     */
    public function isCloudFrontCacheTag(string $tag): bool
    {
        foreach ($this->getCacheTagPrefixes() as $prefix) {
            if ($tag === $prefix || str_starts_with($tag, $prefix . '_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Raw mapping as configured: domain => "ID1,ID2" (JSON form) or index => "ID" (list form).
     *
     * @return array<int|string, string>
     */
    public function getDistributionMapping(): array
    {
        $mapping = (string)($this->getSettings()['distributionIds'] ?? '');
        if (!isset($this->distributionMappings[$mapping])) {
            $decoded = str_starts_with(ltrim($mapping), '{') ? json_decode($mapping, true) : null;
            $this->distributionMappings[$mapping] = array_map(
                'strval',
                is_array($decoded) ? $decoded : explode(',', $mapping)
            );
        }
        return $this->distributionMappings[$mapping];
    }

    /**
     * Comma separated list of every configured distribution id.
     */
    public function getAllDistributionIds(): string
    {
        return implode(',', array_values($this->getDistributionMapping()));
    }

    /**
     * Every configured distribution id, trimmed and de-duplicated.
     *
     * @return list<string>
     */
    public function getAllDistributionIdList(): array
    {
        return self::splitDistributionIds($this->getAllDistributionIds());
    }

    /**
     * Distribution ids mapped to a domain, or null when the domain is not mapped.
     * An empty string means "mapped but disabled".
     */
    public function getDistributionIdsForDomain(string $domain): ?string
    {
        return $this->getDistributionMapping()[$domain] ?? null;
    }

    /**
     * Distribution ids mapped to a domain, falling back to all distributions when the domain is not mapped.
     */
    public function getDistributionIdsForDomainOrAll(string $domain): string
    {
        return $this->getDistributionIdsForDomain($domain) ?? $this->getAllDistributionIds();
    }

    /**
     * @return list<string>
     */
    public static function splitDistributionIds(?string $distributionIds): array
    {
        if ($distributionIds === null || $distributionIds === '') {
            return [];
        }
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $distributionIds)),
            static fn(string $id): bool => $id !== ''
        )));
    }

    private function getSettings(): array
    {
        try {
            $settings = $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Exception) {
            return [];
        }
        $settings = is_array($settings) ? $settings : [];
        // Settings are nested under "cloudfront" (ext_conf_template), but tolerate a flat configuration.
        return is_array($settings['cloudfront'] ?? null) ? $settings['cloudfront'] : $settings;
    }
}

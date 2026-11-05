<?php

declare(strict_types=1);

/*
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Toumoro\TmCloudfront\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Toumoro\TmCloudfront\Configuration\CloudFrontConfiguration;
use TYPO3\CMS\Core\Cache\CacheDataCollectorInterface;
use TYPO3\CMS\Core\Cache\CacheTag;

/**
 * Sends the page cache tags of the configured tables ("cacheTags" setting) in the response header configured
 * in the CloudFront distributions (CacheTagConfig.HeaderName, "cacheTagHeader" setting), e.g.:
 *
 *   x-amz-meta-cache-tag: tx_news_domain_model_news_12, tx_news_pid_5
 *
 * Works for freshly rendered pages and pages served from the TYPO3 page cache (their tags are restored).
 */
final readonly class CacheTagHeaderMiddleware implements MiddlewareInterface
{
    /**
     * CloudFront ignores the tags beyond 50 per object.
     */
    private const MAX_TAGS = 50;

    public function __construct(
        private CloudFrontConfiguration $configuration,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $collector = $request->getAttribute('frontend.cache.collector');
        if (!$collector instanceof CacheDataCollectorInterface || $this->configuration->getCacheTagPrefixes() === []) {
            return $response;
        }

        $tags = array_values(array_unique(array_filter(
            array_map(static fn(CacheTag $tag): string => $tag->name, $collector->getCacheTags()),
            $this->configuration->isCloudFrontCacheTag(...)
        )));
        if ($tags === []) {
            return $response;
        }

        return $response->withHeader(
            $this->configuration->getCacheTagHeader(),
            implode(', ', array_slice($tags, 0, self::MAX_TAGS))
        );
    }
}

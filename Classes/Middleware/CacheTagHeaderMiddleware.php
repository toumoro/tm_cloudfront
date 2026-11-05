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
use Psr\Log\LoggerInterface;
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
 *
 * CloudFront keeps at most 50 tags per object. Above that (e.g. a list rendering many records with
 * frontend.cache.autoTagging), the tags are reduced instead of cut arbitrarily, see reduceTags().
 */
final readonly class CacheTagHeaderMiddleware implements MiddlewareInterface
{
    /**
     * CloudFront ignores the tags beyond 50 per object.
     */
    private const MAX_TAGS = 50;

    public function __construct(
        private CloudFrontConfiguration $configuration,
        private LoggerInterface $logger,
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

        if (count($tags) > self::MAX_TAGS) {
            $tags = $this->reduceTags($tags, (string)$request->getUri());
        }

        return $response->withHeader($this->configuration->getCacheTagHeader(), implode(', ', $tags));
    }

    /**
     * Brings the tags down to the CloudFront limit, keeping the page invalidated by the broadest tags:
     *  1. A record tag "<table>_<uid>" is dropped when "<table>" is also present: saving the record flushes
     *     "<table>" too (DataHandler), so the page is still invalidated.
     *  2. The tags equal to a "cacheTags" entry (e.g. table names) come first, so they survive the cut.
     *  3. Still too many: the remaining tags are cut and a warning is logged.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    private function reduceTags(array $tags, string $url): array
    {
        $present = array_flip($tags);
        $tags = array_values(array_filter(
            $tags,
            static function (string $tag) use ($present): bool {
                // "<table>_<uid>" with "<table>" present
                return !(preg_match('/^(.+)_\d+$/', $tag, $matches) === 1 && isset($present[$matches[1]]));
            }
        ));
        if (count($tags) <= self::MAX_TAGS) {
            return $tags;
        }

        $entries = array_flip($this->configuration->getCacheTagPrefixes());
        usort($tags, static fn(string $a, string $b): int => (int)!isset($entries[$a]) <=> (int)!isset($entries[$b]));

        $this->logger->warning(
            'CloudFront keeps at most {max} cache tags per object, {dropped} tags of {url} are not sent: '
            . 'this page will not be invalidated by these tags. Tag the page with a broader tag (e.g. the table name).',
            ['max' => self::MAX_TAGS, 'dropped' => count($tags) - self::MAX_TAGS, 'url' => $url]
        );
        return array_slice($tags, 0, self::MAX_TAGS);
    }
}

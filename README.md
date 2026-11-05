# TYPO3 Extension tm_cloudfront

This extension clears the AWS CloudFront cache when content changes in TYPO3:

- **Pages and content elements**: the speaking path of the page is invalidated in every language
  (pages: the parent page, recursively).
- **Clear cache buttons**: "Flush frontend caches" / "Flush all caches" invalidate `/*`, the page module
  button invalidates the page.
- **Files and folders** (FAL): moved, renamed, replaced, deleted or modified files and folders.
- **Records (cache tags)**: for the cache tags you choose (e.g. the news tags), TYPO3 sends the page
  cache tags to CloudFront in a response header. Saving a record then invalidates every cached object
  carrying its tag, using
  [CloudFront invalidation by cache tag](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/invalidation-by-tags.html).

## Installation (TYPO3 v14)

```
composer require toumoro/tm-cloudfront
```

Then update the database schema (`vendor/bin/typo3 extension:setup`).

## Configuration

1. **CloudFront settings**

   In the Install Tool (Settings > Extension Configuration) or in `config/system/settings.php`:
   ```php
   'EXTENSIONS' => [
       'tm_cloudfront' => [
           'cloudfront' => [
               'apikey' => 'YOUR_AWS_KEY',
               'apisecret' => 'YOUR_AWS_SECRET',
               'region' => 'us-east-1',
               'version' => 'latest',
               'mode' => 'live',
               'distributionIds' => '{"domain1.com":"DIST_ID_1", "domain2.com":"DIST_ID_2", "cdn.domain3.com":"DIST_ID_3", "domain4.com":"DIST_ID_4, DIST_ID_5", "domain5.com":""}',
               'disableLog' => false,
               'maxInvalidationPaths' => 0,
               'cacheTags' => 'tx_news_uid, tx_news_pid, tx_news_domain_model_news',
               'cacheTagHeader' => 'x-amz-meta-cache-tag',
           ],
       ],
   ],
   ```
   - `distributionIds`: JSON mapping `domain => distribution id(s)`, or a plain comma separated list of
     distribution ids. To disable the invalidation for a domain, map it to an empty string (`domain5.com`).
     A domain that is not mapped invalidates every distribution.
   - `mode`: `live` sends the invalidations immediately. Any other value (e.g. `table`) stores them in the
     database. The scheduler task "Clear cloud front cache" then sends them in batches.
   - `disableLog`: do not write the invalidations in the TYPO3 system log.
   - `maxInvalidationPaths` (optional): above this number of paths for one distribution, `/*` is invalidated
     instead. `0` means the CloudFront limit (3000).
   - `cacheTags` (optional): the cache tags to invalidate in CloudFront, as a comma separated list of table names or
     tag prefixes. See [Clearing the cache of records](#clearing-the-cache-of-records-cache-tags). Empty: disabled.
   - `cacheTagHeader` (optional): the response header holding the cache tags. Default: `x-amz-meta-cache-tag`.

2. **Storage / CDN mapping**

   For files, set the CDN domain in the storage configuration (`publicBaseUrl`). Storages without
   `publicBaseUrl` (local driver) invalidate every distribution.

3. **Page TSconfig (optional)**

   Force the distributions used for a page tree:
   ```
   TCEMAIN.distributionIds = DIST_ID_1
   ```

4. **AWS permissions**

   The AWS user needs the `cloudfront:CreateInvalidation` permission.

## Clearing the cache of records (cache tags)

CloudFront can invalidate cached objects by tag instead of by path. The origin (TYPO3) sends the tags of each page
in a response header. CloudFront stores them with the cached object, and an invalidation of `#<tag>` removes every
object carrying that tag, whatever its URL (detail pages, list pages, query string variants...).

### 1. Choose the tags

List the tables (or tag prefixes) to handle in the `cacheTags` setting. For the news extension:

```php
'cacheTags' => 'tx_news_uid, tx_news_pid, tx_news_domain_model_news',
```

Each entry matches the tag itself and every tag starting with `<entry>_`:

| Entry | Matching tags | Added by news on | Flushed when |
|---|---|---|---|
| `tx_news_uid` | `tx_news_uid_12`, ... | detail views (the news and its related news) | news 12 is saved |
| `tx_news_pid` | `tx_news_pid_5`, ... | list views with storage folder 5 | a news of folder 5 is saved |
| `tx_news_domain_model_news` | `tx_news_domain_model_news` | list views without storage folder | any news is saved |

News adds these tags itself, so nothing else is needed for news.

Pages and content elements don't need tags: they are invalidated by path, as before.

### 2. Enable cache tags on the CloudFront distributions

Add a `CacheTagConfig` to **every distribution** of the `distributionIds` setting (tag invalidations are sent to all
of them). The header name must match the `cacheTagHeader` setting:

```json
"CacheTagConfig": {
  "HeaderName": "x-amz-meta-cache-tag"
}
```

With the AWS CLI:

```
aws cloudfront get-distribution-config --id DIST_ID_1 > dist.json
# Edit dist.json: keep only the "DistributionConfig" object and add the "CacheTagConfig" above
aws cloudfront update-distribution --id DIST_ID_1 --if-match <ETag> --distribution-config file://dist.json
```

Distributions without `CacheTagConfig` ignore the header, and tag invalidations have no effect on them.

### 3. The cache tag header

TYPO3 adds the header automatically. A frontend middleware writes the page cache tags matching `cacheTags`, for pages
rendered and for pages served from the TYPO3 page cache:

```
HTTP/1.1 200 OK
Content-Type: text/html; charset=utf-8
x-amz-meta-cache-tag: tx_news_uid_12, tx_news_uid_7
```

Check it with `curl -sI https://www.example.com/news/my-article | grep -i cache-tag`. CloudFront only stores the tags
when the object is fetched from the origin. Objects cached before the configuration are only tagged after they
expire. Invalidate `/*` once to tag them right away.

The header only lists the configured tags. If you don't want it to reach
the browsers, remove it with a CloudFront response headers policy (`RemoveHeadersConfig`).

CloudFront keeps at most 50 tags per object. A page can carry more matching tags, e.g. a list rendering many records
with `frontend.cache.autoTagging` (one `<table>_<uid>` tag per record). Above 50, the tags are reduced:

1. `<table>_<uid>` is dropped when `<table>` is also present: saving the record in the backend flushes both, so the
   page is still invalidated.
2. The tags equal to a `cacheTags` entry (e.g. table names) are kept first.
3. If there are still more than 50, the rest is not sent and a warning is logged with the URL. Changes to these records
   won't invalidate that page: tag it with a broader tag (the table name, a storage folder tag...).

Code that flushes only `<table>_<uid>` (without `<table>`) doesn't invalidate a page reduced by rule 1.

The page cache tags come from:

- extensions that tag their pages, e.g. news (see the table above),
- your own code:
  ```php
  $request->getAttribute('frontend.cache.collector')
      ->addCacheTags(new \TYPO3\CMS\Core\Cache\CacheTag('tx_myext_domain_model_item_' . $item->getUid()));
  ```
- TypoScript: `stdWrap.addPageCacheTags = tx_myext_domain_model_item_12`, or the "Cache tags" field of a page,
- the core feature `frontend.cache.autoTagging`, which tags the pages with every record they render:
  ```php
  $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['frontend.cache.autoTagging'] = true;
  ```

### Example: tag a custom record detail view with TypoScript

A plugin of your extension shows the record `tx_myext_domain_model_item` with the uid in the
`tx_myext_item[item]` argument, for example `/products/item/12` with a route enhancer. Tag the detail page with
`tx_myext_domain_model_item_<uid>`, the tag the DataHandler flushes when the record is saved:

```
page.stdWrap.addPageCacheTags.cObject = TEXT
page.stdWrap.addPageCacheTags.cObject {
  # uid of the record from the plugin argument (route enhancers fill it too)
  data = GP:tx_myext_item|item
  # Only digits: a cache tag with other characters makes the page cache fail
  intval = 1
  wrap = tx_myext_domain_model_item_|
  # Only on the detail view
  if.isTrue.data = GP:tx_myext_item|item
}
```

Then add the table to the extension configuration:

```php
'cacheTags' => 'tx_myext_domain_model_item',
```

The detail page now answers with `x-amz-meta-cache-tag: tx_myext_domain_model_item_12`. When item 12 is saved in the
backend, TYPO3 flushes `tx_myext_domain_model_item_12`. The page is removed from the TYPO3 page cache and
`#tx_myext_domain_model_item_12` is invalidated in CloudFront. No hook is needed.

Notes:

- The tags are added to the existing ones (TYPO3, news, other `addPageCacheTags`...). But
  `page.stdWrap.addPageCacheTags` is one TypoScript property: if it already has a value, the `cObject` replaces it.
  Combine both in a `COA`:
  ```
  page.stdWrap.addPageCacheTags.cObject = COA
  page.stdWrap.addPageCacheTags.cObject {
    10 = TEXT
    10.value = my_custom_tag, another_tag
    20 = TEXT
    20 {
      data = GP:tx_myext_item|item
      intval = 1
      wrap = ,tx_myext_domain_model_item_|
      if.isTrue.data = GP:tx_myext_item|item
    }
  }
  ```
  or use `addPageCacheTags` on another object (e.g. `page.10.stdWrap.addPageCacheTags`).
- Extbase plugin arguments are named `tx_<extension key without underscores>_<plugin name in lowercase>`, e.g.
  `tx_myext_item[item]` for the plugin `Item` of `my_ext`. Check the name in a non-speaking URL.
- With a route enhancer, use an aspect (e.g. `PersistedAliasMapper` or `StaticRangeMapper`) for the uid,
  otherwise the URL needs a cHash.
- Records changed outside the DataHandler (e.g. imports, Extbase repositories in the frontend) don't flush the
  tag. Flush it yourself with `CacheManager::flushCachesInGroupByTag('pages', 'tx_myext_domain_model_item_12')`.
- To also refresh the list views when any item changes, tag them with the table name
  (`page.stdWrap.addPageCacheTags = tx_myext_domain_model_item`, e.g. in a condition for the list page).
  The DataHandler flushes it on every save of the table.

### 4. Invalidation

Each time TYPO3 flushes page cache tags, the tags matching `cacheTags` are invalidated as `#<tag>` on every
distribution, in the same way as paths (`live` or `table` mode). TYPO3 flushes tags in these cases:

- a record is saved (tags `<table>` and `<table>_<uid>`, plus the tags flushed by the extension's own hooks,
  e.g. `tx_news_uid_<uid>` and `tx_news_pid_<pid>` for news),
- the page TSconfig `TCEMAIN.clearCacheCmd = cacheTag:<tag>`,
- code calling `CacheManager::flushCachesInGroupByTag(s)('pages', ...)`.

Each tag counts as one invalidation path in CloudFront quotas and pricing.

## Usage

- Use the "Clear Cache" buttons in TYPO3 to trigger CloudFront invalidations.
- Invalidations are handled automatically according to your configuration.
- In `table` mode, create the scheduler task "Clear cloud front cache".

## Testing

```
composer install
RUNTESTS_DIR_BIN=.Build/bin/ ./Build/Scripts/runTests.sh -p 8.4 -s functional
RUNTESTS_DIR_BIN=.Build/bin/ ./Build/Scripts/runTests.sh -p 8.4 -d mariadb -s functional
```

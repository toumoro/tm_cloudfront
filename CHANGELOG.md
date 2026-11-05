# Changelog
All notable changes to this extension will be documented in this file.

## [14.0.0] - unreleased
### Changed
- TYPO3 v14.3 support (TYPO3 v12/v13 support dropped, use the 13.x versions).
- The scheduler task is a native (TCA based) task. Existing "Clear cloud front cache" tasks keep working.
- Event listeners are registered with PHP attributes. Services use dependency injection.
- Code refactored into a configuration service, an invalidation dispatcher and a queue repository.
  The extension configuration (`distributionIds` mapping, mode, credentials) and the invalidated paths are unchanged.
- The invalidation queue table no longer has the unused language, workspace and visibility fields.

### Added
- Record cache clearing with CloudFront invalidation by cache tag: the page cache tags of the tables configured in
  the new `cacheTags` setting are sent in a response header (`cacheTagHeader`, default `x-amz-meta-cache-tag`) and
  invalidated as `#<tag>` when TYPO3 flushes them (record saved, `cacheTag:` clear cache commands, extensions).
- Optional `maxInvalidationPaths` setting: above this number of paths, `/*` is invalidated instead.

### Fixed
- File and folder invalidations use the public URL of the resource: local storages now invalidate the real path
  (`/fileadmin/dir/x.pdf` instead of `/dir/x.pdf`), and CDN storages keep the path prefix of their base URL
  (`https://cdn.example.com/media/` → `/media/dir/x.pdf`). The distribution is resolved with the domain of that URL.
- `DataHandler::getPID()` no longer exists in TYPO3 v14.
- Invalidation queue is emptied after each send (paths were sent again on later calls in live mode).
- Queued invalidations (table mode): one query per batch instead of one per path. The task also processes
  distribution ids configured with spaces (`"ID1, ID2"`).
- Batches larger than the CloudFront limit (3000 paths) are collapsed to `/*` instead of failing.
- Multi-domain sites: a language domain missing from the mapping no longer throws a TypeError.
- A page that cannot be linked in a language is skipped instead of throwing an exception.
- Page cache command (clear cache button) when no site domain is mapped (e.g. plain list of distribution ids):
  every distribution is invalidated, as for records.
- Page TSconfig `TCEMAIN.clearCache_disable` is respected.
- Page TSconfig of content elements is read from their page.
- The scheduler task reports a failure when CloudFront rejects an invalidation.

## [13.0.6] - 2025-10-17
### Fixed
- Fixed issue with cache invalidation when processed files are flushed.

## [13.0.5] - 2025-09-08
### Fixed
- Fixed navigation error when clearing cache while impersonating a backend user.

## [13.0.4] - 2025-09-02
### Fixed
- Fixed cache invalidation bug when creating or deleting a page.
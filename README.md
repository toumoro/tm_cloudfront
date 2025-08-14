# TYPO3 Extension tm_cloudfront

This extension clears the AWS CloudFront cache based on the speaking path of a page by creating an AWS CloudFront invalidation queue based on clearCacheCmd.

## Installation (TYPO3 v13)

### Using Composer

1. **Require the extension via Composer**  
   In your TYPO3 project root, run:
   ```
   composer require toumoro/tm-cloudfront
   ```

## Configuration

1. **CloudFront Settings**

   - Add your configuration in the TYPO3 backend or in `config/system/settings.php`:
     ```php
     'EXTENSIONS' => [
         'tm_cloudfront' => [
             'apikey' => 'YOUR_AWS_KEY',
             'apisecret' => 'YOUR_AWS_SECRET',
             'region' => 'us-east-1',
             'version' => 'latest',
             'distributionIds' => '{"domain1.com":"DIST_ID_1", "domain2.com":"DIST_ID_2", "cdn.domain3.com":"DIST_ID_3", "domain4.com":"DIST_ID_4, DIST_ID_5"}'
         ]
     ]
     ```

2. **Storage/CDN Mapping**

   - For files, set the CDN domain in the storage configuration (`domain` field).

3. **TSconfig (optional)**

   - Add to your page configuration to customize cache commands:
     ```
     distributionIds = DIST_ID_1
     ```

4. **AWS Permissions**
   - The AWS user must have permission to invalidate CloudFront cache.

## Usage

- Use the "Clear Cache" button in TYPO3 to trigger CloudFront invalidation.
- Invalidations are handled automatically according to your configuration.

## Testing

```
composer install
RUNTESTS_DIR_BIN=.Build/bin/ ./Build/Scripts/runTests.sh -p 8.2 -s functional
```

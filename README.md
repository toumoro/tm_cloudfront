# TYPO3 Extension tm_cloudfront

This extension clears the AWS CloudFront cache based on the speaking path of a page by creating an AWS CloudFront invalidation queue based on clearCacheCmd.


docker run -ti --rm -w /var/www -v "$PWD:/var/www" toumoro/docker-apache-php:7.4-prod composer install

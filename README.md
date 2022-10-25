# TYPO3 Extension tm_cloudfront

This extension clears the AWS CloudFront cache based on the speaking path of a page by creating an AWS CloudFront invalidation queue based on clearCacheCmd.


sudo rm -rf .Build composer.lock public && mkdir .Build && sudo chown -R souellet:souellet .Build
docker run -ti --rm -w /var/www -v "$PWD:/var/www" toumoro/docker-apache-php:7.4-prod composer install
sudo chown -R souellet:souellet .Build
Build/Scripts/runTests.sh -s functional

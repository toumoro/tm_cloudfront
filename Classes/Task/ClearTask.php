<?php

 /***
 *
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS by Toumoro.com.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2018 Toumoro.com (Simon Ouellet)
 *
 ***/

namespace Toumoro\TmCloudfront\Task;

class ClearTask extends \TYPO3\CMS\Scheduler\Task\AbstractTask {

    public function execute() {


        list($row) = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('COUNT(*) AS t', 'tx_tmcloudfront_domain_model_invalidation', '');
        $count = $row['t'];

        if ($count > 0) {


            $this->cloudFrontConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['tm_cloudfront']);
            $this->cloudFrontConfiguration = $this->cloudFrontConfiguration['cloudfront.'];


            $options = [
                'version' => $this->cloudFrontConfiguration['version'],
                'region' => $this->cloudFrontConfiguration['region'],
                'credentials' => [
                    'key' => $this->cloudFrontConfiguration['apikey'],
                    'secret' => $this->cloudFrontConfiguration['apisecret'],
                ]
            ];
            $cloudFront = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('Aws\CloudFront\CloudFrontClient', $options);



            $list = $cloudFront->listInvalidations([
                'DistributionId' => $this->cloudFrontConfiguration['distributionId'],
                'MaxItems' => '30',
            ]);

            /* the max is 15 but we let 5 spot available for manual clear cache in the backend */
            $availableInvalidations = 10;
            $items = $list->get('InvalidationList')['Items'];
            if (!empty($items)) {
                foreach ($items as $k => $value) {
                    if ($value["Status"] != 'Completed') {
                        $availableInvalidations--;
                    }
                }
            }
  
            if ($availableInvalidations > 0) {
                $rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('*', 'tx_tmcloudfront_domain_model_invalidation', '', '', '', $availableInvalidations);
                foreach ($rows as $k => $value) {

                    $GLOBALS['BE_USER']->simplelog($value['pathsegment'], "tm_cloudfront");
                    $result = $cloudFront->createInvalidation([
                        'DistributionId' => $this->cloudFrontConfiguration['distributionId'], // REQUIRED
                        'InvalidationBatch' => [// REQUIRED
                            'CallerReference' => $this->generateRandomString(16), // REQUIRED
                            'Paths' => [// REQUIRED
                                'Items' => array($value['pathsegment']), // items or paths to invalidate
                                'Quantity' => 1, // REQUIRED (must be equal to the number of 'Items' in the previus line)
                            ]
                        ]
                    ]);

                    $GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_tmcloudfront_domain_model_invalidation', 'uid = ' . $value['uid']);
                }
            }
        }
        return true;
    }

    /**
     * Generate a random string
     * @param int $length length of the string
     * @return string
     */
    protected function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

}

<?php
namespace Toumoro\TmCloudfront\Domain\Model;

/***
 *
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2018 Simon Ouellet, Toumoro
 *
 ***/

/**
 * Invalidation
 */
class Invalidation extends \TYPO3\CMS\Extbase\DomainObject\AbstractEntity
{
    /**
     * pathsegment
     *
     * @var string
     */
    protected $pathsegment = '';

    /**
     * Returns the pathsegment
     *
     * @return string $pathsegment
     */
    public function getPathsegment()
    {
        return $this->pathsegment;
    }

    /**
     * Sets the pathsegment
     *
     * @param string $pathsegment
     * @return void
     */
    public function setPathsegment($pathsegment)
    {
        $this->pathsegment = $pathsegment;
    }
}

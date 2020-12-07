<?php
namespace Toumoro\TmCloudfront\Tests\Unit\Domain\Model;

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

class InvalidationTest extends \TYPO3\CMS\Core\Tests\UnitTestCase
{
    /**
     * @var \Toumoro\TmCloudfront\Domain\Model\Invalidation
     */
    protected $subject = null;

    protected function setUp()
    {
        parent::setUp();
        $this->subject = new \Toumoro\TmCloudfront\Domain\Model\Invalidation();
    }

    protected function tearDown()
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function getPathsegmentReturnsInitialValueForString()
    {
        self::assertSame(
            '',
            $this->subject->getPathsegment()
        );
    }

    /**
     * @test
     */
    public function setPathsegmentForStringSetsPathsegment()
    {
        $this->subject->setPathsegment('Conceived at T3CON10');

        self::assertAttributeEquals(
            'Conceived at T3CON10',
            'pathsegment',
            $this->subject
        );
    }
}

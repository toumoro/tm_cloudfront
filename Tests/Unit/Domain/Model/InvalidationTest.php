<?php
namespace Toumoro\TmCloudfront\Tests\Unit\Domain\Model;

use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Toumoro\TmCloudfront\Domain\Model\Invalidation;
/**
 * Test case.
 *
 * @author Simon Ouellet 
 */
class InvalidationTest extends UnitTestCase
{
    /**
     * @var Invalidation
     */
    protected $subject = null;

    protected function setUp()
    {
        parent::setUp();
        $this->subject = new Invalidation();
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

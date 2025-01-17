<?php
namespace ZBateson\MailMimeParser;

use LegacyPHPUnit\TestCase;
use Pimple\Exception\UnknownIdentifierException;

/**
 * Description of ContainerTest
 *
 * @group Container
 * @group Base
 * @covers ZBateson\MailMimeParser\Container
 * @author Zaahid Bateson
 */
class ContainerTest extends TestCase
{
    private $container;
    
    protected function legacySetUp()
    {
        $this->container = new Container();
    }
    
    public function testSetAndGet()
    {
        $this->container['test'] = 'toost';
        $this->assertSame('toost', $this->container['test']);
    }

    public function testAutoRegister()
    {
        $this->assertFalse($this->container->offsetExists('blah'));
        $this->assertTrue($this->container->offsetExists('ArrayObject'));
        $this->assertInstanceOf('SplFixedArray', $this->container->offsetGet('SplFixedArray'));
        $thrown = false;
        try {
            $this->container->offsetGet('Arooo');
        } catch (UnknownIdentifierException $ex) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }

    public function testAutoRegisterParams()
    {
        $this->container['secondArg'] = 'Aha!';
        $ob = $this->container['ZBateson\MailMimeParser\ContainerTestClass'];
        $this->assertNotNull($ob);
        $this->assertInstanceOf('ZBateson\MailMimeParser\ContainerTestClass', $ob);
        $this->assertInstanceOf('SplFixedArray', $ob->firstArg);
        $this->assertSame('Aha!', $ob->secondArg);
    }
}

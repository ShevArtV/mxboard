<?php

declare(strict_types=1);

namespace Mxboard\Tests;

use Modx3TestUtils\ModxTestCase;

class ExampleTest extends ModxTestCase
{
    public function testModxMockIsAvailable(): void
    {
        $this->assertNotNull($this->modx);
    }

    public function testGetOptionReturnsConfiguredValue(): void
    {
        $this->modxOptions['test_key'] = 'test_value';
        $this->setUpModxMock();

        $this->assertSame('test_value', $this->modx->getOption('test_key'));
    }

    public function testGetOptionReturnsDefault(): void
    {
        $this->assertSame('fallback', $this->modx->getOption('missing_key', null, 'fallback'));
    }
}

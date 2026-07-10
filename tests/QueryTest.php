<?php

namespace RouterOS\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Exceptions\QueryException;
use RouterOS\Sdk\Query;

final class QueryTest extends TestCase
{
    public function testEqualBuildsAttributeWord(): void
    {
        $query = new Query('/ip/address/add');
        $query->equal('address', '192.168.1.1/24')->equal('interface', 'ether1');

        $this->assertSame('/ip/address/add', $query->getEndpoint());
        $this->assertSame(['=address=192.168.1.1/24', '=interface=ether1'], $query->toWords());
    }

    public function testWhereBuildsFilterWord(): void
    {
        $query = new Query('/interface/print');
        $query->where('disabled', 'false');

        $this->assertSame(['?disabled=false'], $query->toWords());
    }

    public function testWhereWithExplicitOperator(): void
    {
        $query = new Query('/interface/print');
        $query->where('mtu', '>', '1000');

        $this->assertSame(['?>mtu=1000'], $query->toWords());
    }

    public function testInvalidOperatorThrows(): void
    {
        $query = new Query('/interface/print');

        $this->expectException(QueryException::class);
        $query->where('mtu', '~', '1000');
    }

    public function testTagIsAppendedLast(): void
    {
        $query = new Query('/interface/print');
        $query->tag('7')->where('disabled', 'false');

        $this->assertSame(['?disabled=false', '.tag=7'], $query->toWords());
    }

    public function testOperationsWord(): void
    {
        $query = new Query('/interface/print');
        $query->where('running', 'true')->operations('or');

        $this->assertSame(['?running=true', '?#or'], $query->toWords());
    }

    public function testBooleanValueIsStringified(): void
    {
        $query = new Query('/ip/firewall/filter/set');
        $query->equal('disabled', true);

        $this->assertSame(['=disabled=true'], $query->toWords());
    }

    public function testEmptyEndpointThrows(): void
    {
        $this->expectException(QueryException::class);
        new Query('');
    }
}

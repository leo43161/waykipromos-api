<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

class RequestBasePathTest extends TestCase
{
    public function testGetPathStripsBasePathCorrectly(): void
    {
        $request = new Request(
            method: 'GET',
            uri: '/waykipromo/api/salud?foo=bar',
            basePath: '/waykipromo/api'
        );

        $this->assertSame('/salud', $request->getPath());
    }

    public function testGetPathWithoutBasePath(): void
    {
        $request = new Request(
            method: 'GET',
            uri: '/api/v1/ofertas'
        );

        $this->assertSame('/api/v1/ofertas', $request->getPath());
    }
}

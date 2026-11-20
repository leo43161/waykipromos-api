<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Response;
use PHPUnit\Framework\TestCase;

class ResponseCacheTest extends TestCase
{
    public function testWithCacheSetsExpectedHeaders(): void
    {
        $response = Response::ok(['item' => 1])->withCache(1800, 3600);

        $cacheControl = $response->getHeader('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('max-age=1800', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=3600', $cacheControl);

        $etag = $response->getHeader('ETag');
        $this->assertNotNull($etag);
        $this->assertStringStartsWith('W/"', $etag);
    }
}

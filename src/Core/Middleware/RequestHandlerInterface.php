<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;

interface RequestHandlerInterface
{
    /**
     * Handles a request and produces a response.
     */
    public function handle(Request $request): Response;
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Exceptions\HttpException;

/**
 * Para los códigos HTTP que no tienen excepción propia (413, 415, 429).
 */
final class ApiException extends HttpException
{
}

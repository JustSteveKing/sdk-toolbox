<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

/**
 * A 5xx: the API failed to handle a request it may well have understood.
 */
class ServerException extends HttpException {}

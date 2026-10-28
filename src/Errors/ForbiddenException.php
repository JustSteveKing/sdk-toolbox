<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

/**
 * 403 Forbidden: the credentials are valid but don't allow this.
 */
class ForbiddenException extends ClientException {}

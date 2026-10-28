<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

/**
 * 428 Precondition Required (RFC 6585 section 3): the API won't take this
 * change without an If-Match, to protect against lost updates.
 */
class PreconditionRequiredException extends ClientException {}

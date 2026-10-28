<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use RuntimeException;

/**
 * Pagination that would never end: the API pointed at the page just read.
 */
final class PaginationException extends RuntimeException implements SdkException {}

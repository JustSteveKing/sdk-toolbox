<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Testing;

enum RecordMode: string
{
    /**
     * Answer only from the fixture; a missing fixture fails the test.
     * The default, and what CI should run.
     */
    case Replay = 'replay';

    /**
     * Send every request to the real API and write the fixture afresh.
     */
    case Record = 'record';

    /**
     * Replay a fixture that exists, record one that doesn't.
     */
    case Missing = 'missing';

    /**
     * From an environment variable, `replay` when it's unset, so a fixture
     * is only ever re-recorded on purpose:
     *
     *     SDK_FIXTURES=record vendor/bin/pest
     */
    public static function fromEnvironment(string $variable = 'SDK_FIXTURES'): self
    {
        $value = getenv($variable);

        return is_string($value) && $value !== '' ? self::from(strtolower($value)) : self::Replay;
    }
}

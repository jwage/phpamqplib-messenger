<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Tests\Unit;

use Jwage\PhpAmqpLibMessengerBundle\Tests\TestCase;
use Jwage\PhpAmqpLibMessengerBundle\Tests\TestLog;

class TestLogTest extends TestCase
{
    public function testRedactHidesThePasswordInADsn(): void
    {
        self::assertSame(
            'phpamqplib://guest:***@127.0.0.1:5673/%2f/messages',
            TestLog::redact('phpamqplib://guest:guest@127.0.0.1:5673/%2f/messages'),
        );
    }

    public function testRedactAllowsAHashInTheSubject(): void
    {
        $subject = 'phpamqplib://guest:secret@127.0.0.1:5673/%2f/messages#fragment';

        self::assertSame(
            'phpamqplib://guest:***@127.0.0.1:5673/%2f/messages#fragment',
            TestLog::redact($subject),
        );
    }
}

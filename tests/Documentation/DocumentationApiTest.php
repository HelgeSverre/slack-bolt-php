<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Documentation;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Messages\Message;

final class DocumentationApiTest extends TestCase
{
    public function test_documented_factories_and_constructors_are_checked_without_executing_them(): void
    {
        $checks = [
            ['class' => Message::class, 'method' => 'text', 'kind' => 'static'],
            ['class' => Message::class, 'method' => 'not_a_real_factory', 'kind' => 'static'],
            ['class' => Message::class, 'method' => 'fallbackText', 'kind' => 'static'],
            ['class' => Message::class, 'kind' => 'construct'],
        ];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-documentation-api.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($checks, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $errors = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([1, 2, 3], array_column($errors, 'index'));
    }
}

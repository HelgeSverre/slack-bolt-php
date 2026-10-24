<?php

declare(strict_types=1);

namespace App\Demo\Ai;

/** Network chunks may split lines, JSON, or a multibyte character. */
final class SseDecoder
{
    private string $buffer = '';
    private string $data = '';

    /** @param callable(array): void $onEvent */
    public function push(string $chunk, callable $onEvent): void
    {
        $this->buffer .= $chunk;
        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $newline), "\r");
            $this->buffer = substr($this->buffer, $newline + 1);
            if ($line === '') {
                if ($this->data !== '' && trim($this->data) !== '[DONE]') {
                    $event = json_decode($this->data, true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($event) || array_is_list($event)) {
                        throw new \JsonException('Invalid stream event.');
                    }
                    $onEvent($event);
                }
                $this->data = '';
            } elseif (str_starts_with($line, 'data:')) {
                $value = substr($line, 5);
                $this->data .=
                    ($this->data === '' ? '' : "\n") . (str_starts_with($value, ' ') ? substr($value, 1) : $value);
            }
            $this->checkSize();
        }
        $this->checkSize();
    }

    private function checkSize(): void
    {
        if ((strlen($this->buffer) + strlen($this->data)) > 1_048_576) {
            throw new \JsonException('Stream event exceeded its limit.');
        }
    }
}

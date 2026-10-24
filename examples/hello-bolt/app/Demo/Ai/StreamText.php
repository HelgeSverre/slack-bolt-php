<?php

declare(strict_types=1);

namespace App\Demo\Ai;

/** OpenAI citation markers can span deltas; Slack receives source links separately. */
final class StreamText
{
    private bool $inCitation = false;

    public function append(string $delta): string
    {
        $text = '';
        foreach (mb_str_split($delta) as $character) {
            if ($character === "\u{E200}") {
                $this->inCitation = true;
            } elseif ($character === "\u{E201}") {
                $this->inCitation = false;
            } elseif (!$this->inCitation) {
                $text .= $character;
            }
        }
        return $text;
    }
}

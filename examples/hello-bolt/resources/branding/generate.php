<?php

declare(strict_types=1);

// Original vector geometry. Run with PHP and ImageMagick to reproduce every export.
$root = __DIR__;
$colors = ['vermilion' => '#D94728', 'paper' => '#F5F2EB', 'ink' => '#242522'];
$shape = 'M590 200H830L670 440H550V520H615L430 800H190Z';
$svg = static function (string $foreground, ?string $background = null) use ($shape): string {
    $ground = $background === null ? '' : '<path fill="' . $background . '" d="M0 0H1024V1024H0Z"/>';

    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" width="1024" height="1024"><title>Thunder Bolt — notched contact</title>'
        . $ground
        . '<path fill="'
        . $foreground
        . '" d="'
        . $shape
        . '"/></svg>'
    );
};

foreach ([
    'mark' => [$colors['vermilion'], null],
    'mark-ink' => [$colors['ink'], null],
    'mark-paper' => [$colors['paper'], null],
    'avatar' => [$colors['paper'], $colors['vermilion']],
    'avatar-dark' => [$colors['vermilion'], $colors['ink']],
    'avatar-light' => [$colors['vermilion'], $colors['paper']],
] as $name => [$foreground, $background]) {
    file_put_contents($root . '/' . $name . '.svg', $svg($foreground, $background));
}

$render = static function (string $source, string $destination, int $size): void {
    $font = getenv('BRAND_LABEL_FONT')
    ?: (
        is_file('/System/Library/Fonts/Supplemental/Arial.ttf')
            ? '/System/Library/Fonts/Supplemental/Arial.ttf'
            : 'DejaVu-Sans'
    );
    $pipes = [];
    $process = proc_open(
        [
            'magick',
            '-font',
            $font,
            '-background',
            'none',
            $source,
            '-resize',
            $size . 'x' . $size,
            '-set',
            'impeccable:prompt',
            'Origin: original vector geometry authored in generate.php; source '
                . basename($source)
                . '. Rendered with ImageMagick.',
            '-define',
            'png:exclude-chunks=date,time',
            $destination,
        ],
        [STDIN, STDOUT, STDERR],
        $pipes,
    );
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('ImageMagick could not render ' . $destination);
    }
};

$render($root . '/avatar.svg', $root . '/thunder-bolt-dev.png', 1024);
foreach ([24, 32, 48, 128, 512] as $size) {
    $render($root . '/avatar.svg', $root . '/avatar-' . $size . '.png', $size);
}

$board =
    '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="700" viewBox="0 0 1200 700"><rect width="1200" height="700" fill="'
    . $colors['paper']
    . '"/>';
$board .=
    '<g font-family="Arial" fill="'
    . $colors['ink']
    . '"><text x="64" y="66" font-size="24">Thunder Bolt</text><text x="64" y="100" font-size="14">One shape. One accent.</text></g>';
foreach ([
    [64, 150, $colors['paper'], $colors['vermilion']],
    [440, 150, $colors['vermilion'], $colors['paper']],
    [816, 150, $colors['vermilion'], $colors['ink']],
] as [$x, $y, $foreground, $background]) {
    $board .=
        '<g transform="translate('
        . $x
        . ' '
        . $y
        . ') scale(.3125)"><path fill="'
        . $background
        . '" d="M0 0H1024V1024H0Z"/><path fill="'
        . $foreground
        . '" d="'
        . $shape
        . '"/></g>';
}
$board .=
    '<g font-family="Arial" font-size="14" fill="'
    . $colors['ink']
    . '"><text x="64" y="518">Vermilion / #D94728</text><text x="440" y="518">Paper / #F5F2EB</text><text x="816" y="518">Ink / #242522</text><text x="64" y="620">Actual size</text></g>';
foreach ([24, 32, 48] as $index => $size) {
    $board .=
        '<g transform="translate('
        . (190 + ($index * 96))
        . ' '
        . (612 - ($size / 2))
        . ') scale('
        . ($size / 1024)
        . ')"><path fill="'
        . $colors['vermilion']
        . '" d="M0 0H1024V1024H0Z"/><path fill="'
        . $colors['paper']
        . '" d="'
        . $shape
        . '"/></g>';
}
$board .= '</svg>';
file_put_contents($root . '/brand-sheet.svg', $board);
$render($root . '/brand-sheet.svg', $root . '/brand-sheet.png', 1200);
file_put_contents(
    $root . '/palette.json',
    json_encode($colors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);

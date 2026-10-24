# Thunder Bolt demo branding

This directory holds the minimal identity for the `hello-bolt` Slack demonstration only. The user confirmed that it does not apply to the Thunder Bolt PHP framework.

The identity has one original connected, sharp, notched diagonal contact and one accent. The geometry lives in `generate.php`; do not redraw it from a raster export. The square avatar field is intentional: Slack crops app imagery square.

## Export inventory

| File | Use |
| --- | --- |
| `generate.php` | Source geometry and deterministic export script. |
| `mark.svg`, `mark-ink.svg`, `mark-paper.svg` | Transparent single-contact SVGs for known backgrounds. |
| `avatar.svg`, `avatar-dark.svg`, `avatar-light.svg` | Square Slack-safe SVG avatars. |
| `thunder-bolt-dev.png` | 1024 px development Slack app icon. |
| `avatar-24.png`, `avatar-32.png`, `avatar-48.png`, `avatar-128.png`, `avatar-512.png` | Square Slack-safe raster avatars. |
| `brand-sheet.svg`, `brand-sheet.png` | Reference sheet showing color contexts and actual-size samples. Labels are metadata, not a wordmark. |
| `palette.json` | The canonical vermilion, paper, and ink values. |

## Reproduce exports

Requires PHP and ImageMagick's `magick` command. From this directory:

```sh
php generate.php
```

The script regenerates every SVG, PNG, reference sheet, and `palette.json`. To control the font used when ImageMagick rasterizes the reference-sheet metadata, set `BRAND_LABEL_FONT` to a local font path:

```sh
BRAND_LABEL_FONT="/path/to/font.ttf" php generate.php
```

No brand typeface has been chosen. The default label font is only a portable export fallback, and the sheet's labels must not be reused as a wordmark.

## Guardrails

- Keep the three palette names and values: vermilion, paper, and ink.
- Use the avatar variants for Slack so the contact stays intact inside Slack's square crop.
- Use ink on paper for small text. Vermilion fails the 4.5:1 normal-text contrast target against both paper and ink.
- Do not add gradients, glow, eyes, a generic AI mascot, or an additional accent.

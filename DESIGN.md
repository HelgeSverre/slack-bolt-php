---
name: Thunder Bolt
description: Minimal identity for the hello-bolt Slack demonstration.
colors:
  vermilion: "#D94728"
  paper: "#F5F2EB"
  ink: "#242522"
---

# Design System: Thunder Bolt

## Overview

**Creative North Star: "The Notched Contact"**

This is a deliberately small identity for the hello-bolt Slack demonstration: one accent, one connected sharp diagonal form, and plain supporting language. It gives the app a recognizable square-crop avatar without turning a developer demo into a mascot brand.

Its scope is the demo identity only. Whether it becomes the framework's permanent identity remains undecided.

**Key Characteristics:**

- One original notched diagonal contact, drawn as a single SVG path.
- One vermilion accent held against paper and ink.
- Sparse, functional labelling; no ornamental AI imagery.

## Colors

Vermilion makes the contact unmistakable; paper and ink carry the quiet, high-contrast supporting field.

### Primary

- **Vermilion:** Use for the square avatar field or the contact itself at graphic scale. It is not approved for small text: it reaches 3.85:1 on paper and 3.58:1 on ink, below the 4.5:1 normal-text threshold.

### Neutral

- **Paper:** Use as the light field and as the reversed contact inside a vermilion avatar.
- **Ink:** Use for text and the dark field. Ink on paper reaches 13.78:1 and is the standard text pairing.

**The One-Accent Rule.** Vermilion is the only accent; do not add supporting colors to decorate the mark.

## Typography

No brand typeface is selected. Brand-sheet labels are explanatory metadata, not a wordmark. The export script accepts `BRAND_LABEL_FONT` only to make those rasterized labels reproducible in a chosen environment.

## Shapes

The identity is one connected, sharp, notched diagonal contact. Its source is the `d` path in `examples/hello-bolt/resources/branding/generate.php`; use the generated SVG variants rather than redrawing or rounding it.

Slack images remain square: use an avatar export with its full square field so Slack cropping never cuts the contact. Do not add gradients, glow, eyes, faces, a character, or other mascot treatment.

## Do's and Don'ts

### Do:

- **Do** use the generated square avatar exports for Slack app imagery.
- **Do** reproduce assets from the PHP source and preserve the three named color tokens.
- **Do** use ink on paper for small readable text.

### Don't:

- **Don't** treat the brand-sheet label as a wordmark or infer a brand typeface from it.
- **Don't** use vermilion for small text or add another accent color.
- **Don't** turn the contact into generic AI imagery, a mascot, or an illustrated character.

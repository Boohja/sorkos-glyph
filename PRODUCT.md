# Product

## Register

product

## Users

Glyph is for developers working on PHP, CMS, static, and small web projects that do not need or want npm, webpack, Composer, or a larger icon-management workflow. They usually need a handful of SVG icons, a clean sprite, and a way to keep small reusable sprite collections when signed in through Sorkos.

## Product Purpose

Glyph creates clean SVG sprites without a build step. It accepts individual SVG files, sanitizes and normalizes them on the server, converts colors to `currentColor`, and produces a deterministic downloadable sprite. Success means a guest can finish a sprite quickly in one browser session, and an authenticated Sorkos user can return later to manage saved sprite projects.

## Brand Personality

Focused, calm, precise. Glyph should feel like a small developer utility that does one task well: quiet enough for repeated use, pleasant enough to not feel bare, and exact enough that developers trust its output.

## Anti-references

Glyph should not become an icon library, asset manager, design tool, SVG editor, marketplace, CDN, or IcoMoon-style advanced workflow. It should not feel like a SaaS dashboard, a marketing brand site, a decorative design playground, or a complex product trying to justify itself. Anything beyond a simple tool for a simple task is the anti-reference.

## Design Principles

- Keep the task path obvious: upload SVGs, review the cleaned icons, adjust IDs, download the sprite.
- Prefer earned familiarity over novelty; standard controls, predictable navigation, and clear states beat custom affordances.
- Make processing visible without making it alarming; cleanup notes should help developers understand what changed.
- Preserve focus by keeping secondary features quiet and avoiding product sprawl.
- Let the saved-project workflow feel durable, but not heavier than the guest workflow.

## Accessibility & Inclusion

Target WCAG AA for contrast and keyboard operation. All buttons and inputs must be reachable by keyboard, focus states must remain visible, file upload needs a normal input fallback, status messages should use `aria-live`, destructive actions require confirmation, and motion should be restrained with reduced-motion support when animation is added.

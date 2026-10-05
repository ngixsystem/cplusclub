# Interface design

Updated 2026-10-05.

The shared stylesheet uses one set of semantic color tokens for light and dark themes. Dark background remains #242834; the primary action color remains #b6ff00. Light mode retains yellow primary actions.

- Shell: 64px header, 232px sidebar, shared content alignment.
- Navigation: neutral active surface, narrow accent marker, muted secondary labels.
- Surfaces: 8px card radius, 6px field/button radius, one-pixel borders, no gradients or decorative shadows on desktop.
- Metrics: tabular numerals, compact cards, 16px grid gaps.
- Records: integrated toolbar and full-width table, subtle row hover, descriptive section names and quieter status badges.
- Mobile: collapsible sidebar, two-column metrics, single-column forms, contained table scrolling.
- Keyboard focus and reduced-motion preference remain supported.

Validation: Docker TypeScript check and Vite build passed. Authentication and application data are unchanged.

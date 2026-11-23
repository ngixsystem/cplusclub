import type { Page } from '@playwright/test';

/** Elements that stick out past the right edge of the viewport (empty when the page fits). */
export async function overflowing(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    if (document.documentElement.scrollWidth <= window.innerWidth) return [];
    const width = window.innerWidth;
    const found: string[] = [];
    for (const el of Array.from(document.querySelectorAll<HTMLElement>('body *'))) {
      const box = el.getBoundingClientRect();
      if (box.width === 0 || box.right <= width + 1) continue;
      let clipped = false;
      for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
        const o = getComputedStyle(p).overflowX;
        if ((o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') && p.getBoundingClientRect().right <= width + 1) { clipped = true; break; }
      }
      if (clipped) continue;
      const cls = typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).join('.') : '';
      found.push(`${el.tagName.toLowerCase()}${cls} right=${Math.round(box.right)} text=${(el.textContent || '').trim().slice(0, 40)}`);
      if (found.length >= 8) break;
    }
    return found.length ? found : [`scrollWidth=${document.documentElement.scrollWidth} > ${width}`];
  });
}

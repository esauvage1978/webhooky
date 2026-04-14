// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  site: 'https://webhooky.fr',
  integrations: [
    sitemap({
      changefreq: 'weekly',
      priority: 0.65,
      serialize(item) {
        const u = item.url.replace(/\/$/, '') || item.url;
        if (item.url === 'https://webhooky.fr/' || u === 'https://webhooky.fr') {
          item.priority = 1.0;
          item.changefreq = 'daily';
          return item;
        }
        if (
          u.endsWith('/tarifs') ||
          u.endsWith('/contact')
        ) {
          item.priority = 0.85;
          item.changefreq = 'weekly';
          return item;
        }
        if (
          u.endsWith('/mentions-legales') ||
          u.endsWith('/politique-confidentialite') ||
          u.endsWith('/cgu') ||
          u.endsWith('/cgv')
        ) {
          item.priority = 0.45;
          item.changefreq = 'monthly';
          return item;
        }
        return item;
      },
    }),
  ],
});

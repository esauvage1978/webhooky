import { expect, test } from '@playwright/test';

test.describe('parcours critiques vitrine', () => {
  test('accueil → création de compte', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/webhooks/i);
    const cta = page.getByRole('link', { name: /Créer un compte/i }).first();
    await expect(cta).toHaveAttribute('href', /webhooky\.builders\/inscription/);
  });

  test('accueil → tarifs', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('navigation', { name: 'Navigation principale' }).getByRole('link', { name: 'Tarifs' }).click();
    await expect(page).toHaveURL(/\/tarifs\/?$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Tarifs/i);
  });

  test('page 404 utile', async ({ page }) => {
    const res = await page.goto('/page-qui-nexiste-pas/');
    expect(res?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/introuvable/i);
    await expect(page.getByRole('link', { name: 'Accueil', exact: true })).toBeVisible();
  });

  test('liens juridiques', async ({ page }) => {
    await page.goto('/mentions-legales/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Mentions légales/i);
    await page.goto('/politique-confidentialite/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/confidentialité/i);
  });

  test('cookies : refus et réouverture des préférences', async ({ page }) => {
    await page.goto('/');
    const banner = page.getByRole('region', { name: /cookies/i });
    await expect(banner).toBeVisible();
    await banner.getByRole('button', { name: /^Refuser$/i }).click();
    await expect(banner).toBeHidden();
    await page.getByRole('button', { name: /Préférences cookies/i }).click();
    await expect(banner).toBeVisible();
  });

  test('menu Produit desktop', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/');
    const trigger = page.getByRole('button', { name: 'Produit' });
    await trigger.click();
    await expect(page.getByRole('link', { name: 'Sécurité' }).first()).toBeVisible();
    await page.getByRole('link', { name: 'Sécurité' }).first().click();
    await expect(page).toHaveURL(/\/securite\/?$/);
  });

  test('documentation → application', async ({ page }) => {
    await page.goto('/documentation/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Documentation/i);
    const register = page.getByRole('link', { name: /Créer un compte|inscription/i }).first();
    await expect(register).toHaveAttribute('href', /webhooky\.builders/);
  });
});

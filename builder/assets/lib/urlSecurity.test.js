import { describe, expect, it } from 'vitest';
import { isSafePublicHref, safeHrefOrNull, safeStripeRedirectUrl } from './urlSecurity.js';

describe('urlSecurity', () => {
  it('rejects protocol-relative URLs', () => {
    expect(isSafePublicHref('//evil.com')).toBe(false);
    expect(safeHrefOrNull('//evil.com')).toBeNull();
  });

  it('allows same-origin paths', () => {
    expect(isSafePublicHref('/facturation')).toBe(true);
  });

  it('allows stripe checkout hosts only for redirects', () => {
    expect(safeStripeRedirectUrl('https://checkout.stripe.com/c/pay/cs_test')).toContain('checkout.stripe.com');
    expect(safeStripeRedirectUrl('https://evil.test/phish')).toBeNull();
    expect(safeStripeRedirectUrl('javascript:alert(1)')).toBeNull();
  });
});

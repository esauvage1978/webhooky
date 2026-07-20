import { describe, expect, it } from 'vitest';
import { MIN_SUBMIT_DELAY_MS, validateContactForm } from './contactValidation';

const base = {
  name: 'Jean Dupont',
  email: 'jean.dupont@example.com',
  company: 'Exemple',
  requestType: 'sales',
  subject: 'Demande',
  message: 'Bonjour, je souhaite une démonstration.',
  privacyAccepted: true,
  websiteHoneypot: '',
  formReadyAt: Date.now() - MIN_SUBMIT_DELAY_MS - 10,
};

describe('validateContactForm', () => {
  it('accepte un message valide', () => {
    const result = validateContactForm(base);
    expect(result.ok).toBe(true);
    expect(result.sanitized?.email).toBe('jean.dupont@example.com');
  });

  it('rejette le honeypot silencieusement', () => {
    const result = validateContactForm({ ...base, websiteHoneypot: 'bot' });
    expect(result.ok).toBe(false);
    expect(result.errors.form).toBe('rejected');
  });

  it('exige le délai minimal', () => {
    const result = validateContactForm({ ...base, formReadyAt: Date.now() });
    expect(result.ok).toBe(false);
    expect(result.errors.form).toMatch(/patienter/i);
  });

  it('valide e-mail et privacy', () => {
    const result = validateContactForm({
      ...base,
      email: 'invalid',
      privacyAccepted: false,
    });
    expect(result.ok).toBe(false);
    expect(result.errors.email).toBeTruthy();
    expect(result.errors.privacyAccepted).toBeTruthy();
  });

  it('neutralise les retours ligne dans l’objet', () => {
    const result = validateContactForm({
      ...base,
      subject: 'Hello\r\nBcc: evil@example.com',
    });
    expect(result.ok).toBe(true);
    expect(result.sanitized?.subject).not.toMatch(/[\r\n]/);
  });
});

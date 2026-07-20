export const CONTACT_REQUEST_TYPES = [
  { value: 'sales', label: 'Question commerciale' },
  { value: 'support', label: 'Assistance' },
  { value: 'partnership', label: 'Partenariat' },
  { value: 'integration', label: 'Demande d’intégration' },
  { value: 'security', label: 'Sécurité' },
  { value: 'other', label: 'Autre' },
] as const;

export type ContactRequestType = (typeof CONTACT_REQUEST_TYPES)[number]['value'];

export interface ContactFormInput {
  name: string;
  email: string;
  company: string;
  requestType: string;
  subject: string;
  message: string;
  privacyAccepted: boolean;
  websiteHoneypot: string;
  /** Timestamp ms when the form was rendered / focused. */
  formReadyAt: number;
}

export interface ContactValidationResult {
  ok: boolean;
  errors: Partial<Record<keyof ContactFormInput | 'form', string>>;
  sanitized?: {
    name: string;
    email: string;
    company: string;
    requestType: ContactRequestType;
    subject: string;
    message: string;
  };
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MIN_SUBMIT_DELAY_MS = 2500;

function clean(value: string, max: number): string {
  return value.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '').trim().slice(0, max);
}

/** Empêche l’injection d’en-têtes dans d’éventuels e-mails côté traitement. */
function stripHeaderInjection(value: string): string {
  return value.replace(/[\r\n]+/g, ' ').trim();
}

export function validateContactForm(input: ContactFormInput, now = Date.now()): ContactValidationResult {
  const errors: ContactValidationResult['errors'] = {};

  if (input.websiteHoneypot.trim() !== '') {
    return { ok: false, errors: { form: 'rejected' } };
  }

  if (!Number.isFinite(input.formReadyAt) || now - input.formReadyAt < MIN_SUBMIT_DELAY_MS) {
    errors.form = 'Veuillez patienter quelques secondes avant d’envoyer le message.';
  }

  const name = stripHeaderInjection(clean(input.name, 120));
  const email = stripHeaderInjection(clean(input.email, 180)).toLowerCase();
  const company = stripHeaderInjection(clean(input.company, 180));
  const subject = stripHeaderInjection(clean(input.subject, 200));
  const message = clean(input.message, 8000);
  const requestType = clean(input.requestType, 40);

  if (name.length < 2) errors.name = 'Indiquez votre nom (2 caractères minimum).';
  if (!EMAIL_RE.test(email)) errors.email = 'Indiquez une adresse e-mail valide.';
  if (!CONTACT_REQUEST_TYPES.some((t) => t.value === requestType)) {
    errors.requestType = 'Sélectionnez un type de demande.';
  }
  if (subject.length < 2) errors.subject = 'Indiquez un objet.';
  if (message.length < 10) errors.message = 'Votre message doit contenir au moins 10 caractères.';
  if (!input.privacyAccepted) {
    errors.privacyAccepted = 'Vous devez accepter la politique de confidentialité.';
  }

  if (Object.keys(errors).length > 0) {
    return { ok: false, errors };
  }

  return {
    ok: true,
    errors: {},
    sanitized: {
      name,
      email,
      company,
      requestType: requestType as ContactRequestType,
      subject,
      message,
    },
  };
}

export { MIN_SUBMIT_DELAY_MS };

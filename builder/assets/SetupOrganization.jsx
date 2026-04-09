import { useState } from 'react';

async function parseJson(res) {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

export default function SetupOrganization({
  onSuccess,
  onNavigate,
  embeddedInOnboarding = false,
  useAdminOrganizationEndpoint = false,
}) {
  const [name, setName] = useState('');
  const [error, setError] = useState('');
  const [fields, setFields] = useState({});
  const [pending, setPending] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setFields({});
    setPending(true);
    try {
      const endpoint = useAdminOrganizationEndpoint ? '/api/onboarding/organization' : '/api/organizations/bootstrap';
      const res = await fetch(endpoint, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ name }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        if (data?.fields && typeof data.fields === 'object') setFields(data.fields);
        const msg =
          data?.error && typeof data.error === 'string'
            ? data.error
            : data?.code === 'organization_name_taken'
              ? 'Ce nom d’organisation est déjà utilisé.'
              : 'Création impossible';
        setError(msg);
        return;
      }
      await onSuccess?.();
      if (!embeddedInOnboarding) {
        onNavigate?.('dashboard');
      }
    } catch {
      setError('Erreur réseau');
    } finally {
      setPending(false);
    }
  };

  if (embeddedInOnboarding) {
    return (
      <form className="org-form mailjet-form" onSubmit={(e) => void submit(e)} style={{ maxWidth: '28rem' }}>
        <label className="field">
          <span>Nom de l’organisation</span>
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
            maxLength={180}
            placeholder="Ex. Ma société"
            autoComplete="organization"
          />
        </label>
        {fields.name ? <p className="error">{fields.name}</p> : null}
        {error ? <p className="error">{error}</p> : null}
        <button type="submit" className="btn" disabled={pending}>
          {pending ? 'Création…' : 'Créer et continuer'}
        </button>
      </form>
    );
  }

  return (
    <div className="users-shell setup-organization-page org-section">
      <header className="users-hero users-hero--minimal">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-building" aria-hidden />
            <span>Créer votre organisation</span>
          </h1>
          <p className="users-hero-sub muted" style={{ maxWidth: '40rem' }}>
            Votre compte n’est pas encore rattaché à une structure. Définissez le nom de votre organisation pour
            accéder au tableau de bord, à Mailjet et aux webhooks. Ce nom peut être modifié plus tard.
          </p>
        </div>
      </header>
      <div className="content-card">
        <form className="org-form mailjet-form" onSubmit={(e) => void submit(e)} style={{ maxWidth: '28rem' }}>
        <label className="field">
          <span>Nom de l’organisation</span>
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
            maxLength={180}
            placeholder="Ex. Ma société"
            autoComplete="organization"
          />
        </label>
        {fields.name ? <p className="error">{fields.name}</p> : null}
        {error ? <p className="error">{error}</p> : null}
        <button type="submit" className="btn" disabled={pending}>
          {pending ? 'Création…' : 'Créer et continuer'}
        </button>
      </form>
      </div>
    </div>
  );
}

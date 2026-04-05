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

export default function SetupOrganization({ onSuccess, onNavigate }) {
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
      const res = await fetch('/api/organizations/bootstrap', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ name }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        if (data?.fields && typeof data.fields === 'object') setFields(data.fields);
        setError(data?.error && typeof data.error === 'string' ? data.error : 'Création impossible');
        return;
      }
      await onSuccess?.();
      onNavigate?.('dashboard');
    } catch {
      setError('Erreur réseau');
    } finally {
      setPending(false);
    }
  };

  return (
    <section className="org-section">
      <h2>Créer votre organisation</h2>
      <p className="muted" style={{ maxWidth: '40rem' }}>
        Votre compte n’est pas encore rattaché à une structure. Définissez le nom de votre organisation pour
        accéder au tableau de bord, à Mailjet et aux webhooks. Ce nom peut être modifié plus tard.
      </p>
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
    </section>
  );
}

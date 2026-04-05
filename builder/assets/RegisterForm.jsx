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

export default function RegisterForm() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [fields, setFields] = useState({});
  const [pending, setPending] = useState(false);
  const [done, setDone] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setFields({});
    setPending(true);
    try {
      const res = await fetch('/api/register', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, password }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        if (data?.fields && typeof data.fields === 'object') setFields(data.fields);
        setError(data?.error && typeof data.error === 'string' ? data.error : 'Inscription impossible');
        return;
      }
      setDone(true);
    } catch {
      setError('Erreur réseau');
    } finally {
      setPending(false);
    }
  };

  if (done) {
    return (
      <div className="login-form">
        <p className="login-success">
          Inscription enregistrée. Un e-mail de confirmation a été envoyé à <strong>{email}</strong>. Ouvrez le lien
          pour activer votre compte avant de vous connecter.
        </p>
        <p>
          <a href="/" className="login-inline-link">
            Retour à la connexion
          </a>
        </p>
      </div>
    );
  }

  return (
    <form className="login-form" onSubmit={(e) => void submit(e)}>
      <label className="field">
        <span>E-mail</span>
        <input
          type="email"
          name="email"
          autoComplete="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
        {fields.email ? <small className="field-hint error">{fields.email}</small> : null}
      </label>
      <label className="field">
        <span>Mot de passe (8 caractères minimum)</span>
        <input
          type="password"
          name="password"
          autoComplete="new-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
          minLength={8}
        />
        {fields.password ? <small className="field-hint error">{fields.password}</small> : null}
      </label>
      {error ? <p className="error">{error}</p> : null}
      <button type="submit" className="btn" disabled={pending}>
        {pending ? 'Envoi…' : 'Créer mon compte'}
      </button>
      <p className="login-switch">
        Déjà un compte ?{' '}
        <a href="/" className="login-inline-link">
          Connexion
        </a>
      </p>
    </form>
  );
}

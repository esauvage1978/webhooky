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

export default function ForgotPasswordForm() {
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [pending, setPending] = useState(false);
  const [done, setDone] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setPending(true);
    try {
      const res = await fetch('/api/forgot-password', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        setError(data?.error ?? 'Requête impossible');
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
          Si un compte correspond à cette adresse, un e-mail de réinitialisation vient d’être envoyé. Pensez à vérifier
          les courriers indésirables.
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
        <span>E-mail du compte</span>
        <input
          type="email"
          name="email"
          autoComplete="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
      </label>
      {error ? <p className="error">{error}</p> : null}
      <button type="submit" className="btn" disabled={pending}>
        {pending ? 'Envoi…' : 'Envoyer le lien'}
      </button>
      <p className="login-switch">
        <a href="/" className="login-inline-link">
          Retour à la connexion
        </a>
      </p>
    </form>
  );
}

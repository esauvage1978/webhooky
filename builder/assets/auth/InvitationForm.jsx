import { useMemo, useState } from 'react';
import { parseJson } from '../lib/http.js';

export default function InvitationForm() {
  const token = useMemo(() => new URLSearchParams(window.location.search).get('token') ?? '', []);
  const [password, setPassword] = useState('');
  const [password2, setPassword2] = useState('');
  const [error, setError] = useState('');
  const [fields, setFields] = useState({});
  const [pending, setPending] = useState(false);
  const [done, setDone] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setFields({});
    if (password !== password2) {
      setError('Les mots de passe ne correspondent pas.');
      return;
    }
    if (!token) {
      setError('Lien invalide : jeton manquant dans l’URL.');
      return;
    }
    setPending(true);
    try {
      const res = await fetch('/api/accept-invitation', {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ token, password }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        if (data?.fields && typeof data.fields === 'object') setFields(data.fields);
        setError(data?.error && typeof data.error === 'string' ? data.error : 'Activation impossible');
        return;
      }
      setDone(true);
      window.history.replaceState({}, '', '/invitation');
    } catch {
      setError('Erreur réseau');
    } finally {
      setPending(false);
    }
  };

  if (done) {
    return (
      <div className="login-form">
        <p className="login-success">Votre mot de passe est enregistré. Vous pouvez vous connecter.</p>
        <p>
          <a href="/" className="login-inline-link">
            Connexion
          </a>
        </p>
      </div>
    );
  }

  return (
    <form className="login-form" onSubmit={(e) => void submit(e)}>
      {!token ? (
        <p className="error">Lien incomplet. Utilisez le lien reçu par e-mail.</p>
      ) : null}
      <label className="field">
        <span>Choisissez un mot de passe</span>
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
      <label className="field">
        <span>Confirmer le mot de passe</span>
        <input
          type="password"
          name="password2"
          autoComplete="new-password"
          value={password2}
          onChange={(e) => setPassword2(e.target.value)}
          required
          minLength={8}
        />
      </label>
      {error ? <p className="error">{error}</p> : null}
      <button type="submit" className="btn" disabled={pending || !token}>
        {pending ? 'Enregistrement…' : 'Activer mon accès'}
      </button>
      <p className="login-switch">
        <a href="/" className="login-inline-link">
          Connexion
        </a>
      </p>
    </form>
  );
}

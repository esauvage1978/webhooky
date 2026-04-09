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

export default function AccountChangePassword() {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newPassword2, setNewPassword2] = useState('');
  const [error, setError] = useState('');
  const [fields, setFields] = useState({});
  const [pending, setPending] = useState(false);
  const [ok, setOk] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setFields({});
    setOk(false);
    if (newPassword !== newPassword2) {
      setFields({ newPassword2: 'Les mots de passe ne correspondent pas' });
      return;
    }
    setPending(true);
    try {
      const res = await fetch('/api/me/change-password', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ currentPassword, newPassword }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        if (data?.fields && typeof data.fields === 'object') setFields(data.fields);
        setError(data?.error && typeof data.error === 'string' ? data.error : 'Modification impossible');
        return;
      }
      setOk(true);
      setCurrentPassword('');
      setNewPassword('');
      setNewPassword2('');
    } catch {
      setError('Erreur réseau');
    } finally {
      setPending(false);
    }
  };

  return (
    <div className="users-shell account-settings-page">
      <header className="users-hero users-hero--minimal">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-key" aria-hidden />
            <span>Changer mon mot de passe</span>
          </h1>
          <p className="users-hero-sub muted">
            Saisissez votre mot de passe actuel puis choisissez un nouveau mot de passe (au moins 8 caractères).
          </p>
        </div>
      </header>
      <div className="content-card">
        <form className="mailjet-form account-form account-section" onSubmit={(e) => void submit(e)}>
        <label className="field">
          <span>Mot de passe actuel</span>
          <input
            type="password"
            name="currentPassword"
            autoComplete="current-password"
            value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)}
            required
          />
        </label>
        {fields.currentPassword ? <p className="error">{fields.currentPassword}</p> : null}
        <label className="field">
          <span>Nouveau mot de passe</span>
          <input
            type="password"
            name="newPassword"
            autoComplete="new-password"
            value={newPassword}
            onChange={(e) => setNewPassword(e.target.value)}
            required
            minLength={8}
          />
        </label>
        {fields.newPassword ? <p className="error">{fields.newPassword}</p> : null}
        <label className="field">
          <span>Confirmer le nouveau mot de passe</span>
          <input
            type="password"
            name="newPassword2"
            autoComplete="new-password"
            value={newPassword2}
            onChange={(e) => setNewPassword2(e.target.value)}
            required
            minLength={8}
          />
        </label>
        {fields.newPassword2 ? <p className="error">{fields.newPassword2}</p> : null}
        {error ? <p className="error">{error}</p> : null}
        {ok ? (
          <p className="auth-notice ok" role="status">
            Votre mot de passe a été mis à jour.
          </p>
        ) : null}
        <button type="submit" className="btn" disabled={pending}>
          {pending ? 'Enregistrement…' : 'Enregistrer le nouveau mot de passe'}
        </button>
      </form>
      </div>
    </div>
  );
}

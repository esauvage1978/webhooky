import AdminPlatformOptions from './AdminPlatformOptions.jsx';

/**
 * Page dédiée : CRUD options plateforme (admin uniquement — garde côté API).
 */
export default function AdminOptions() {
  return (
    <div className="users-shell admin-supervision-page">
      <header className="users-hero users-hero--minimal">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-sliders" aria-hidden />
            <span>Options plateforme</span>
          </h1>
        </div>
      </header>
      <AdminPlatformOptions />
    </div>
  );
}

import AdminPlatformOptions from './AdminPlatformOptions.jsx';

/**
 * Page dédiée : même gabarit visuel que Intégrations (hub + carte pleine largeur).
 */
export default function AdminOptions() {
  return (
    <div className="users-shell org-section integrations-page">
      <AdminPlatformOptions showHubLayout />
    </div>
  );
}

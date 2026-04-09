import ServiceConnections from './ServiceConnections.jsx';

export default function Integrations({ user }) {
  return (
    <div className="users-shell org-section integrations-page">
      <ServiceConnections user={user} hubTitle="Intégrations" />
    </div>
  );
}

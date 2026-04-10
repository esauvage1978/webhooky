import { memo } from 'react';

/**
 * Message d’erreur accessible (écran lecteur + `role="alert"`).
 */
function ErrorAlert({ children, className = '' }) {
  if (children == null || children === '') return null;
  return (
    <p className={`error ${className}`.trim()} role="alert">
      {children}
    </p>
  );
}

export default memo(ErrorAlert);

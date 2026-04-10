import { createPortal } from 'react-dom';

/**
 * Monte le contenu sur document.body pour que position:fixed des modales
 * reste bien plein écran (ancêtres en transform/overflow ne l’isolent pas).
 */
export default function ModalPortal({ children }) {
  return createPortal(children, document.body);
}

import type { FeatureStatus } from './types';

export const STATUS_LABELS: Record<FeatureStatus, string> = {
  available: 'Disponible',
  beta: 'Bêta',
  planned: 'Prochainement',
};

export const STATUS_HINTS: Record<FeatureStatus, string> = {
  available: 'Fonctionnalité utilisable aujourd’hui dans l’application.',
  beta: 'Disponible mais encore en consolidation ; le comportement peut évoluer.',
  planned: 'Prévu sur la feuille de route ; non disponible aujourd’hui.',
};

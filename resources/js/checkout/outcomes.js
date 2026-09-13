/**
 * What a driver may report back.
 *
 * THEIR OWN MODULE, importing nothing. When these lived in `index.js` the
 * drivers imported them from the file that imports the drivers — a cycle, and
 * at the moment a driver evaluated, every constant was still `undefined`. The
 * symptom was not an error: a refused payment silently reported itself as a
 * cancellation, and a successful one never navigated. A circular import fails
 * quietly and looks like a logic bug.
 */
export const COMPLETED = 'completed';
export const DISMISSED = 'dismissed';
export const REFUSED = 'refused';

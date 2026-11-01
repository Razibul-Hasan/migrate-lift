import { InformativeDocsOptions } from './types.js';

/**
 * @param docs Any amount of docs text, such as from a JSDoc description.
 * @param name Name of the entity the docs text is describing.
 * @param options Additional options to customize informativity checking.
 * @returns Whether the docs include at least one word with new information.
 * @example
 * ```js
 * areDocsInformative("The user id.", "userId"); // false
 * ```
 * @example
 * ```js
 * areDocsInformative("Retrieved user id.", "userId"); // true
 * ```
 */
declare function areDocsInformative(docs: string | string[], name: string | string[], options?: InformativeDocsOptions): boolean;

export { InformativeDocsOptions, areDocsInformative };

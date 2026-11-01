export default index;
/** @type {import('eslint').ESLint.Plugin['rules']} */
export const rules: import("eslint").ESLint.Plugin["rules"];
/**
 * @type {Record<
 *      FlatConfigNames,
 *       import('eslint').Linter.Config
 *     > &
 *     Record<
 *       "examples"|"default-expressions"|"examples-and-default-expressions",
 *       import('eslint').Linter.Config[]
 *     > &
 *     Record<"flat/recommended-mixed", import('eslint').Linter.Config[]>
  } */
export const configs: Record<FlatConfigNames, import("eslint").Linter.Config> & Record<"examples" | "default-expressions" | "examples-and-default-expressions", import("eslint").Linter.Config[]> & Record<"flat/recommended-mixed", import("eslint").Linter.Config[]>;
/**
 * @type {((
 *   cfg?: import('eslint').Linter.Config & {
 *     config?: FlatConfigNames,
 *     mergeSettings?: boolean,
 *     settings?: Partial<import('./iterateJsdoc.js').Settings>,
 *     rules?: {[key in keyof import('./rules.d.ts').Rules]?: import('eslint').Linter.RuleEntry<import('./rules.d.ts').Rules[key]>},
 *     extraRuleDefinitions?: {
 *       forbid?: {
 *         [contextName: string]: {
 *           description?: string,
 *           url?: string,
 *           contexts: (string|{
 *             message: string,
 *             context: string,
 *             comment: string
 *           })[]
 *         }
 *       },
 *       preferTypes?: {
 *         [typeName: string]: {
 *           description: string,
 *           overrideSettings: {
 *             [typeNodeName: string]: {
 *               message: string,
 *               replacement?: false|string,
 *               unifyParentAndChildTypeChecks?: boolean,
 *             }
 *           },
 *           url: string,
 *         }
 *       }
 *     }
 *   }
 * ) => import('eslint').Linter.Config)}
 */
export const jsdoc: ((cfg?: import("eslint").Linter.Config & {
    config?: FlatConfigNames;
    mergeSettings?: boolean;
    settings?: Partial<import("./iterateJsdoc.js").Settings>;
    rules?: { [key in keyof import("./rules.d.ts").Rules]?: import("eslint").Linter.RuleEntry<import("./rules.d.ts").Rules[key]>; };
    extraRuleDefinitions?: {
        forbid?: {
            [contextName: string]: {
                description?: string;
                url?: string;
                contexts: (string | {
                    message: string;
                    context: string;
                    comment: string;
                })[];
            };
        };
        preferTypes?: {
            [typeName: string]: {
                description: string;
                overrideSettings: {
                    [typeNodeName: string]: {
                        message: string;
                        replacement?: false | string;
                        unifyParentAndChildTypeChecks?: boolean;
                    };
                };
                url: string;
            };
        };
    };
}) => import("eslint").Linter.Config);
export { getJsdocProcessorPlugin } from "./getJsdocProcessorPlugin.js";
export type ConfigGroups = "recommended" | "stylistic" | "contents" | "logical" | "requirements";
export type ConfigVariants = "" | "-typescript" | "-typescript-flavor";
export type ErrorLevelVariants = "" | "-error";
export type FlatConfigNames = `flat/${ConfigGroups}${ConfigVariants}${ErrorLevelVariants}` | "flat/recommended-tsdoc" | "flat/recommended-tsdoc-error";
export type JsdocPlugin = import("eslint").ESLint.Plugin & {
    configs: Record<FlatConfigNames, import("eslint").Linter.Config> & Record<"examples" | "default-expressions" | "examples-and-default-expressions", import("eslint").Linter.Config[]> & Record<"flat/recommended-mixed", import("eslint").Linter.Config[]>;
};
/**
 * @typedef {"recommended" | "stylistic" | "contents" | "logical" | "requirements"} ConfigGroups
 * @typedef {"" | "-typescript" | "-typescript-flavor"} ConfigVariants
 * @typedef {"" | "-error"} ErrorLevelVariants
 * @typedef {`flat/${ConfigGroups}${ConfigVariants}${ErrorLevelVariants}` |
 *   "flat/recommended-tsdoc" | "flat/recommended-tsdoc-error"} FlatConfigNames
 * @typedef {import('eslint').ESLint.Plugin & {
 *   configs: Record<
 *      FlatConfigNames,
 *       import('eslint').Linter.Config
 *     > &
 *     Record<
 *       "examples"|"default-expressions"|"examples-and-default-expressions",
 *       import('eslint').Linter.Config[]
 *     > &
 *     Record<"flat/recommended-mixed", import('eslint').Linter.Config[]>
 * }} JsdocPlugin
 */
declare const index: JsdocPlugin;
//# sourceMappingURL=index.d.ts.map
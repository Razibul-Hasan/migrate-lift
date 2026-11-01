export * from "./types.js";
const defaultAliases = {
  a: ["an", "our"]
};
const defaultUselessWords = ["a", "an", "i", "in", "of", "re", "s", "the"];
function areDocsInformative(docs, name, options = {}) {
  const { aliases = defaultAliases, uselessWords = defaultUselessWords } = options;
  const docsWords = new Set(splitTextIntoWords(docs));
  const nameWords = splitTextIntoWords(name);
  for (const nameWord of nameWords) {
    docsWords.delete(nameWord);
  }
  for (const uselessWord of uselessWords) {
    docsWords.delete(uselessWord);
  }
  return !!docsWords.size;
  function normalizeWord(word) {
    const wordLower = word.toLowerCase();
    return aliases[wordLower] ?? wordLower;
  }
  function splitTextIntoWords(text) {
    return (typeof text === "string" ? [text] : text).flatMap((name2) => {
      return name2.replace(/[^\p{L}\p{N}_]+/gu, " ").replace(/(\p{Ll})(\p{Lu})/gu, "$1 $2").trim().split(" ");
    }).flatMap(normalizeWord).filter(Boolean);
  }
}
export {
  areDocsInformative
};

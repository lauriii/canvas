# Refining a guideline

Use this when the page agent built a page with the guideline and the result is off.

Ask for what is missing:

- the ID of the page the agent built;
- the ID of a reference page it should look like;
- what is wrong, in the user's words.

Then:

1. Read both pages with the page structure command in SKILL.md, and the guideline file in `canvas-page-guidelines/`. When the file is missing, read the context item's content instead.
2. Compare the built page with the guideline and the reference page. Name the gap in the guideline that let the agent go wrong, for example:
   - a `use_when` that let the agent pick the wrong pattern;
   - a `look` too vague to tell two patterns apart;
   - a missing rule, such as a pairing or an order the pages follow;
   - a `repeat` range the agent went beyond, or one that is missing;
   - a purpose with no pattern.
3. Tell the user the gap and the change you propose. Change only that part of the guideline.
4. Go through the checklist in [output-format.md](output-format.md) again. Items 10 and 11 check against the reference pages; they do not apply to what the user asked for.
5. Offer to update the context item with the create command in SKILL.md, then to build the page again.

The user's request wins over the reference pages. When they ask for a pattern, rule or item count the pages do not show, add it as asked, using only components and prop values from the page agent's catalog. Never copy content from the built page into the guideline.

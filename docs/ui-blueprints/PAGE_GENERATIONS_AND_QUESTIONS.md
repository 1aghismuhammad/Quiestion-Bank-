# Generations & Question Sets Blueprint (PAGE_GENERATIONS_AND_QUESTIONS.md)

This module handles the display of heavy data tables and final AI outputs (Question Sets).

## 1. Glassmorphism Data Tables (`generations/index.blade.php`, `question-sets/index.blade.php`)
*Standard HTML tables look terrible with Glassmorphism if not styled correctly. Follow these strict rules:*

* **Table Wrapper:**
  * Must be wrapped in a Tier 1 Glass container: `bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-2xl overflow-hidden`.
  * Ensure `overflow-x-auto` is applied for mobile responsiveness.
* **Table Header (`<thead>`):**
  * Do NOT use solid colors. Use `bg-white/30 backdrop-blur-lg border-b border-white/50`.
  * Header text: `text-left text-xs font-semibold text-slate-500 uppercase tracking-wider px-6 py-4`.
* **Table Rows (`<tbody> <tr>`):**
  * Alternate row backgrounds are tricky with glass. Use very subtle opacity: `even:bg-white/10`.
  * **Hover State:** `hover:bg-white/30 transition-colors duration-200`.
  * **Borders:** Only bottom borders between rows: `border-b border-white/20`.
* **Table Cells (`<td>`):**
  * Padding: `px-6 py-4 whitespace-nowrap`.
  * Use Pill badges for statuses (e.g., Completed, Failed).

## 2. Question Set Detail / Review (`question-sets/show.blade.php`)
* **Layout:** Flashcard style review.
* **Question Card:**
  * Container: Tier 1 Glass card.
  * **Question Text:** Prominent, `text-lg font-medium text-slate-800`.
* **Options/Answers:**
  * Rendered as a vertical list below the question.
  * Normal Option: `bg-white/40 border border-white/50 rounded-xl px-4 py-3 mb-2`.
  * **Correct Answer Option:** Highlighted using the vibrant design language. `bg-gradient-to-r from-purple-100 to-pink-100 border-purple-300 shadow-sm relative`. Add a small green checkmark icon.
* **Actions:**
  * Floating action bar at the bottom (Tier 2 Heavy Glass) to "Download Student Version", "Download Teacher Version", or "Publish".
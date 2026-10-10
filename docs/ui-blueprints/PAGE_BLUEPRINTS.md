# Blueprints Module Blueprint (PAGE_BLUEPRINTS.md)

This module handles complex forms, AI parameter tuning, and blueprint imports.

## 1. Blueprint Imports & AI Processing (`blueprint-imports/show.blade.php`)
* **AI Processing State (Crucial):**
  * When status is "processing", display a prominent Tier 2 Highlight Glass card at the top.
  * **Progress Bar:** Use an animated gradient strip (`bg-gradient-to-r from-purple-500 to-pink-500 animate-pulse`).
  * Text should read "AI is analyzing the material..." with a glowing effect.
* **Grounding/Summary Output:**
  * Display the AI's interpretation in a scrollable Tier 1 glass panel. 
  * Use a subtle monospace font for raw data or JSON views if applicable, wrapped in a darker glass (`bg-slate-900/10`).

## 2. Blueprint Builder Form (`blueprints/create.blade.php` & `_form.blade.php`)
* **Form Sections:** 
  * Group related inputs (e.g., "General Settings", "Question Types", "Difficulty") into separate Tier 1 Glass cards. Do not put all inputs on a plain background.
* **Input Fields:**
  * Use `bg-white/50 backdrop-blur-sm border border-white/40 focus:ring-2 focus:ring-purple-400 focus:bg-white/80 rounded-xl px-4 py-2`.
  * Select dropdowns must match this styling.
* **Dynamic Rows (`_row.blade.php`):**
  * If the form has repeatable rows (e.g., adding multiple topics), wrap each row in a subtle `bg-white/20` container with a "Remove" button aligned to the right.

## 3. Blueprint Detail View (`blueprints/show.blade.php`)
* **Action Header:** 
  * Sticky top bar (Tier 1 glass) with primary actions: "Generate Questions" (vibrant button), "Edit Blueprint", "Clone".
* **Blueprint Configuration Display:**
  * Present the settings as a grid of key-value pairs inside a glass card.
  * Keys in `text-slate-500 text-sm`, Values in `text-slate-800 font-semibold text-lg`.
# Core Design System (DESIGN.md)

This document is the absolute source of truth for the visual design language of the AI Question Bank platform. Do not deviate from these Tailwind classes.

## 1. Color Palette (Tokens)
The application uses a soft pastel background with highly vibrant, saturated accents for interactions.

* **Global Background (Mesh/Gradient):** 
  * Class: `bg-gradient-to-br from-[#E3F2FD] via-[#F3E5F5] to-[#FFEBEE]` (Light Blue to Soft Purple to Peach).
  * *Note: The background MUST be fixed so scrolling doesn't break the gradient.*
* **Text Colors:**
  * Primary Headings: `text-slate-800` (Dark Navy/Slate for high contrast).
  * Secondary Text: `text-slate-500`.
* **Accent Colors (Buttons, Progress Bars, Active States):**
  * Primary Gradient: `bg-gradient-to-r from-purple-500 to-pink-500`.
  * Hover Gradient: `bg-gradient-to-r from-purple-600 to-pink-600`.

## 2. Glassmorphism Formulas
Never use plain white or solid gray backgrounds for containers. Use these 3 tiers of glass:

* **Tier 1: Base Glass (Cards, Sidebars, Main Panels)**
  * `bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-2xl`
  * *Use for standard content wrappers.*
* **Tier 2: Highlight Glass (Promos, Floating Widgets, Achievements)**
  * `bg-white/20 backdrop-blur-lg border border-white/50 shadow-glass rounded-3xl`
  * *(Requires adding a custom `shadow-glass` to `tailwind.config.js` like: `0 8px 32px 0 rgba(31, 38, 135, 0.07)`)*
* **Tier 3: Heavy Glass (Modals, Overlays, Dropdowns)**
  * `bg-white/70 backdrop-blur-xl border border-white/60 shadow-2xl rounded-2xl`
  * *High opacity ensures readability over other content.*

## 3. UI Primitives
* **Buttons (Primary):** Pill-shaped. `px-6 py-2 rounded-full text-white bg-gradient-to-r from-purple-500 to-pink-500 font-semibold shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300`.
* **Buttons (Secondary):** `px-6 py-2 rounded-full bg-white/50 text-slate-700 font-medium hover:bg-white/80 transition-all duration-300`.
* **Pill Badges (Categories):** `px-4 py-1.5 rounded-full text-sm font-medium bg-white/60 border border-white/50 text-slate-600 hover:bg-white shadow-sm`.

## 4. Animation & Transitions
* **Hover Lift:** All interactive cards must have `transition-all duration-300 ease-out hover:-translate-y-1 hover:shadow-lg`.
* **Page Load (Framer/Alpine):** Elements should fade in and slide up slightly (`translateY: 10px` to `0`, `opacity: 0` to `1`).

## 5. Z-Index & Stacking Context
* Glassmorphism heavily relies on `backdrop-filter`. This creates a new stacking context.
* **Dropdowns/Modals (`z-50`):** Must NOT be placed inside an `overflow-hidden` glass container, or they will be clipped. Render them via portals or ensure parent containers do not clip overflow.
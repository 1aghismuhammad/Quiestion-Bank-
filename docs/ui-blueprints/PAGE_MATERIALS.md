# Materials Module Blueprint (PAGE_MATERIALS.md)

This defines the UI for the Materials module, focusing on data presentation and upload interactions.

## 1. Materials Index (`materials/index.blade.php` & `archived.blade.php`)
* **Layout:** Grid view is preferred over a table for materials to showcase the glass effect.
* **Material Cards:** 
  * Use Tier 1 Base Glass (`bg-white/40`).
  * **Card Header:** Icon representing file type (PDF, Text, Video) in a vibrant circle, followed by the Material Title.
  * **Card Body:** Meta data (Date uploaded, Size, Status) using muted text `text-slate-500`.
  * **Status Badge:** Pill-shaped badge (e.g., "Ready" = Green/Glass, "Processing" = Yellow/Glass).
  * **Card Footer:** Action buttons (View Profile, Create Blueprint, Delete).
* **Empty State:** A large centered Tier 1 glass box with a muted icon and a prominent "Upload First Material" gradient button.

## 2. Material Upload (`materials/create.blade.php`)
* **Upload Zone (Drag & Drop):**
  * Container: Tier 1 Glass, but with a dashed border `border-2 border-dashed border-purple-300/50`.
  * Hover state: `bg-white/60 border-purple-500` to indicate drag readiness.
  * Icon: Large cloud upload 3D icon or vibrant SVG.

## 3. Material Profile (`materials/profile/show.blade.php`)
* **Layout:** Split view (2 columns on desktop).
* **Left Column (Meta & Stats):**
  * A sticky Tier 1 glass card showing the document summary, word count, reading time, and primary topics.
* **Right Column (Extracted Content):**
  * A stack of glass panels (`_element-list.blade.php`) displaying the chunks/paragraphs of the material.
  * Use `divide-y divide-white/20` inside the container to separate text chunks cleanly without heavy borders.
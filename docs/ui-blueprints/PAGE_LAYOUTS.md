# Master Layouts Blueprint (PAGE_LAYOUTS.md)

This defines the structural skeleton for `resources/views/layouts/app.blade.php`.

## 1. The Canvas (Body)
* **HTML/Body Tags:** Must be `min-h-screen text-slate-800 antialiased`.
* **Background:** The global gradient from `DESIGN.md` must be applied to the `<body>` or a fixed `div` spanning `-z-10 w-full h-full fixed inset-0`.

## 2. Layout Grid Strategy
The application uses a Dashboard-style layout for authenticated users, which adapts based on screen size.

### Desktop (>= 1024px)
* **Structure:** A 3-column flex or CSS Grid layout.
* **Left Column (Sidebar):** Fixed width, approx `w-[280px]`. Sticky positioning so it stays visible while content scrolls.
* **Middle Column (Main Content):** Flexible width, taking up the most space (`flex-1`).
* **Right Column (Widgets/Stats - Optional per page):** Fixed width, approx `w-[320px]`.

### Tablet (768px - 1023px)
* **Structure:** 2-column layout.
* The Right Column (Stats) moves below the Main Content.
* Sidebar remains visible but slightly narrower (`w-[240px]`).

### Mobile (< 768px)
* **Structure:** 1-column layout.
* **Sidebar:** Disappears from the left. Becomes a **Bottom Navigation Bar** fixed to the bottom of the screen with a glass effect (`fixed bottom-0 w-full z-40 bg-white/70 backdrop-blur-lg border-t border-white/50`).
* **Main Content:** Takes full width (`w-full`), with extra padding at the bottom (`pb-20`) to prevent content from hiding behind the bottom navigation.

## 3. Topbar (Mobile/Tablet Only)
Since the sidebar moves to the bottom on mobile, the top needs a small Glass header containing:
* Brand Logo/Name.
* User Avatar (linking to profile).
* Hamburger menu (for settings or secondary links).

## 4. Protected Artifact Guardrails
* When updating `app.blade.php`, **DO NOT** remove `@livewireStyles`, `@livewireScripts`, or any `@stack` or `@yield` directives.
* The layout only wraps the content in the new UI containers; it does not change routing logic.
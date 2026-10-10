# Public, Setup & Dashboard Blueprint (PAGE_PUBLIC_AND_DASHBOARD.md)

This defines the specific UI layout and components for the main entry points of the application.

## 1. Landing Page (`home.blade.php` / `welcome.blade.php`)
* **Layout:** Standard single-column vertical scroll.
* **Hero Section:** 
  * Massive, bold typography (`text-5xl lg:text-7xl font-extrabold`).
  * Floating 3D elements or mockups using the Highlight Glass tier (`bg-white/20 backdrop-blur-lg`).
  * "Get Started" button using the primary purple-to-pink gradient.
* **Cards:** Any feature list should use the Base Glass tier (`bg-white/40`) aligned in a responsive grid.

## 2. Profile Setup (`profile/setup.blade.php`)
* **Layout:** Centered Focus (A single card in the middle of the screen).
* **Container:** A wide, Tier 2 Highlight Glass card (`max-w-2xl mx-auto`).
* **Form UI:** 
  * Inputs must have a semi-transparent background (`bg-white/50 focus:bg-white/80`) with soft borders.
  * Submit button spans full width with the vibrant gradient.

## 3. User Dashboard (`dashboard.blade.php`)
*Matches the exact structure of the provided image reference.*

### Column 1: Navigation Sidebar (Left)
* **Profile Header:** Pill-shaped glass container showing Avatar, Name, and Role (e.g., "Student / User").
* **Menu Items:** Dashboard (Active: gradient pill background), Lesson, Statistics, Favorite, Wallet.
* **Promo Card:** A Tier 2 Glass card at the bottom. Text: "Upgrade Account. Unlock premium features." Include floating abstract shapes (CSS/SVG) and a solid dark purple button.

### Column 2: Main Content (Middle)
* **Header:** Large greeting "Hello [Name]" in `text-slate-800` with subtitle "Good day to learn" in `text-slate-500`.
* **Search Bar:** Pill-shaped, light gray/transparent input placed top right.
* **Category Filters:** Horizontal scrollable row of pill badges (e.g., Multimedia, Graphic Design, Content Creator). Active badge is solid white with drop shadow.
* **Active Courses (Data Iteration):**
  * Horizontal rounded-rectangle glass cards.
  * Each card contains: A 3D/colorful icon, Course Name, Chapter count, and a Pink Progress Bar.

### Column 3: Statistics & Widgets (Right)
* **Header:** "Statistics" with a "Day / Week / Month" toggle switch.
* **Activity Chart:** A placeholder for a bar chart (use rounded bars with blue-to-pink gradient).
* **Widget Grid (Below Chart):**
  * *Progress Card:* Full gradient background (Purple to Blue), showing a circular progress ring (e.g., 50%).
  * *Completion Card:* Small white glass card showing completed numbers.
  * *Achievement Card:* Tier 2 Glass card with a gold medal icon, confetti graphics, and a pink gradient strip at the bottom reading "UNLOCKED".
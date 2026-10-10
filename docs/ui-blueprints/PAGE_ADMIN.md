# Admin Module Blueprint (PAGE_ADMIN.md)

This defines the UI for the Administration area. The admin panel must maintain the same Glassmorphism aesthetic as the user-facing app, but optimized for data density and quick actions.

## 1. Admin Dashboard (`admin/dashboard.blade.php`)
* **Top Overview (Stat Cards):**
  * A grid of 4 cards (e.g., Total Users, Active Subs, Pending Upgrades, System Health).
  * Use Tier 1 Glass (`bg-white/40`).
  * Include a large, bold number (`text-4xl font-extrabold text-slate-800`) and a small sparkline or trend indicator (e.g., "+5% this week" in green text).
* **Recent Activity Feed:**
  * A vertical timeline inside a Glass container.
  * Use subtle connecting lines (`border-l-2 border-white/50`) and small vibrant dots for each event.

## 2. User Management (`admin/users/index.blade.php`, `show.blade.php`)
* **Users Table:**
  * Strictly follow the Glassmorphism Table rules defined in `PAGE_GENERATIONS_AND_QUESTIONS.md`.
  * **Role Badges:** Use distinct pill colors. "Admin" = Purple Glass (`bg-purple-100/50 text-purple-700`), "User" = Blue Glass (`bg-blue-100/50 text-blue-700`).
* **User Profile / Edit (Modal or Page):**
  * If using a modal for quick edits, use Tier 3 Heavy Glass (`bg-white/70 backdrop-blur-xl`) to ensure it pops out from the table behind it.
  * Include a dedicated section (Tier 1 card) for assigning or revoking subscriptions.

## 3. Subscription Upgrades (`admin/subscription-upgrades/index.blade.php`)
* **Approval Queue:**
  * Highlight "Pending" requests. Use an attention-grabbing badge (`bg-amber-100/60 text-amber-700 border border-amber-200/50`).
* **Action Buttons (Crucial for Admin UX):**
  * **Approve:** Vibrant Gradient button (Purple to Pink) or a distinct Green Glass button (`bg-emerald-400/20 text-emerald-700 hover:bg-emerald-400/40`).
  * **Reject:** Muted Danger button (`bg-rose-500/10 text-rose-600 hover:bg-rose-500/20`).
  * *Never place Approve and Reject buttons right next to each other without visual hierarchy to prevent misclicks.*

## 4. Admin Navigation
* Ensure the Admin Sidebar (`admin-nav.blade.php` if separate) has a visual indicator that the user is in "Admin Mode" (e.g., a small "ADMIN" badge next to the logo or a slightly darker sidebar glass tint: `bg-slate-900/5 backdrop-blur-md`).
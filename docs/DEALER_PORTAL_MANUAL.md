# ProSelver Dealer Portal — User Manual

This guide is for dealership staff who use the **Dealer Portal** to track vehicles, book transport, and work with body builders. It reflects the portal as of June 2026.

---

## 1. What the portal does

The Dealer Portal is built around one job, in this order:

1. **Track your own vehicles** — see where every VIN is (premises, BB, storage, transit, on demo).
2. **Reserve** when a customer commits — capture salesperson + buyer.
3. **Book a delivery** with ProSelver (or your own driver) to move the chassis where it needs to go.
4. **Mark sold** when the paperwork is done — the reserved customer carries forward automatically.
5. **Mark as delivered** when the buyer takes the keys — the row leaves the active board but stays in your **Recently delivered** history.

> **Archive is not part of the journey.** Use it only for mistakes, test vehicles, or duplicates. Archived rows are hidden from delivery history. For a normal handover, always use **Mark as delivered**.

Supporting features (body-builder admin, bulk upload, trips, petty cash) are there but aren't the day-to-day path.

You sign in with the same ProSelver account your administrator created for you. What you see in the sidebar depends on your **role** and **permissions**.

> **Two parallel tracks**: every vehicle has a **commercial lifecycle** (available → reserved → sold → archived) and an independent **transport movement** (booked → in transit → at destination). The vehicle card shows both side by side.

---

## 2. Finding your way around

When your company is a dealer, the sidebar header reads **Dealer Portal**. Main sections:

| Section | What it is for |
|---------|----------------|
| **Movements** | Dashboard, **Book a delivery**, bulk upload, **My movements** |
| **Stock** | Full stock ledger and off-site / in-transit view |
| **Trips** | Plan trips for your own drivers; drivers use **My Day** |
| **Reports** | Deliveries report; live wallboard |
| **Resources** | Documents; address book |
| **Body Builders** | Movement requests; linked BBs; request a new BB |
| **Account** | Team, branding, drivers, petty cash |

> OEM and body-builder tenants see different labels (still "Orders / New Order / My Orders" rather than "Movements"). This guide describes the **dealer** sidebar.

**Badges on the sidebar**

- **Movement Requests** — amber number = pending requests from linked body builders waiting for your decision.
- **My movements** — amber number = **direct orders** where a body builder booked ProSelver and you must approve the move as **vehicle owner**.

If you do not see a menu item, your role may not include that permission. Ask your **Dealer Owner** or ProSelver operations to adjust your access.

---

## 3. Roles at a glance

Typical dealer roles (names may show as “Dealer Owner”, “Stock Controller”, etc. on the Team page):

| Role | Usually can |
|------|-------------|
| **Dealer Owner / Principal** | Everything: stock, orders, team, BB links, bulk upload, drivers |
| **Sales Manager** | Bookings, stock, most admin — similar to owner minus some extras |
| **Stock Controller** | Stock ledger, movements, POs; often approves BB owner movements |
| **Sales Person** | Own orders, view stock, address book — no team/BB admin/bulk upload |
| **Dispatcher** | Confirm orders, BB movement approvals; may be limited to one branch |

Group or franchise principals who manage **multiple dealerships** see a **dealership chip strip** on stock, orders, and movement requests to filter by branch or view the whole group.

---

## 4. Dashboard

**Path:** Movements → **Dashboard** (`/customer/dashboard`)

The dashboard is a tablet-friendly **stock console**: eight cards in a 4×2 grid showing counts for your visible dealerships.

**Top row — commercial funnel:**

| Card | Meaning |
|------|---------|
| **At premises** | On your dealership floor |
| **Reserved** | Held for a customer (salesperson + contact captured) |
| **At body builder / fitment** | Parked at a linked body builder |
| **Scheduled for movement** | Transport booked; collection not started yet |

**Bottom row — physical & post-sale:**

| Card | Meaning |
|------|---------|
| **In transit** | On the road with an active ProSelver job |
| **At another storage** | Parked at another yard or storage site |
| **On demo with customer** | Out on demo |
| **Recently sold** | Marked **sold** in the last 30 days and still on your books — hit **Mark as delivered** the moment the buyer takes the keys |
| **Recently delivered** | Marked **delivered** in the last 30 days. Archived from the active board, kept here as your delivery history |

**Tap any card** to open the **full stock ledger** filtered to that bucket. Tap the same card again to clear the filter.

Quick actions at the top:

- **Book a delivery** — if you may submit bookings.
- **View movements** — your movement list.
- **Help** — opens this guide.

Use **Open full stock →** below the list for the complete paginated ledger with extra filters (body builder, salesperson, search).

---

## 5. Stock — three views (read this carefully)

Dealers have **three** stock-related screens. They answer slightly different questions.

### 5.1 All stock (canonical ledger)

**Path:** Stock → **All stock** (`/customer/stock`)

This is the main **“where is my stock?”** table. Each row shows:

- **VIN, Vehicle, Colour, Reg** — identity columns.
- **Where** — the bucket pill plus the concrete location name underneath (which yard, which BB).
- **Status** — Available / Reserved / Sold / Demo.
- **Salesperson** — assigned via reserve or sale.
- **Customer** — the buyer (also showing during reserve, before sale closes).
- **Last movement** — active job number + status, or "No active movement".
- **Actions** — **Book** (deep-links to *Book a delivery* with VIN, pickup, brand, model pre-filled) and **Print delivery note**.

Filters:

- **Bucket chips** (location-based) plus **Scheduled for movement**, **Recently sold**, and **Recently delivered** virtual buckets. The Delivered chip is the only one that crosses the archive boundary — it's the dealer's handover history.
- **Reserved only** one-tap button next to the status dropdown.
- **Body builder** multi-select pills (only shown when at least one BB hosts your stock).
- **Salesperson** multi-select pills.
- **Search** by VIN, registration, model, colour, etc.
- **Dealership chip strip** (for group / franchise principals).

Click any row to open the **vehicle card**.

**Bucket labels**

| Label | Meaning |
|-------|---------|
| At premises | At your dealership |
| Body builder | At a body builder / fitment centre |
| Other storage | Another storage yard |
| In transit | Active transport job |
| On demo | With a customer on demo |
| Delivered to dealer | A transport job ended at a dealer destination (the vehicle arrived at your premises) |
| Scheduled for movement | Job booked, not yet collected |
| **Recently sold** | Sold and still on your books — hit **Mark as delivered** when the buyer takes the keys |
| **Recently delivered** | Handed over in the last 30 days. Archived from the active board, kept for your records |

> **Sold is sold.** When a deal closes, mark the row sold; once the vehicle has left your floor, archive it. There is no separate "customer handover" step in the dealer flow.

### 5.1.1 Adding stock

There are two ways to put vehicles onto the ledger. Both are gated to users with **Manage Dealer Stock**.

#### A. Add a single vehicle (`+ Add vehicle`)

**Path:** Stock → All stock → **+ Add vehicle** (`/customer/stock/create`)

Use this when a single unit needs to land on the books outside the normal DMS-export flow &mdash; most commonly when the OEM shipped a chassis **factory-direct to one of your body builders**, or when a vehicle arrived at a branch / yard rather than your main premises.

Fill in VIN (required), the rest of the identity columns (suffix, variant, description, engine number, colour, registration, brand, model, year), pick a starting location, and save:

- **At my premises** &mdash; the default. Lands on the dealership floor.
- **At a body builder** &mdash; pick the linked BB and its yard. Stock lands in the *body builder* bucket immediately.
- **At another storage / yard** &mdash; pick one of your own non-primary locations.

You can optionally assign a salesperson at the time of creation. Status starts at **Available**.

#### B. Bulk import from your DMS

**Path:** Stock → **Import stock** (`/customer/stock/import`)

Export your inventory out of your dealership management system (Kerridge, Pinnacle, Autoline, Automate &mdash; anything that produces an `.xlsx`, `.xls` or `.csv` file) and drop it in. Four steps, all on one page:

1. **Upload** &mdash; pick the file (5 MB max).
2. **Starting location** &mdash; pick the bucket new rows should land in (Premises / a specific BB / your own yard). Defaults to *Premises*. Existing rows (matched on VIN) keep their current location regardless.
3. **Confirm mapping** &mdash; we auto-detect VIN, Suffix, Variant, Description, Engine number, Colour, Registration, Make/Brand, Model and Model year. You can override any column.
4. **Commit** &mdash; preview the rows (errors in red, warnings in amber, ready rows in green) and click **Commit import**.

**Re-uploading is safe.** Vehicles are matched on (your company, VIN). Existing rows have their attributes refreshed; their location and sale state are untouched.

**Header aliases we recognise** &mdash; column names like Chassis No, Reg No, License Plate, Engine No, Make, Manufacturer, Year Model and similar are all picked up automatically. Need a starting template? The import page has a **Download sample CSV template** link.

> Whole batch shipped factory-direct to a fitter? Set the import's starting location to that body builder and the entire upload lands in the BB bucket in one pass.

> Don't confuse this with **Bulk upload movements** (under Movements) &mdash; that one generates transport jobs, not stock rows.

### 5.2 Off-site & in transit (job-based view)

**Path:** Stock → **Off-site & in transit** (`/customer/stock/at-body-builder`)

This view is built from **transport jobs**, not only the ledger. It shows vehicles you still own that are:

- at a body builder,
- at other storage, or
- actively on the road.

**Archived** vehicles drop off this list. Use **Book return** (where shown) to start an order with pickup/delivery pre-filled.

For counts that match the ledger (e.g. “how many at BB?”), prefer **All stock** → **Body builder** bucket.

### 5.3 Dashboard cards

Same buckets as the ledger, optimised for quick counts on a tablet. Cards link straight into **All stock** with the right filter.

---

## 6. Vehicle card (single stock unit)

**Path:** click any row on **All stock** → `/customer/stock/{id}`

The card has four parts:

1. **Vehicle details** — VIN, make, model, registration, colour, dealership.
2. **Where** — the bucket and (if known) the specific location name.
3. **Lifecycle timeline** — Available → Reserved → Sold → Delivered, each step showing the date and person.
4. **Transport movement** — the active ProSelver job (number, status, pickup → delivery, scheduled date) or "No active transport job".

The lifecycle and the transport movement run **independently** — a vehicle can be sold while still in transit, or in transit before it's reserved. Both are visible at a glance. **Mark as delivered** is the happy-path exit from the active board; **Archive** is the escape hatch for mistakes / test vehicles only.

A **Fitment chain** panel below the timeline tracks one or more body-builder stops as ordered steps (dropside → crane, fridge body → fridge unit, etc.). Each step has its own fitment type, notes, internal job number and independent **Share with BB** toggle so you can disclose end-customer details to one fitter and keep them confidential from the next. See *Fitment chain* below for the full workflow.

### Actions available

| Action | What it does |
|--------|----------------|
| **Book delivery** | Opens *Book a delivery* pre-filled with this VIN, pickup location, brand, model |
| **Reserve** | Holds the vehicle for a customer — captures salesperson + customer name (phone/email optional). Status becomes *Reserved*; `reserved_at` is stamped |
| **Edit reserve / Clear reserve** | Update or release the reserve at any time |
| **Mark sold (from reserve)** | Reserved customer carries forward; just confirm and stamp `sold_at` |
| **Mark as sold** | If not previously reserved — captures salesperson + customer fresh |
| **Send out on demo** | Customer details and due-back date; location becomes *On demo* |
| **Return from demo** | Brings the unit back from demo |
| **Reverse sale** | Undo a sale while the row is still on the active ledger (chassis swaps, spec changes, finance fall-through) |
| **Mark as delivered** | **Happy-path close.** Stamps `delivered_at`, archives the row, lands it in *Recently delivered*. Only available once the row is **Sold** |
| **Archive (mistake / test)** | **Escape hatch.** For mistakes, test vehicles, or duplicates. Hidden from delivery history. For a normal handover use **Mark as delivered** instead |
| **Print delivery note** | 4-page handover pack: sale cover + Customer Copy POD + blank backside + Dealer Copy POD. Each POD page captures odometer, fuel level (1–10), condition checklist (panels, glass, lights, interior, keys, spare wheel/jack/tools, manual, dash lights, tyres, fuel cap, plates), damage & missing items, and dual signatures — same shape as the ProSelver/OEM pack with the collection note removed |

Share salesperson and end-customer details with each body builder only when you intend them to see that information on their yard app. Sharing is per-leg in the fitment chain — see below.

## 5b. Fitment chain (multi-BB build process)

A single chassis often passes through **several body builders** in sequence — a fridge body supplier then a fridge unit supplier, or a dropside builder then a crane installer. The **Fitment chain** panel on the vehicle card lets you track each stop independently, so notes, internal job numbers, and sharing decisions don't leak between fitters.

### Per-step fields

| Field | Meaning |
|-------|---------|
| Body builder | The fitter for this step (must be linked to your dealership) |
| Fitment type | Short label (Dropside body, Crane mount, Fridge unit, ...) |
| Notes | Full spec for this leg — size, colour, accessories, dates |
| Share with BB | Independent toggle — ON means this fitter sees the shared details |
| Shared salesperson | Sent through only when Share is ON |
| Shared end customer | Sent through only when Share is ON |
| Internal job number | Written by the BB on their yard tablet, kept per leg |

### Step states

- **Planned** — queued; nothing has happened yet. Editable + deletable.
- **In progress** — vehicle is currently with this fitter (stamps `started_at`). Only one leg is ever in progress at a time.
- **Completed** — fitter is done (stamps `completed_at`). Visible, not editable.
- **Cancelled** — the step won't happen; stays on the timeline for the audit trail.

### Workflow

1. Vehicle card → **+ Add fitment step**. Pick the BB, label the fitment type, fill in the build notes.
2. Decide whether to **Share these details with this body builder**. If ON, also fill the salesperson + end customer you want them to see.
3. Save — the step lands as **Planned** at the end of the chain.
4. Repeat for the next fitter (a second BB, etc.). Each leg is independent.
5. **Start** a step when the vehicle physically arrives at that BB. If another leg is still active, it auto-completes — only one leg can be in progress at a time.
6. **Complete** a step when the BB is done; the next planned step becomes the obvious next click.

### What the BB sees

The BB's yard portal reads **only their own active leg**. If sharing is OFF, they see the chassis + their internal job number but nothing else. If sharing is ON, they see the fitment type, salesperson and end customer you chose. Other BBs on the chain never see another BB's notes.

---

## 7. Reserve workflow

**Reserve** is the step between "on the floor" and "sold". It captures *who is buying* and *who is selling to them* before the deal closes — so the unit is held off the available list and any salesperson on the team can see it's spoken for.

### When to reserve

- A customer has paid a deposit or signed.
- You're holding a specific chassis for a fitment that's already been scoped.
- The vehicle is allocated to a deal even though paperwork isn't final yet.

### What gets captured

- **Salesperson** (optional but recommended)
- **Customer name** (required)
- Customer **phone** and **email** (optional)
- **Date stamp** (`reserved_at`) — survives through to sold for the timeline

### Reserve → Sold flow

1. On the vehicle card click **Reserve**, enter salesperson + customer, save.
2. The card shows a **Reserved** panel with the customer details and timestamp; status becomes *Reserved*.
3. When the deal closes, click **Mark sold (from reserve)** — the form is already pre-filled, just confirm.
4. If anything changes before the close, use **Edit reserve** or **Clear reserve**.

### Finding reserved units

- Dashboard **Reserved** card — tap to filter *All stock*.
- All stock → **Reserved only** button next to the status dropdown.
- Salesperson filter pills — find every reservation by a specific rep.

---

## 8. Movements and bookings

### 8.1 Book a delivery

**Path:** Movements → **Book a delivery** (`/customer/orders/create`)

You choose:

- **Pickup and delivery** (from your address book or search).
- **Vehicle** (VIN / details).
- **Executor:** ProSelver driver, your own driver, a 3rd-party transporter (competing carrier or owner-operator), or self-collect.

Most dealers use **ProSelver** for long-distance transport. Your own driver and trip planner are for internal fleet workflows.

**Faster route — book from the vehicle:** open the row in *All stock* (or click the row's **Book** action) and the form opens with VIN, pickup location, brand, and model pre-filled. Same shortcut works from **Off-site & in transit** via **Book return**.

### 8.2 My movements

**Path:** Movements → **My movements** (`/customer/orders`)

Lists movements where your dealership is the **booking customer** and movements where you are only the **vehicle owner** (body-builder direct orders).

**Filters:** search, status, archived, dealership (group users), and **owner pending only** when approvals are waiting.

**Amber banner:** "Movements awaiting your approval" = **direct orders** (see section 9). Use **Show only these** to filter the list.

Open a movement to confirm readiness, mark urgent, change executor, assign your driver, upload documents or POs, and approve or reject **owner** movements.

When you are only the vehicle owner, **pricing is hidden** — you are approving that the vehicle may move, not paying the transport.

### 8.3 Bulk upload

**Path:** Movements → **Bulk Upload** — for principals and similar roles. Upload a spreadsheet of multiple movements at once.

---

## 9. Body builders — two different flows

This is the most common source of confusion. There are **two** ways a body builder moves your stock.

### 9.1 Movement request (BB asks you)

| | |
|---|---|
| **Who books transport?** | **You** (the dealer), after you approve |
| **Who pays ProSelver?** | You (or you use your own driver) |
| **Where to act** | **Body Builders → Movement Requests** |
| **What happens** | BB raises next-fitment or collection request → you approve or reject → approving creates a job in **your** queue |

Check the sidebar badge on **Movement Requests** for pending items.

### 9.2 Direct order (BB books ProSelver)

| | |
|---|---|
| **Who books transport?** | **The body builder** with ProSelver |
| **Who pays ProSelver?** | The body builder |
| **Your role** | **Vehicle owner** — approve or reject that **your** VIN may move |
| **Where to act** | **My movements** (not Movement Requests) |
| **Badge** | Amber count on **My movements** in the sidebar |

You do **not** see commercial pricing on these owner-only views.

**Summary**

- **Movement request** = “BB asks you to arrange the move.”
- **Direct order** = “BB booked ProSelver; you only approve the move.”

### 9.3 Linking body builders

**Path:** Body Builders → **Linked Body Builders**

Dealer owners link authorised body builders so they can raise requests or direct orders against your stock.

**Request a BB** sends a request to ProSelver operations if a builder is not yet on the platform.

---

## 10. Trips (own drivers)

**Path:** Trips → **Trip Planner**

If your dealership uses **internal drivers** (not OEM-only accounts), planners attach confirmed jobs to trips.

**My Day** is for drivers — their assigned collections and deliveries for the day.

Jobs still waiting for **owner approval** cannot be attached to a trip until approved.

---

## 11. Reports and resources

| Item | Path | Purpose |
|------|------|---------|
| **Deliveries** | Reports → Deliveries | Delivery history and metrics |
| **Live Display** | Reports → Live Display | Wallboard of active movements (opens in new tab) |
| **Documents** | Resources → Documents | POs and files across orders |
| **Address Book** | Resources → Address Book | Pickup/delivery locations for bookings |

---

## 12. Account settings

| Item | Who typically uses it |
|------|------------------------|
| **Team** | Dealer Owner — add users, assign roles |
| **Branding** | Logo/details on sale delivery notes |
| **Drivers** | Internal driver pool (dealer only) |
| **Petty cash** | Own-driver expense tracking |

**Profile** (user menu) — your name, password, and personal settings.

---

## 13. Common tasks — quick reference

| I want to… | Go to… |
|------------|--------|
| See everything on my books | Stock → **All stock** |
| Reserve a vehicle for a customer | Vehicle card → **Reserve** |
| See only reserved units | Dashboard **Reserved** card or All stock → **Reserved only** |
| See vehicles sold in the last 30 days | Dashboard **Recently sold** card |
| See only vehicles at a body builder | Dashboard card or All stock → **Body builder** |
| See what is on the road right now | **In transit** bucket or Off-site & in transit |
| Book a delivery for a specific VIN | All stock row → **Book**, or vehicle card → **Book delivery** |
| Book ProSelver to move a vehicle | Movements → **Book a delivery** |
| Approve a BB's "please move this" request | Body Builders → **Movement Requests** |
| Approve a BB's ProSelver booking on my VIN | Movements → **My movements** (owner pending) |
| Mark a vehicle sold (from reserve) | Vehicle card → **Mark sold (from reserve)** |
| Mark a vehicle sold (no reserve) | Vehicle card → **Mark as sold** |
| Close a sale once the buyer has the keys | Vehicle card → **Mark as delivered** (archives the row but keeps it in *Recently delivered*) |
| Remove a mistake / test row | Vehicle card → **Archive (mistake / test)** — hidden from delivery history |
| Undo a sale | Vehicle card → **Reverse sale** (while the row is still on the active ledger) |
| Import new stock | Stock → **Import stock** |
| Add a delivery address | Resources → **Address Book** |
| Upload a PO | Open the movement → documents section |

---

## 14. Glossary

| Term | Meaning |
|------|---------|
| **Stock ledger** | Your register of vehicles on the books (`dealer_stock`) |
| **Bucket** | Where the system thinks the vehicle is (premises, BB, in transit, etc.) |
| **Status** | Commercial state: Available, Reserved, Sold, Demo, Archived |
| **Reserve** | Hold for a customer — assigns salesperson + buyer before sale |
| **Movement / order / job** | A transport booking in ProSelver |
| **Recently sold** | Sold and still on your books — handover not yet captured |
| **Mark as delivered** | Happy-path close: stamps `delivered_at`, archives the row, lands it in *Recently delivered* |
| **Recently delivered** | Delivered in the last 30 days. Archived from the active board, kept for your records |
| **Archive** | Escape hatch for mistakes / test vehicles. Hides the row from delivery history |
| **Movement request** | BB asks dealer to arrange transport |
| **Direct order** | BB books ProSelver; dealer approves as owner |
| **Owner approval** | Dealer OK for someone else's booking against their VIN |
| **Group view** | One login sees multiple sibling dealerships |

---

## 15. Getting help

- **Access or permissions** — contact your dealership’s **Dealer Owner** or ProSelver operations.
- **Wrong location on stock** — check that the related transport job is completed; stock location often follows job status. Stock controllers can correct details on the vehicle card where permitted.
- **Missing body builder** — use **Request a BB** or ask ProSelver to onboard them.
- **Technical issues** — contact ProSelver support with your dealership name, VIN or job number, and a screenshot if possible.

---

## 16. Suggested walkthrough (new users)

1. Log in and open **Dashboard** — note the eight card counts and how they line up against the lifecycle.
2. Tap **At body builder** and confirm the ledger filter matches your expectation; tap again to clear.
3. Open **Stock → All stock**, try search on a known VIN, then click **Reserved only** to see what's on hold.
4. Open any vehicle row and look at the **Lifecycle timeline** and **Transport movement** panels.
5. Click **Book delivery** from a vehicle card and see the create-order form pre-fill.
6. Open **Movements → My movements** and note whether any amber owner-approval badge appears.
7. Open **Body Builders → Movement Requests** and read the blue info box (movement request vs direct order).
8. If you manage users, skim **Account → Team** to see role names for your colleagues.

---

*Internal reference: feature map and UX notes live in the dealer module audit plan; developer workflows are in `docs/WORKFLOWS.md`.*

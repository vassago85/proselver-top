# ProSelver Dealer Portal — User Manual

This guide is for dealership staff who use the **Dealer Portal** to track vehicles, book transport, and work with body builders. It reflects the portal as of June 2026.

---

## 1. What the portal does

The Dealer Portal helps you:

- See **where every vehicle on your stock ledger is** (at your yard, at a body builder, on the road, on demo, sold, or handed over).
- **Book movements** with ProSelver, your own drivers, a courier, or self-collection.
- **Approve** body-builder requests and movements placed against your vehicles.
- **Manage your team**, addresses, branding, and (where permitted) internal drivers and petty cash.

You sign in with the same ProSelver account your administrator created for you. What you see in the sidebar depends on your **role** and **permissions**.

---

## 2. Finding your way around

When your company is a dealer, the sidebar header reads **Dealer Portal**. Main sections:

| Section | What it is for |
|---------|----------------|
| **Orders** | Dashboard, new orders, bulk upload, order list |
| **Stock** | Full stock ledger and off-site / in-transit view |
| **Trips** | Plan trips for your own drivers; drivers use **My Day** |
| **Reports** | Deliveries report; live wallboard |
| **Resources** | Documents; address book |
| **Body Builders** | Movement requests; linked BBs; request a new BB |
| **Account** | Team, branding, drivers, petty cash |

**Badges on the sidebar**

- **Movement Requests** — amber number = pending requests from linked body builders waiting for your decision.
- **My Orders** — amber number = **direct orders** where a body builder booked ProSelver and you must approve the move as **vehicle owner**.

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

**Path:** Orders → **Dashboard** (`/customer/dashboard`)

The dashboard is a tablet-friendly **stock console**: seven cards showing counts for your visible dealerships.

| Card | Meaning |
|------|---------|
| **At premises** | On your dealership floor |
| **At body builder / fitment** | Parked at a linked body builder |
| **Scheduled for movement** | Transport booked; collection not started yet |
| **In transit** | On the road with an active ProSelver job |
| **At another storage** | Parked at another yard or storage site |
| **On demo with customer** | Out on demo |
| **Recently sold** | Marked **sold** in the last 30 days (may still be in transit) |

**Tap any card** to open the **full stock ledger** filtered to that bucket.

Quick actions at the top:

- **New Order** — if you may submit bookings.
- **View Orders** — your movement list.

Use **Open full stock →** below the list for the complete paginated ledger with extra filters (body builder, salesperson, search).

---

## 5. Stock — three views (read this carefully)

Dealers have **three** stock-related screens. They answer slightly different questions.

### 5.1 All stock (canonical ledger)

**Path:** Stock → **All stock** (`/customer/stock`)

This is the main **“where is my stock?”** table.

- Filter by **bucket** (location/status chips).
- Filter by **body builder** or **salesperson** (multi-select pills).
- Search by VIN, registration, model, etc.
- Click a row to open the **vehicle card**.

**Bucket labels**

| Label | Meaning |
|-------|---------|
| At premises | At your dealership |
| Body builder | At a body builder / fitment centre |
| Other storage | Another storage yard |
| In transit | Active transport job |
| On demo | With a customer on demo |
| **Handed over** | Customer delivery complete — marked handed over to the buyer |
| Scheduled for movement | Job booked, not yet collected |
| **Recently sold** | Sold in the last 30 days (not the same as handed over) |

**Important:** **Recently sold** and **Handed over** are different. A vehicle can be sold but still in transit; **handed over** means the customer handover step is done on the ledger.

**Import stock:** If you have **Manage Dealer Stock**, use **Import stock** to upload a CSV of vehicles onto the ledger.

### 5.2 Off-site & in transit (job-based view)

**Path:** Stock → **Off-site & in transit** (`/customer/stock/at-body-builder`)

This view is built from **transport jobs**, not only the ledger. It shows vehicles you still own that are:

- at a body builder,
- at other storage, or
- actively on the road.

Vehicles **handed over** to the buyer drop off this list. Use **Book return** (where shown) to start an order with pickup/delivery pre-filled.

For counts that match the ledger (e.g. “how many at BB?”), prefer **All stock** → **Body builder** bucket.

### 5.3 Dashboard cards

Same buckets as the ledger, optimised for quick counts on a tablet. Cards link straight into **All stock** with the right filter.

---

## 6. Vehicle card (single stock unit)

**Path:** click any row on **All stock** → `/customer/stock/{id}`

What you can do here depends on **Manage Dealer Stock**:

| Action | What it does |
|--------|----------------|
| **Mark as sold** | Records salesperson and customer; sets status to sold |
| **Send out on demo** | Customer details and due-back date; location becomes on demo |
| **Return from demo** | Brings the unit back from demo |
| **Mark handed over** | Customer handover complete (ledger location: handed over) |
| **Reverse sale** | Undoes a sale while still allowed |
| **Archive** | Removes from active dashboards/lists (soft archive) |
| **Body builder details** | Optional fields shared with the BB when the vehicle is on their premises |
| **Sale delivery note** | Print/download where available |

Share salesperson and end-customer details with the body builder only when you intend them to see that information on their yard app.

---

## 7. Orders and movements

### 7.1 Creating an order

**Path:** Orders → **New Order** (`/customer/orders/create`)

You choose:

- **Pickup and delivery** (from your address book or search).
- **Vehicle** (VIN / details).
- **Executor:** ProSelver driver, your own driver, courier, or self-collect.

Most dealers use **ProSelver** for long-distance transport. Your own driver and trip planner are for internal fleet workflows.

You can also reach create-order from **Off-site & in transit** via **Book return**.

### 7.2 My Orders

**Path:** Orders → **My Orders** (`/customer/orders`)

Lists movements where your dealership is the **booking customer** and movements where you are only the **vehicle owner** (e.g. body-builder direct orders).

**Filters:** search, status, archived, dealership (group users), and **owner pending only** when approvals are waiting.

**Amber banner:** “Movements awaiting your approval” = **direct orders** (see section 8). Use **Show only these** to filter the list.

Open an order to confirm readiness, mark urgent, change executor, assign your driver, upload documents or POs, and approve or reject **owner** movements.

When you are only the vehicle owner, **pricing is hidden** — you are approving that the vehicle may move, not paying the transport.

### 7.3 Bulk upload

**Path:** Orders → **Bulk Upload** — for principals and similar roles. Upload a spreadsheet of multiple movements at once.

---

## 8. Body builders — two different flows

This is the most common source of confusion. There are **two** ways a body builder moves your stock.

### 8.1 Movement request (BB asks you)

| | |
|---|---|
| **Who books transport?** | **You** (the dealer), after you approve |
| **Who pays ProSelver?** | You (or you use your own driver) |
| **Where to act** | **Body Builders → Movement Requests** |
| **What happens** | BB raises next-fitment or collection request → you approve or reject → approving creates a job in **your** queue |

Check the sidebar badge on **Movement Requests** for pending items.

### 8.2 Direct order (BB books ProSelver)

| | |
|---|---|
| **Who books transport?** | **The body builder** with ProSelver |
| **Who pays ProSelver?** | The body builder |
| **Your role** | **Vehicle owner** — approve or reject that **your** VIN may move |
| **Where to act** | **My Orders** (not Movement Requests) |
| **Badge** | Amber count on **My Orders** in the sidebar |

You do **not** see commercial pricing on these owner-only views.

**Summary**

- **Movement request** = “BB asks you to arrange the move.”
- **Direct order** = “BB booked ProSelver; you only approve the move.”

### 8.3 Linking body builders

**Path:** Body Builders → **Linked Body Builders**

Dealer owners link authorised body builders so they can raise requests or direct orders against your stock.

**Request a BB** sends a request to ProSelver operations if a builder is not yet on the platform.

---

## 9. Trips (own drivers)

**Path:** Trips → **Trip Planner**

If your dealership uses **internal drivers** (not OEM-only accounts), planners attach confirmed jobs to trips.

**My Day** is for drivers — their assigned collections and deliveries for the day.

Jobs still waiting for **owner approval** cannot be attached to a trip until approved.

---

## 10. Reports and resources

| Item | Path | Purpose |
|------|------|---------|
| **Deliveries** | Reports → Deliveries | Delivery history and metrics |
| **Live Display** | Reports → Live Display | Wallboard of active movements (opens in new tab) |
| **Documents** | Resources → Documents | POs and files across orders |
| **Address Book** | Resources → Address Book | Pickup/delivery locations for bookings |

---

## 11. Account settings

| Item | Who typically uses it |
|------|------------------------|
| **Team** | Dealer Owner — add users, assign roles |
| **Branding** | Logo/details on sale delivery notes |
| **Drivers** | Internal driver pool (dealer only) |
| **Petty cash** | Own-driver expense tracking |

**Profile** (user menu) — your name, password, and personal settings.

---

## 12. Common tasks — quick reference

| I want to… | Go to… |
|------------|--------|
| See everything on my books | Stock → **All stock** |
| See only vehicles at a body builder | Dashboard card or All stock → **Body builder** |
| See what is on the road right now | **In transit** bucket or Off-site & in transit |
| Book ProSelver to move a vehicle | Orders → **New Order** |
| Approve a BB’s “please move this” request | Body Builders → **Movement Requests** |
| Approve a BB’s ProSelver booking on my VIN | Orders → **My Orders** (check badge) |
| Mark a vehicle sold | All stock → vehicle → **Mark as sold** |
| Record customer handover | Vehicle card → **Mark handed over** |
| Import new stock | All stock → **Import stock** |
| Add a delivery address | Resources → **Address Book** |
| Upload a PO | Open the order → documents section |

---

## 13. Glossary

| Term | Meaning |
|------|---------|
| **Stock ledger** | Your register of vehicles on the books (`dealer_stock`) |
| **Bucket** | Where the system thinks the vehicle is (premises, BB, in transit, etc.) |
| **Movement / order / job** | A transport booking in ProSelver |
| **Handed over** | Customer delivery step completed on the ledger |
| **Recently sold** | Sold in the last 30 days |
| **Movement request** | BB asks dealer to arrange transport |
| **Direct order** | BB books ProSelver; dealer approves as owner |
| **Owner approval** | Dealer OK for someone else’s booking against their VIN |
| **Group view** | One login sees multiple sibling dealerships |

---

## 14. Getting help

- **Access or permissions** — contact your dealership’s **Dealer Owner** or ProSelver operations.
- **Wrong location on stock** — check that the related transport job is completed; stock location often follows job status. Stock controllers can correct details on the vehicle card where permitted.
- **Missing body builder** — use **Request a BB** or ask ProSelver to onboard them.
- **Technical issues** — contact ProSelver support with your dealership name, VIN or job number, and a screenshot if possible.

---

## 15. Suggested walkthrough (new users)

1. Log in and open **Dashboard** — note the seven card counts.
2. Tap **At body builder** and confirm the ledger filter matches your expectation.
3. Open **Stock → All stock** and try search on a known VIN.
4. Open **Orders → My Orders** and note whether any amber owner-approval badge appears.
5. Open **Body Builders → Movement Requests** and read the blue info box (movement request vs direct order).
6. If you manage users, skim **Account → Team** to see role names for your colleagues.

---

*Internal reference: feature map and UX notes live in the dealer module audit plan; developer workflows are in `docs/WORKFLOWS.md`.*

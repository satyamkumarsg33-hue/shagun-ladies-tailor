# Shagun Ladies Tailor — Permanent Database Architecture Plan
**Database Name:** `shagun_ladies_tailor`  
**Engine:** MySQL 8.0+ / MariaDB 10.4+ (InnoDB Engine)  
**Default Collation:** `utf8mb4_unicode_ci`  
**Document Version:** 1.1.0 (Revised Architectural Specification)

---

## Executive Summary & Architectural Decisions

This document establishes the permanent relational database schema for **Shagun Ladies Tailor**, bridging the current PHP prototype (`includes/demo-data.php`, session-based cart, checkout, Luxe Wedding, Luxe Family & Celebrations, and Order Dossier PDF engines) to a scalable, production-grade MySQL architecture.

### Key Architectural Tenets

1. **Mandatory Authenticated User Hierarchy**:
   - Finalized orders must strictly belong to an authenticated customer.
   - The user workflow is: `Guest → Cart → Authentication → User Account → Order Booking`.
   - Both Luxe Stitching (guarded at entry) and Standard Stitching (guarded at checkout) enforce this.
   - The obsolete concept of `guest` accounts in `orders` is eliminated.
2. **Unified Order Core**:
   - A single `orders` table accommodates all service workflows (`standard`, `wedding`, `family`, and future `bulk`) via a `workflow_type` discriminator. No fragmented order tables (`standard_orders`, `luxe_orders`, etc.).
3. **Strict Physical Garment Rule**:
   - **ONE physical garment = ONE database row**.
   - If a customer or person orders 3 blouses, they are stored as 3 distinct rows in `order_garments`, each with independent styles, customizations, embroidery work, material links, tailoring statuses, and pricing. Duplicate garments are never collapsed into quantity-only rows.
4. **Physical Material & Reference Disaggregation**:
   - A physical customer-provided item (e.g. 1 physical Reference Blouse or 1 roll of Lace) is represented **exactly once** in `order_materials`.
   - Garments reference physical materials via `order_garment_material_links` (Many-to-Many mapping).
   - If 3 blouses use the same Reference Blouse, the physical blouse is logged once with its storage bin and Admin inspection photos, and mapped to all 3 physical blouse records.
5. **Multi-Item Embroidery / Work Normalization**:
   - A garment can have No Work, Machine Work, Hand Work, or Both. In the case of "Both", the single physical garment links to two distinct rows in `order_garment_work_items` without duplicating the garment.
6. **Extensible Garment-Specific Measurements**:
   - Customers choose high-level fitting methods (`reference_blouse` or `visit_shop`) via `order_people`.
   - Numeric body measurements are Admin-controlled. Rather than an unstructured text/JSON blob or hardcoding only blouse fields, a normalized table `order_garment_measurement_values` stores typed dimensions for any garment silhouette (Blouse, Lehenga, Kurti, Salwar Suit, Anarkali, Gown) backed by `measurement_fields` catalogue definitions.
7. **Multi-Payment Lifecycle**:
   - An order supports multiple sequential payments (Advance, Second, Final) in `order_payments`, maintaining financial auditability while `orders` tracks running summary balances.
8. **Immutable Date Audit History**:
   - Dates (`requested_ready_date`, `booked_date`, `admin_delivery_date`) are strictly versioned in `order_date_history`. Customer-requested ready dates become immutable upon advance payment; admin updates append historical records.
9. **Authoritative Catalogue with Controlled Migration**:
   - The database is the authoritative source of truth for catalogue styles and pricing.
   - During migration only, a controlled fallback to `demo-data.php` is permitted in local development (`APP_ENV === 'local'`). In production, a database outage triggers a 503 Service Unavailable rather than silently serving stale prices.
10. **Role-Based Admin Access & Password Security**:
    - Dedicated `admin_users` table with strictly partitioned permissions for **Super Admin**, **Master Tailor**, and **Store Manager**.
    - Passwords are strictly stored as secure cryptographic hashes (`PASSWORD_DEFAULT` / Bcrypt / Argon2id), never in plaintext.

---

## High-Level Entity Relationship Diagrams

### 1. Unified Core Entity Hierarchy
```
 users (Authenticated Customers Only — No Guests)
   │
   ├──< customer_addresses
   ├──< customer_family_members
   │
   └──< orders (workflow_type: 'standard' | 'wedding' | 'family' | 'bulk')
          │
          ├──< order_people (Customer, Bride, Mother, Sister, Groom...)
          │      │
          │      └──< order_person_measurements (operational method: reference_blouse / visit_shop)
          │             │
          │             └──< order_garment_measurement_values (bust, waist, armhole, lehenga_length...)
          │
          ├──< order_garments (ONE ROW = ONE PHYSICAL PIECE)
          │      │
          │      ├──< order_garment_customizations (neck, sleeves, lining, piping...)
          │      ├──< order_garment_work_items (machine, hand, or both)
          │      └──< order_garment_material_links (Maps physical materials to garments)
          │
          ├──< order_materials (ONE ROW = ONE PHYSICAL ITEM: Fabric, Reference Blouse, Trims)
          │      │
          │      └──< material_photos (Admin color photos + grayscale dossier variants)
          │
          ├──< order_payments (advance, second, final)
          ├──< order_status_history (status progression timeline)
          ├──< order_date_history (requested vs admin delivery audit log)
          └──< order_documents (Order Dossier, Completion Dossier PDFs)
```

### 2. Physical Material Mapping Architecture
```
                   ┌──────────────────────────────────────┐
                   │           order_materials            │
                   │ (1 Physical Banarasi Reference Blouse│
                   │  Tag: MAT-01, Bin: A-14, Received)   │
                   └──────────────────┬───────────────────┘
                                      │
                         material_photos (Admin Photos)
                                      │
                ┌─────────────────────┴─────────────────────┐
                ▼                                           ▼
┌───────────────────────────────┐           ┌───────────────────────────────┐
│ order_garment_material_links  │           │ order_garment_material_links  │
│ Garment: Blouse #1            │           │ Garment: Blouse #2            │
│ Role: reference_sample        │           │ Role: reference_sample        │
└───────────────┬───────────────┘           └───────────────┬───────────────┘
                ▼                                           ▼
┌───────────────────────────────┐           ┌───────────────────────────────┐
│        order_garments         │           │        order_garments         │
│ (Blouse #1: U-Cut, ₹1,290)    │           │ (Blouse #2: Princess, ₹1,450) │
└───────────────────────────────┘           └───────────────────────────────┘
```

### 3. Catalogue Master Data Architecture
```
 garment_categories (blouse, lehenga, kurti, salwar-suit, anarkali, gown, alterations)
   │
   ├──< garment_styles (u-cut, princess-cut, katori-cut, panel-cut, boat-neck...)
   │
   ├──< customization_groups (neck_design, sleeve_style, sleeve_length, lining, cups, piping...)
   │      │
   │      └──< customization_options (sweetheart, square, keyhole, puff, elbow, contrast...)
   │
   └──< measurement_fields (silhouette measurement points: bust, apex, lehenga_length, flair...)

 embroidery_designs (machine M-018, M-024... | hand H-012, H-007...)
 work_placements (Neck, Sleeve, Back, All Over, Custom)
```

### 4. System & Administration Support
```
 admin_users (Super Admin, Master Tailor, Store Manager)
   │
   └──< activity_logs (audit trail for admin actions, status changes, date overrides)

 shop_settings (shop address, operational timings, advance % thresholds, lead time defaults)
```

---

## Complete Proposed Table List (27 Tables)

| # | Table Name | Category | Primary Purpose | Current Code Source / Equivalent |
|---|---|---|---|---|
| 1 | `users` | Core / Auth | Authenticated customer accounts (no guests) | `includes/auth.php`, `login.php`, `signup.php` |
| 2 | `customer_addresses` | Core / Customer | Customer billing and delivery addresses | `checkout.php` |
| 3 | `customer_family_members` | Core / Customer | Reusable family profiles & roles for repeat Luxe bookings | `people.php` |
| 4 | `orders` | Core / Order | Central unified order header, financials, dates, status | `checkout.php`, `orders.php`, `review-payment.php` |
| 5 | `order_people` | Core / Order | Person roles (Customer, Bride, Mother, etc.) in an order | `people.php`, `$_SESSION['luxe_wedding']['people']` |
| 6 | `order_garments` | Core / Garment | Finalized physical garment records (1 row = 1 garment) | `garments.php`, `cart.php`, `$_SESSION['standard_order']` |
| 7 | `order_garment_customizations` | Core / Garment | Normalized style choices & price deltas per physical piece | `customize-blouse.php`, `includes/demo-data.php` |
| 8 | `order_garment_work_items` | Core / Garment | Normalized embroidery items (Machine, Hand, or Both) | `luxe-work.php`, `includes/demo-data.php` |
| 9 | `order_person_measurements` | Core / Measurement| Customer method (`reference_blouse`/`visit_shop`) & intake status | `measurements.php`, `includes/dossier-pdf.php` |
| 10 | `order_garment_measurement_values`| Core / Measurement| Normalized body/garment measurements for any silhouette | Bespoke fitting desk |
| 11 | `order_materials` | Core / Material | Physical customer-provided items (Fabric, Reference, Trims)| Atelier physical intake desk |
| 12 | `order_garment_material_links` | Core / Material | Many-to-Many mapping of physical materials to garments | Order Dossier material mapping |
| 13 | `material_photos` | Core / Material | Photographs taken by Admin at shop + grayscale dossier refs | `includes/dossier-pdf.php` (Admin material photo) |
| 14 | `order_payments` | Core / Finance | Multi-payment records (Advance, Second, Final) & gateways | `payment.php`, `review-payment.php` |
| 15 | `order_date_history` | Core / Audit | Audit trail of requested vs admin committed ready dates | `orders.php` (`date_history`), `dossier-pdf.php` |
| 16 | `order_status_history` | Core / Audit | Historical order status progression log | Order lifecycle state machine |
| 17 | `order_documents` | Core / Document | Generated PDF dossiers & formal documents | `includes/dossier-pdf.php`, `orders.php` |
| 18 | `garment_categories` | Catalogue | Master garment categories (Blouse, Lehenga, Kurti, etc.) | `includes/demo-data.php::standard_stitching_categories` |
| 19 | `garment_styles` | Catalogue | Base garment styles (U-Cut, Princess Cut, etc.) with prices | `includes/demo-data.php::blouse_styles` |
| 20 | `customization_groups` | Catalogue | Style feature groups (Neck design, Sleeve style, Lining...) | `includes/demo-data.php::blouse_customization_options` |
| 21 | `customization_options` | Catalogue | Available choices per group with price adjustments | `includes/demo-data.php::blouse_customization_options` |
| 22 | `measurement_fields` | Catalogue | Silhouette measurement point definitions (Blouse, Gown, etc.)| Tailoring specifications |
| 23 | `embroidery_designs` | Catalogue | Machine & Hand embroidery catalogue codes and base prices | `includes/demo-data.php::luxe_machine/hand_work_designs` |
| 24 | `work_placements` | Catalogue | Standard embroidery placement positions | `includes/demo-data.php::luxe_work_placements` |
| 25 | `admin_users` | Admin / Security | Admin, Master Tailor, and Staff credentials & permissions | Admin Dashboard authentication |
| 26 | `shop_settings` | Admin / Config | Configurable shop address, contact, advance rules, lead times| `includes/config.php`, `includes/dossier-pdf.php` |
| 27 | `activity_logs` | Admin / Audit | Complete audit log for administrative and system operations | Security & operational tracking |

---

## Detailed Table Specifications

### 1. `users`
**Purpose:** Authenticated customer accounts. Accommodates prototype Google/Email logins and seamlessly upgrades to production OAuth/password authentication. **No guest users are permitted in finalized orders.**

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `name` | `VARCHAR(150)` | NO | None | Customer full name |
| `email` | `VARCHAR(191)` | NO | None | **UNIQUE KEY `uk_users_email`** |
| `phone` | `VARCHAR(30)` | YES | NULL | Customer phone number with country code |
| `password_hash` | `VARCHAR(255)` | YES | NULL | For email/password login (NULL for OAuth users) |
| `auth_provider` | `ENUM('google','email','phone')` | NO | `'google'` | Authentication source (**No 'guest'**) |
| `provider_id` | `VARCHAR(191)` | YES | NULL | External OAuth subject ID (e.g. Google sub) |
| `role` | `ENUM('customer','staff','admin')` | NO | `'customer'` | Base account tier |
| `is_active` | `TINYINT(1)` | NO | `1` | Account status flag |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Account creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_users_email (email)`
- `INDEX idx_users_phone (phone)`
- `INDEX idx_users_provider (auth_provider, provider_id)`

---

### 2. `customer_addresses`
**Purpose:** Saved shipping, billing, and workshop pickup/return addresses for customers.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `user_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `users(id)` ON DELETE CASCADE |
| `address_type` | `ENUM('home','work','other')` | NO | `'home'` | Address category |
| `recipient_name`| `VARCHAR(150)` | NO | None | Name of receiving person |
| `phone` | `VARCHAR(30)` | NO | None | Contact number for delivery/pickup |
| `address_line1`| `VARCHAR(255)` | NO | None | House / Flat / Street address |
| `address_line2`| `VARCHAR(255)` | YES | NULL | Apartment, Suite, Landmark details |
| `city` | `VARCHAR(100)` | NO | `'Bengaluru'` | City name |
| `state` | `VARCHAR(100)` | NO | `'Karnataka'` | State name |
| `postal_code` | `VARCHAR(20)` | NO | None | Postal / PIN code |
| `is_default` | `TINYINT(1)` | NO | `0` | Default address indicator |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `INDEX idx_cust_addr_user (user_id)`

---

### 3. `customer_family_members`
**Purpose:** Stores reusable family members (e.g. Ramya - Bride, Gunjan - Sister) associated with a customer account for instant reselection in future Luxe bookings.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `user_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `users(id)` ON DELETE CASCADE |
| `name` | `VARCHAR(150)` | NO | None | Family member name |
| `relation_role`| `VARCHAR(50)` | NO | None | Wedding/Family role (e.g. Bride, Mother, Sister) |
| `notes` | `TEXT` | YES | NULL | Fit preferences, garment notes |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `INDEX idx_cfm_user (user_id)`

---

### 4. `orders`
**Purpose:** Central unified order header for ALL stitching flows (Standard, Wedding, Family, and Bulk). Holds high-level financials, dates, and status. **`user_id` strictly references an authenticated customer account.**

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_ref` | `VARCHAR(50)` | NO | None | **UNIQUE KEY `uk_orders_ref`** (e.g. `LT20261025-001`) |
| `user_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `users(id)` ON DELETE RESTRICT |
| `workflow_type`| `ENUM('standard','wedding','family','bulk')` | NO | None | Order workflow category |
| `occasion` | `VARCHAR(100)` | NO | None | Event name (e.g. 'Standard Stitching', 'Wedding', 'Diwali') |
| `status` | `ENUM('pending_payment','booked','materials_pending','in_production','ready_for_trial','completed','delivered','cancelled')` | NO | `'booked'` | Current order lifecycle status |
| `total_amount` | `DECIMAL(10,2)` | NO | `0.00` | Grand total tailoring charges (Rs.) |
| `advance_amount`| `DECIMAL(10,2)` | NO | `0.00` | Total advance paid (Rs.) |
| `balance_amount`| `DECIMAL(10,2)` | NO | `0.00` | Remaining balance to collect (Rs.) |
| `payment_status`| `ENUM('unpaid','partially_paid','fully_paid','refunded')` | NO | `'partially_paid'` | Aggregate payment status |
| `advance_percentage` | `INT UNSIGNED` | NO | `50` | Selected advance percentage (30 to 100) |
| `currency` | `VARCHAR(10)` | NO | `'INR'` | Currency code (INR) |
| `booked_date` | `DATE` | NO | None | Order booking date (immutable server date) |
| `requested_ready_date` | `DATE` | NO | None | Customer requested delivery date (locked at payment) |
| `admin_delivery_date` | `DATE` | NO | None | Shagun committed estimated delivery date |
| `customer_notes`| `TEXT` | YES | NULL | Customer notes/instructions at checkout |
| `admin_notes` | `TEXT` | YES | NULL | Admin internal notes / atelier instructions |
| `is_demo` | `TINYINT(1)` | NO | `1` | 1 if placed via prototype/demo gateway, 0 for live |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Order record timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_orders_ref (order_ref)`
- `INDEX idx_orders_user (user_id)`
- `INDEX idx_orders_workflow (workflow_type)`
- `INDEX idx_orders_status (status)`
- `INDEX idx_orders_booked_date (booked_date)`
- `INDEX idx_orders_delivery_date (admin_delivery_date)`

---

### 5. `order_people`
**Purpose:** Represents every individual person in an order. Standard orders have 1 row (the customer, role `'Customer'`). Luxe orders have 1 to 10+ rows (e.g. Bride, Mother, Sister).

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `orders(id)` ON DELETE CASCADE |
| `family_member_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `customer_family_members(id)` ON DELETE SET NULL |
| `person_order_index` | `INT UNSIGNED` | NO | `1` | Sequence index (1 for Person 1, 2 for Person 2, etc.) |
| `name` | `VARCHAR(150)` | NO | None | Person's display name |
| `role` | `VARCHAR(100)` | NO | None | Occasion role (e.g. 'Customer', 'Bride', 'Sister', 'Groom') |
| `measurement_method` | `ENUM('reference_blouse','visit_shop')` | NO | `'reference_blouse'` | Measurement method chosen for this person |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_order_person_idx (order_id, person_order_index)`
- `INDEX idx_order_people_order (order_id)`

---

### 6. `order_garments`
**Purpose:** **ONE PHYSICAL GARMENT = ONE RECORD**. Every finalized physical garment is independently represented with its own status, style, and pricing.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `orders(id)` ON DELETE CASCADE |
| `order_person_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `order_people(id)` ON DELETE CASCADE |
| `garment_index`| `INT UNSIGNED` | NO | `1` | Garment index for this person (e.g. 1 for Blouse #1, 2 for Blouse #2) |
| `garment_type` | `VARCHAR(100)` | NO | None | Garment name (e.g. 'Blouse', 'Lehenga', 'Gown', 'Anarkali') |
| `style_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `garment_styles(id)` ON DELETE SET NULL |
| `style_slug` | `VARCHAR(100)` | NO | None | Snapshot style slug at booking (e.g. 'u-cut') |
| `style_name` | `VARCHAR(150)` | NO | None | Snapshot style name at booking (e.g. 'U-Cut Blouse') |
| `base_price` | `DECIMAL(10,2)` | NO | `0.00` | Snapshot base tailoring price (Rs.) |
| `customization_total` | `DECIMAL(10,2)` | NO | `0.00` | Sum of all customization options (Rs.) |
| `work_total` | `DECIMAL(10,2)` | NO | `0.00` | Sum of all embroidery work options (Rs.) |
| `total_price` | `DECIMAL(10,2)` | NO | `0.00` | Total price for this physical garment (Rs.) |
| `work_type` | `ENUM('no_work','machine','hand','both')` | NO | `'no_work'` | Primary work classification |
| `status` | `ENUM('not_started','cutting','embroidery','stitching','finishing','quality_check','ready','delivered')` | NO | `'not_started'` | Individual garment tailoring state |
| `item_notes` | `TEXT` | YES | NULL | Specific tailoring or fit notes for this piece |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `INDEX idx_order_garments_order (order_id)`
- `INDEX idx_order_garments_person (order_person_id)`
- `INDEX idx_order_garments_status (status)`
- `INDEX idx_order_garments_type (garment_type)`

---

### 7. `order_garment_customizations`
**Purpose:** Normalized key-value style configuration rows for each physical garment. Preserves immutable price deltas and choice labels at time of order.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_garment_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `order_garments(id)` ON DELETE CASCADE |
| `option_group_slug` | `VARCHAR(100)` | NO | None | Feature group slug (e.g. `neck_design`, `sleeve_style`, `lining`) |
| `option_group_label`| `VARCHAR(150)` | NO | None | Snapshot label (e.g. `'Neck design'`, `'Sleeve style'`) |
| `choice_value` | `VARCHAR(100)` | NO | None | Selected value (e.g. `'sweetheart'`, `'puff'`, `'yes'`) |
| `choice_label` | `VARCHAR(150)` | NO | None | Snapshot label (e.g. `'Sweetheart neck'`, `'Add lining'`) |
| `price_delta` | `DECIMAL(10,2)` | NO | `0.00` | Snapshot price delta (Rs.) |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_garment_customization_group (order_garment_id, option_group_slug)`
- `INDEX idx_ogc_garment (order_garment_id)`

---

### 8. `order_garment_work_items`
**Purpose:** Handles embroidery requirements for a physical garment. If `work_type = 'both'`, the garment has TWO entries (one `'machine'`, one `'hand'`) without duplicating garments.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_garment_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `order_garments(id)` ON DELETE CASCADE |
| `work_category`| `ENUM('machine','hand')` | NO | None | Work classification |
| `design_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `embroidery_designs(id)` ON DELETE SET NULL |
| `design_code` | `VARCHAR(50)` | NO | None | Snapshot design code (e.g. `'M-018'`, `'H-012'`) |
| `design_name` | `VARCHAR(150)` | NO | None | Snapshot design name (e.g. `'Paisley Border'`, `'Zari Floral'`) |
| `placement` | `VARCHAR(100)` | NO | None | Placement area (e.g. `'Neck & Sleeves'`, `'Back'`, `'All Over'`) |
| `price` | `DECIMAL(10,2)` | NO | `0.00` | Price charged for this work item (Rs.) |
| `thread_zari_specs` | `VARCHAR(255)` | YES | NULL | Thread/Zari colour or spec notes from customer/admin |
| `artisan_notes`| `TEXT` | YES | NULL | Workshop notes for karigar/machine operator |
| `status` | `ENUM('pending','tracing','in_embroidery','completed','inspected')` | NO | `'pending'` | Embroidery production status |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_garment_work_category (order_garment_id, work_category)`
- `INDEX idx_ogw_garment (order_garment_id)`
- `INDEX idx_ogw_design (design_id)`

---

### 9. `order_person_measurements`
**Purpose:** Captures the customer-facing measurement method (`reference_blouse` or `visit_shop`) and logs in-shop fitting visit workflow.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_person_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `order_people(id)` ON DELETE CASCADE |
| `measurement_method` | `ENUM('reference_blouse','visit_shop')` | NO | `'reference_blouse'` | Active method chosen by customer |
| `reference_blouse_received` | `TINYINT(1)` | NO | `0` | Physical sample blouse checked in at shop |
| `shop_visit_completed` | `TINYINT(1)` | NO | `0` | In-person fitting session completed |
| `shop_visit_date` | `DATETIME` | YES | NULL | Scheduled or completed visit timestamp |
| `fitter_notes` | `TEXT` | YES | NULL | Master Tailor general fit remarks |
| `measured_by_admin_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `admin_users(id)` ON DELETE SET NULL |
| `measured_at` | `DATETIME` | YES | NULL | Date/time measurements recorded |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `INDEX idx_opm_person (order_person_id)`

---

### 10. `order_garment_measurement_values`
**Purpose:** Normalized, strongly typed numeric measurement points recorded by the Master Tailor. Accommodates any garment silhouette (Blouse, Lehenga, Kurti, Salwar Suit, Anarkali, Gown) without unstructured blobs or altering the schema.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_person_measurement_id`| `BIGINT UNSIGNED` | NO | None | **FK** -> `order_person_measurements(id)` ON DELETE CASCADE |
| `order_garment_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `order_garments(id)` ON DELETE CASCADE (NULL if applies to all garments of this silhouette) |
| `garment_type` | `VARCHAR(50)` | NO | None | Silhouette (`'blouse'`, `'lehenga'`, `'kurti'`, `'salwar_suit'`, `'anarkali'`, `'gown'`) |
| `measurement_key` | `VARCHAR(50)` | NO | None | Dimension key (`bust`, `waist`, `armhole`, `lehenga_length`, `shoulder`, etc.) |
| `measurement_label` | `VARCHAR(100)` | NO | None | Human readable label (e.g. `'Bust Circumference'`, `'Lehenga Length'`) |
| `value` | `DECIMAL(5,2)` | NO | None | Numeric measurement value (e.g. `36.50`) |
| `unit` | `ENUM('inch','cm')` | NO | `'inch'` | Measurement unit |
| `notes` | `VARCHAR(255)` | YES | NULL | Point-specific tailoring note (e.g. `'Sloping shoulder +0.25in'`) |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_ogmv_point (order_person_measurement_id, garment_type, measurement_key, order_garment_id)`
- `INDEX idx_ogmv_garment (order_garment_id)`
- `INDEX idx_ogmv_type (garment_type)`

---

### 11. `order_materials`
**Purpose:** **Physical Material Intake at the Order Level**. Every customer-provided physical item (fabric length, sample reference blouse, trim roll) is recorded **exactly once**, regardless of how many garments use it.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `orders(id)` ON DELETE CASCADE |
| `order_person_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `order_people(id)` ON DELETE SET NULL (Owner of the material) |
| `material_code` | `VARCHAR(50)` | NO | None | Atelier tag / barcode identifier (e.g. `MAT-LT20261025-01`) |
| `material_type` | `ENUM('customer_fabric','reference_blouse','lace_trims','lining_fabric','dupatta','other')` | NO | None | Physical item category |
| `material_name` | `VARCHAR(150)` | NO | None | Item description (e.g. `'Red Banarasi Reference Blouse'`, `'Raw Silk Fabric 5m'`) |
| `quantity_meters` | `DECIMAL(5,2)` | YES | NULL | Fabric length in metres (NULL for reference garments) |
| `storage_bin_location` | `VARCHAR(50)` | YES | NULL | Atelier physical storage location (e.g. `'BIN-A14'`, `'Shelf 2'`) |
| `status` | `ENUM('awaiting_receipt','received','inspected','in_use','returned','exhausted')` | NO | `'awaiting_receipt'` | Current physical lifecycle status |
| `received_date` | `DATE` | YES | NULL | Date physical material arrived at the shop |
| `inspected_date` | `DATE` | YES | NULL | Date inspected by Master Tailor |
| `used_date` | `DATE` | YES | NULL | Date taken to cutting/embroidery desk |
| `returned_date` | `DATE` | YES | NULL | Date returned to customer upon order collection |
| `condition_notes` | `TEXT` | YES | NULL | Physical inspection observations (e.g. stains, tears, zari tarnishing) |
| `admin_notes` | `TEXT` | YES | NULL | Internal atelier handling instructions |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_order_material_code (material_code)`
- `INDEX idx_om_order (order_id)`
- `INDEX idx_om_person (order_person_id)`
- `INDEX idx_om_status (status)`

---

### 12. `order_garment_material_links`
**Purpose:** Maps physical materials from `order_materials` to the physical garments in `order_garments`. Supports many garments sharing one reference blouse, or one garment using multiple fabrics/trims.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_garment_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `order_garments(id)` ON DELETE CASCADE |
| `order_material_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `order_materials(id)` ON DELETE CASCADE |
| `usage_role` | `ENUM('primary_fabric','reference_sample','trims_embellishment','lining','dupatta')` | NO | None | How this garment utilizes the material |
| `notes` | `VARCHAR(255)` | YES | NULL | Usage details (e.g. `'Use sleeve pattern from this sample'`, `'Cutting 1.25m'`) |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_garment_material_role (order_garment_id, order_material_id, usage_role)`
- `INDEX idx_ogml_garment (order_garment_id)`
- `INDEX idx_ogml_material (order_material_id)`

---

### 13. `material_photos`
**Purpose:** Stores Admin photographs taken at the atelier upon physical arrival of customer fabric/reference items. Attached directly to the physical material item (`order_materials`). Supports full-colour previews for Admin and grayscale processing for the official Order Dossier PDF.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_material_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `order_materials(id)` ON DELETE CASCADE |
| `photo_url` | `VARCHAR(255)` | NO | None | Full-colour photograph path |
| `grayscale_photo_url` | `VARCHAR(255)` | YES | NULL | Grayscale version path for Order Dossier print |
| `photo_type` | `ENUM('color_admin','grayscale_dossier','inspection_flaw','completion_proof')` | NO | `'color_admin'` | Photo usage context |
| `caption` | `VARCHAR(255)` | YES | NULL | Caption (e.g. `'Zari border detail on sleeve fabric'`) |
| `taken_by_admin_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `admin_users(id)` ON DELETE SET NULL |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Capture timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `INDEX idx_mp_material (order_material_id)`

---

### 14. `order_payments`
**Purpose:** Multi-payment transaction log over an order's lifecycle (Advance, Second Payment, Final Payment). Retains simulated gateway attributes during prototype and supports live gateway tokens.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `orders(id)` ON DELETE CASCADE |
| `payment_stage`| `ENUM('advance','second','final','additional')` | NO | None | Payment instalment stage |
| `amount` | `DECIMAL(10,2)` | NO | None | Amount paid in this transaction (Rs.) |
| `currency` | `VARCHAR(10)` | NO | `'INR'` | Currency code (INR) |
| `payment_method` | `ENUM('upi','card','netbanking','wallet','cash')` | NO | `'upi'` | Instrument type |
| `payment_method_label` | `VARCHAR(100)` | NO | `'UPI / QR Code'` | Human-readable label for invoice/dossier |
| `transaction_ref` | `VARCHAR(100)` | NO | None | **UNIQUE KEY `uk_op_txn_ref`** (e.g. `PAY-LT20261025-ADV-01`) |
| `status` | `ENUM('initiated','pending','completed','failed','refunded')` | NO | `'completed'` | Transaction status |
| `gateway_mode` | `ENUM('simulated','live')` | NO | `'simulated'` | Simulated vs Production Gateway |
| `gateway_provider` | `VARCHAR(50)` | NO | `'Demo Razorpay / Internal'` | Gateway vendor / channel |
| `gateway_payment_id` | `VARCHAR(100)` | YES | NULL | External gateway payment ID |
| `gateway_signature` | `VARCHAR(255)` | YES | NULL | HMAC signature verification hash |
| `paid_at` | `DATETIME` | NO | None | Payment completion timestamp |
| `receipt_url` | `VARCHAR(255)` | YES | NULL | Payment slip / PDF receipt URL |
| `admin_notes` | `TEXT` | YES | NULL | Cash collection / reconciliation notes |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_op_txn_ref (transaction_ref)`
- `INDEX idx_op_order (order_id)`
- `INDEX idx_op_status (status)`
- `INDEX idx_op_paid_at (paid_at)`

---

### 15. `order_date_history`
**Purpose:** Complete chronological audit trail of all ready date requests and updates. Guarantees that neither customer requests nor admin delivery revisions are overwritten without audit trail.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `orders(id)` ON DELETE CASCADE |
| `event_type` | `ENUM('customer_request','admin_update','reschedule','expedite')` | NO | None | Action that triggered date event |
| `date_type` | `ENUM('requested_ready_date','admin_delivery_date','booked_date')` | NO | None | Date field modified |
| `old_date` | `DATE` | YES | NULL | Previous date (NULL for initial booking) |
| `new_date` | `DATE` | NO | None | Updated date value |
| `actor` | `ENUM('customer','admin','system')` | NO | None | Who initiated the change |
| `admin_user_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `admin_users(id)` ON DELETE SET NULL |
| `note` | `TEXT` | NO | None | Explanation for the change |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Event timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `INDEX idx_odh_order (order_id)`
- `INDEX idx_odh_created (created_at)`

---

### 16. `order_status_history`
**Purpose:** Tracks lifecycle progression of the order (from `'booked'` through `'materials_pending'`, `'in_production'`, `'ready_for_trial'`, to `'delivered'`).

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `orders(id)` ON DELETE CASCADE |
| `old_status` | `VARCHAR(50)` | YES | NULL | Status prior to transition |
| `new_status` | `VARCHAR(50)` | NO | None | Status after transition |
| `actor` | `ENUM('customer','admin','system')` | NO | `'system'` | Change initiator |
| `admin_user_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `admin_users(id)` ON DELETE SET NULL |
| `notes` | `TEXT` | YES | NULL | Context or operational notes |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Status change timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `INDEX idx_osh_order (order_id)`

---

### 17. `order_documents`
**Purpose:** Versioned storage and indexing of official Order Dossiers and Completion Dossiers generated via `includes/dossier-pdf.php`.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `order_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `orders(id)` ON DELETE CASCADE |
| `document_type`| `ENUM('order_dossier','completion_dossier','invoice','receipt')` | NO | None | Document category |
| `version` | `INT UNSIGNED` | NO | `1` | Document version number |
| `file_path` | `VARCHAR(255)` | NO | None | Relative server storage path |
| `file_name` | `VARCHAR(255)` | NO | None | Download filename |
| `file_size_bytes`| `INT UNSIGNED` | YES | NULL | Document size in bytes |
| `checksum` | `VARCHAR(64)` | YES | NULL | SHA-256 hash for document integrity |
| `generated_by` | `ENUM('customer_action','admin_action','automated')` | NO | `'customer_action'` | Trigger source |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Generation timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `INDEX idx_od_order (order_id)`
- `INDEX idx_od_type (document_type)`

---

### 18. `garment_categories`
**Purpose:** Master catalogue categories replacing hardcoded array in `includes/demo-data.php::standard_stitching_categories()`.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `slug` | `VARCHAR(50)` | NO | None | **UNIQUE KEY `uk_gc_slug`** (`blouse`, `lehenga`, `kurti`, etc.) |
| `name` | `VARCHAR(100)` | NO | None | Category display name (e.g. 'Blouse Stitching') |
| `description` | `TEXT` | YES | NULL | Marketing / atelier description |
| `image` | `VARCHAR(255)` | YES | NULL | Feature image path |
| `target_route` | `VARCHAR(100)` | YES | NULL | Frontend routing link (e.g. `'blouse-styles.php'`) |
| `is_available` | `TINYINT(1)` | NO | `1` | Active availability status |
| `display_order`| `INT` | NO | `0` | Visual display ordering |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_gc_slug (slug)`
- `INDEX idx_gc_available (is_available)`

---

### 19. `garment_styles`
**Purpose:** Base garment styles (e.g. U-Cut, Princess Cut, Katori Cut) replacing hardcoded array in `includes/demo-data.php::blouse_styles()`.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `category_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `garment_categories(id)` ON DELETE CASCADE |
| `slug` | `VARCHAR(100)` | NO | None | **UNIQUE KEY `uk_gs_slug`** (e.g. `'u-cut'`, `'princess-cut'`) |
| `name` | `VARCHAR(150)` | NO | None | Style name (e.g. 'Princess Cut Blouse') |
| `description` | `TEXT` | YES | NULL | Style details and fit characteristics |
| `base_price` | `DECIMAL(10,2)` | NO | `0.00` | Standard base tailoring price (Rs.) |
| `image` | `VARCHAR(255)` | YES | NULL | Style preview image path |
| `lead_time_days`| `INT UNSIGNED` | NO | `7` | Typical stitching lead time in days |
| `complexity_level` | `ENUM('standard','intermediate','intricate')` | NO | `'standard'` | Tailoring complexity indicator |
| `is_active` | `TINYINT(1)` | NO | `1` | Active catalogue status |
| `display_order`| `INT` | NO | `0` | Visual display order |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_gs_slug (slug)`
- `INDEX idx_gs_category (category_id)`
- `INDEX idx_gs_active (is_active)`

---

### 20. `customization_groups`
**Purpose:** Customization feature groups (Neck design, Sleeve style, Lining, etc.) replacing top-level keys in `includes/demo-data.php::blouse_customization_options()`.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `category_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `garment_categories(id)` ON DELETE CASCADE |
| `slug` | `VARCHAR(100)` | NO | None | Group slug (e.g. `'neck_design'`, `'sleeve_style'`, `'lining'`) |
| `label` | `VARCHAR(150)` | NO | None | UI Group label (e.g. `'Neck design'`) |
| `is_required` | `TINYINT(1)` | NO | `0` | Required choice flag |
| `selection_type`| `ENUM('single_select','multi_select','toggle')` | NO | `'single_select'` | UI selection mode |
| `display_order`| `INT` | NO | `0` | Form presentation order |
| `is_active` | `TINYINT(1)` | NO | `1` | Active status |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_cg_cat_slug (category_id, slug)`
- `INDEX idx_cg_category (category_id)`

---

### 21. `customization_options`
**Purpose:** Selectable options per customization group replacing choices in `includes/demo-data.php::blouse_customization_options()`.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `group_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `customization_groups(id)` ON DELETE CASCADE |
| `value` | `VARCHAR(100)` | NO | None | Choice identifier (e.g. `'sweetheart'`, `'puff'`, `'yes'`) |
| `label` | `VARCHAR(150)` | NO | None | Display label (e.g. `'Sweetheart neck'`, `'Puff sleeve'`) |
| `price_delta` | `DECIMAL(10,2)` | NO | `0.00` | Price delta added to base price (Rs.) |
| `is_default` | `TINYINT(1)` | NO | `0` | Default preselected option |
| `display_order`| `INT` | NO | `0` | Presentation order |
| `is_active` | `TINYINT(1)` | NO | `1` | Active status |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_co_group_value (group_id, value)`
- `INDEX idx_co_group (group_id)`

---

### 22. `measurement_fields`
**Purpose:** Master definition of measurement dimensions for every garment silhouette (Blouse, Lehenga, Kurti, Salwar Suit, Anarkali, Gown). Enforces UI labels, default ordering, min/max sanity validation, and instructional guidance.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `category_id` | `BIGINT UNSIGNED` | NO | None | **FK** -> `garment_categories(id)` ON DELETE CASCADE |
| `measurement_key` | `VARCHAR(50)` | NO | None | Field key (e.g. `bust`, `waist`, `lehenga_length`, `flair`) |
| `label` | `VARCHAR(100)` | NO | None | UI label (e.g. `'Bust Circumference'`, `'Apex Point'`) |
| `instruction_hint` | `VARCHAR(255)` | YES | NULL | Tailoring instruction on how to measure |
| `min_value` | `DECIMAL(5,2)` | NO | `5.00` | Sanity lower bound (inches) |
| `max_value` | `DECIMAL(5,2)` | NO | `80.00`| Sanity upper bound (inches) |
| `is_required` | `TINYINT(1)` | NO | `1` | Whether required for tailoring completion |
| `display_order`| `INT` | NO | `0` | Sort order on Master Tailor desk |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_mf_cat_key (category_id, measurement_key)`
- `INDEX idx_mf_category (category_id)`

---

### 23. `embroidery_designs`
**Purpose:** Machine and Hand embroidery designs replacing `luxe_machine_work_designs()` and `luxe_hand_work_designs()` in `includes/demo-data.php`.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `code` | `VARCHAR(50)` | NO | None | **UNIQUE KEY `uk_ed_code`** (e.g. `'M-018'`, `'H-012'`) |
| `category` | `ENUM('machine','hand')` | NO | None | Embroidery craft type |
| `name` | `VARCHAR(150)` | NO | None | Design name (e.g. 'Bridal Motif', 'Zari Floral') |
| `description` | `TEXT` | YES | NULL | Craft description, thread types, motifs |
| `base_price` | `DECIMAL(10,2)` | NO | `0.00` | Standard work price (Rs.) |
| `image` | `VARCHAR(255)` | YES | NULL | Swatch image URL |
| `tags` | `VARCHAR(255)` | YES | NULL | Search tags (e.g. 'zari, bridal, heavy, border') |
| `is_featured` | `TINYINT(1)` | NO | `0` | Featured design flag |
| `is_active` | `TINYINT(1)` | NO | `1` | Active catalogue status |
| `display_order`| `INT` | NO | `0` | Display sorting |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_ed_code (code)`
- `INDEX idx_ed_category (category)`
- `INDEX idx_ed_active (is_active)`

---

### 24. `work_placements`
**Purpose:** Standard embroidery placement locations replacing `includes/demo-data.php::luxe_work_placements()`.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `name` | `VARCHAR(100)` | NO | None | **UNIQUE KEY `uk_wp_name`** (e.g. 'Neck', 'Sleeve', 'Back', 'All Over', 'Custom') |
| `description` | `VARCHAR(255)` | YES | NULL | Placement description |
| `surcharge_percentage` | `DECIMAL(5,2)` | NO | `0.00` | Extra surcharge percentage if applicable |
| `is_active` | `TINYINT(1)` | NO | `1` | Active status |
| `display_order`| `INT` | NO | `0` | Sort order |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_wp_name (name)`

---

### 25. `admin_users`
**Purpose:** Credentials, administrative permissions, and roles for the future Admin Dashboard. **Passwords are strictly stored as secure hashes, never plaintext.**

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `username` | `VARCHAR(100)` | NO | None | **UNIQUE KEY `uk_au_username`** |
| `email` | `VARCHAR(191)` | NO | None | **UNIQUE KEY `uk_au_email`** |
| `password_hash` | `VARCHAR(255)` | NO | None | Cryptographic password hash (Bcrypt / Argon2id) |
| `full_name` | `VARCHAR(150)` | NO | None | Staff full name |
| `role` | `ENUM('super_admin','master_tailor','store_manager')` | NO | `'store_manager'` | Role-based authorization tier |
| `phone` | `VARCHAR(30)` | YES | NULL | Staff contact phone |
| `is_active` | `TINYINT(1)` | NO | `1` | Active login status |
| `last_login_at`| `DATETIME` | YES | NULL | Last login timestamp |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_au_username (username)`
- `UNIQUE KEY uk_au_email (email)`

---

### 26. `shop_settings`
**Purpose:** Central store configuration replacing hardcoded values (eliminating hardcoded shop address, phone, GST number, advance percentages, default lead times).

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `setting_key` | `VARCHAR(100)` | NO | None | **UNIQUE KEY `uk_ss_key`** |
| `setting_value`| `TEXT` | NO | None | Serialized or plaintext setting value |
| `setting_group`| `ENUM('general','tailoring','payment','contact','notification')` | NO | `'general'` | Configuration group |
| `description` | `VARCHAR(255)` | YES | NULL | Setting explanation |
| `is_public` | `TINYINT(1)` | NO | `0` | Exposable to frontend client if 1 |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP ON UPDATE` | Last modification timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `UNIQUE KEY uk_ss_key (setting_key)`
- `INDEX idx_ss_group (setting_group)`

---

### 27. `activity_logs`
**Purpose:** Immutable audit trail logging admin actions, status changes, date modifications, and system events for operational traceability.

| Column | Data Type | Nullable | Default | Constraints / Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | Auto Increment | **PRIMARY KEY** |
| `admin_user_id`| `BIGINT UNSIGNED` | YES | NULL | **FK** -> `admin_users(id)` ON DELETE SET NULL |
| `user_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `users(id)` ON DELETE SET NULL |
| `order_id` | `BIGINT UNSIGNED` | YES | NULL | **FK** -> `orders(id)` ON DELETE SET NULL |
| `action_type` | `VARCHAR(100)` | NO | None | Event name (e.g. `'date_updated'`, `'order_placed'`) |
| `details` | `TEXT` | YES | NULL | Structured JSON or text details of the action |
| `ip_address` | `VARCHAR(45)` | YES | NULL | Client IP address |
| `user_agent` | `VARCHAR(255)` | YES | NULL | User-Agent string |
| `created_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | Log event timestamp |

*Indexes:*
- `PRIMARY KEY (id)`
- `INDEX idx_al_admin (admin_user_id)`
- `INDEX idx_al_order (order_id)`
- `INDEX idx_al_action (action_type)`
- `INDEX idx_al_created (created_at)`

---

## Architectural Deep-Dives

### 11. Standard vs Luxe Behavior
- **Unified Storage**: Both Standard and Luxe orders reside in the same `orders` table, distinguished by `workflow_type` (`'standard'`, `'wedding'`, `'family'`, or `'bulk'`).
- **People Handling**:
  - **Standard Stitching**: Creates a single entry in `order_people` with `name = Customer Name`, `role = 'Customer'`, and `person_order_index = 1`. All items from the cart map to this person.
  - **Luxe Stitching**: Creates one row in `order_people` for each member configured in `people.php` (e.g. Person 1: Ramya - Bride; Person 2: Gunjan - Sister). Garments assigned in `garments.php` map directly to their respective `order_people` row.
- **Workflow Integrity**: No application redesign or database bifurcation is required when customers switch between Standard and Luxe.

### 12. Physical Garment Handling
- **The Core Rule**: **ONE PHYSICAL GARMENT = ONE RECORD IN `order_garments`**.
- **No Quantity Aggregation**: If the Mother requests 3 blouses, the database creates:
  - Row 1: `order_garments` (Mother, Blouse, `garment_index = 1`, `style_slug = 'u-cut'`, `total_price = 1290`)
  - Row 2: `order_garments` (Mother, Blouse, `garment_index = 2`, `style_slug = 'princess-cut'`, `total_price = 1450`)
  - Row 3: `order_garments` (Mother, Blouse, `garment_index = 3`, `style_slug = 'katori-cut'`, `total_price = 1600`)
- **Independent Life Cycles**: Each physical garment moves independently through production stages (`'cutting'`, `'embroidery'`, `'stitching'`, `'finishing'`, `'ready'`). If Blouse #1 finishes early while Blouse #2 is undergoing hand embroidery, their statuses remain independent.

### 13. Measurement Architecture (Multi-Garment Bespoke Scaling)
- **Online Customer Experience**: Customers do not enter numerical measurements online. The customer-facing choices remain strictly:
  1. `reference_blouse` (Customer will submit an existing well-fitting blouse)
  2. `visit_shop` (Customer will visit the Shagun workshop in Bengaluru)
- **Master Tailor Bespoke Fit Data**:
  - Stored in `order_garment_measurement_values` backed by catalogue definitions in `measurement_fields`.
  - When the atelier expands beyond blouses to **Lehenga**, **Kurti**, **Salwar Suit**, **Anarkali**, and **Gown**, no database schema migration or table creation is needed.
  - Each silhouette uses its respective standard measurement points:
    - **Blouse**: `bust`, `upper_chest`, `under_bust`, `waist`, `shoulder`, `front_neck_depth`, `back_neck_depth`, `apex_point`, `armhole`, `sleeve_length`, `sleeve_round`, `blouse_length`.
    - **Lehenga**: `waist`, `hip`, `lehenga_length`, `flair_circumference`.
    - **Kurti & Top**: `bust`, `waist`, `hip`, `shoulder`, `armhole`, `sleeve_length`, `kurti_length`, `slit_position`.
    - **Salwar Suit**: `bust`, `waist`, `hip`, `kurti_length`, `bottom_length`, `thigh_round`, `bottom_opening`.
    - **Anarkali**: `bust`, `waist`, `yoke_length`, `anarkali_total_length`, `armhole`, `sleeve_length`, `ghera`.
    - **Gown**: `bust`, `waist`, `hip`, `front_neck_depth`, `back_neck_depth`, `floor_length`, `train_length`.
- **Validation & Bounds**: `measurement_fields` enforces minimum and maximum bounds (e.g. Bust: 28in–56in), preventing transcription errors by atelier fitters.

### 14. Work / Embroidery Architecture
- **The 4 Work Types**: `no_work`, `machine`, `hand`, `both`.
- **Normalized Multi-Work Support**: Handled via `order_garment_work_items`:
  - If `no_work`: 0 rows in `order_garment_work_items`.
  - If `machine`: 1 row (`work_category = 'machine'`, `design_code = 'M-018'`, `placement = 'Neck & Sleeves'`, `price = 250`).
  - If `hand`: 1 row (`work_category = 'hand'`, `design_code = 'H-012'`, `placement = 'Neck'`, `price = 800`).
  - If `both`: **2 rows linked to the SAME physical garment** (`order_garments.id`):
    - Row A: `work_category = 'machine'`, `design_code = 'M-018'`, `price = 250`
    - Row B: `work_category = 'hand'`, `design_code = 'H-012'`, `price = 800`
- **Result**: No artificial duplication of garments; clean financial aggregation (`work_total = 1050.00`).

### 15. Material & Photo Architecture (Disaggregated Physical Tracking)
- **One Physical Item = One Record**:
  - When the customer provides a physical Reference Blouse, it is entered once in `order_materials` with a unique identifier (`material_code = 'MAT-LT20261025-01'`) and assigned storage bin (`storage_bin_location = 'BIN-A14'`).
  - When that Reference Blouse serves as the fitting template for Blouse #1, Blouse #2, and Blouse #3, three mapping rows are created in `order_garment_material_links`:
    - Link 1: Blouse #1 ↔ `MAT-01` (`usage_role = 'reference_sample'`)
    - Link 2: Blouse #2 ↔ `MAT-01` (`usage_role = 'reference_sample'`)
    - Link 3: Blouse #3 ↔ `MAT-01` (`usage_role = 'reference_sample'`)
- **Admin Dashboard Answering Capability**:
  1. *What physical materials have arrived?* Query `order_materials` WHERE `status IN ('received', 'inspected', 'in_use')`.
  2. *Which garment(s) do they belong to?* Query `order_garment_material_links` JOIN `order_garments` on `order_material_id`.
  3. *Has the material been used?* Checked via `order_materials.status = 'in_use'` and `used_date`.
  4. *Has it been returned?* Checked via `order_materials.status = 'returned'` and `returned_date`.
  5. *Where is the Admin photograph?* Query `material_photos` JOIN `order_materials` on `order_material_id`.
- **Order Dossier Preservation**:
  - The Order Dossier PDF engine (`includes/dossier-pdf.php`) iterates through each physical garment, retrieves its mapped materials via `order_garment_material_links`, and renders the 3-panel status strip (Customer Fabric, Reference Blouse, Lace/Trims) with accurate physical status and grayscale photos.

### 16. Multi-Payment Lifecycle
- **Running Summary vs Transaction Audit**:
  - `orders` stores summary balances: `total_amount`, `advance_amount`, `balance_amount`, and `payment_status`.
  - `order_payments` stores every individual transaction with `payment_stage` (`'advance'`, `'second'`, `'final'`), `amount`, `payment_method`, `transaction_ref`, and `status`.
- **Simulation Safety**: `gateway_mode` is set to `'simulated'` by default, accurately reflecting current prototype behaviour while providing all columns needed for live Razorpay/Cashfree webhook verification.

### 17. Date & History Architecture
- **Key Dates Stored**:
  - `booked_date`: Exact server-side booking date when advance was paid.
  - `requested_ready_date`: Customer's desired ready date selected during checkout.
  - `admin_delivery_date`: Master Tailor / Shagun committed delivery date.
- **Audit Immutability**:
  - Customer requested date cannot be altered post-payment.
  - Admin changes write to `order_date_history`, recording `old_date`, `new_date`, `actor = 'admin'`, `admin_user_id`, and `note`.
  - The chronological history displayed in `orders.php` and `dossier-pdf.php` queries `order_date_history` ordered by `created_at ASC`.

### 18. Dossier & Document Architecture
- **Official Documents Supported**:
  - **Order Dossier**: Generated immediately upon advance payment confirmation.
  - **Completion Dossier**: Generated upon final tailoring inspection prior to collection.
  - **Invoices & Receipts**: Tax receipts generated upon full settlement.
- **Verification & Integrity**: Each generated PDF is registered in `order_documents` with version number, relative path, file size, and SHA-256 checksum to prevent document tampering.

### 19. Catalogue Transition Strategy: From Prototype to Authoritative Database

The transition from hardcoded arrays in `includes/demo-data.php` to MySQL will follow a strict three-phase migration:

1. **Phase 1: Seed & Verification (Current)**
   - Database tables `garment_categories`, `garment_styles`, `customization_groups`, `customization_options`, `embroidery_designs`, `work_placements`, and `measurement_fields` are populated using an automated seed script derived from `demo-data.php`.
2. **Phase 2: Authoritative Database with Controlled Local Fallback**
   - The application introduces a central data repository / service layer (`includes/db.php` & `includes/catalogue.php`).
   - The database is **strictly authoritative**. When prices or styles are updated via the Admin Dashboard, the changes take immediate effect.
   - **No Silent Price Substitution in Production**:
     - In production (`APP_ENV === 'production'`), if the MySQL database is unreachable, the system must throw a clean service error (HTTP 503) and log the incident. It must **never** silently fall back to stale hardcoded PHP files, which could cause customer orders to be billed at outdated prices.
     - In local development (`APP_ENV === 'local'`), an explicit fallback flag (`ENABLE_DEMO_DATA_FALLBACK = true`) can be enabled strictly for offline UI development, writing a prominent warning to the development error log.
3. **Phase 3: Complete Deprecation & Archival**
   - Once all catalogue and order flows are fully grounded in the database, `includes/demo-data.php` is deprecated and retained solely as a test/fixture baseline.

### 20. Admin Dashboard Roles & Security Architecture

The `admin_users` table establishes clear Role-Based Access Control (RBAC) across three distinct tiers:

| Role | Permitted Actions & Modules | Explicit Restrictions |
|---|---|---|
| **Super Admin** | • Complete system configuration & shop settings (`shop_settings`)<br>• Create, update, and deactivate staff logins (`admin_users`)<br>• Catalogue & price editing (`garment_styles`, `customization_options`, `embroidery_designs`)<br>• Financial reconciliations, refunds, and cash collections<br>• View all activity & security logs (`activity_logs`)<br>• Order cancellation and deletion overrides | None. Full administrative privileges. |
| **Master Tailor** | • Measurements desk: record, edit, and approve body fit dimensions (`order_garment_measurement_values`)<br>• Atelier production stages: update garment progress (Cutting → Embroidery → Stitching → Finishing → Quality Check)<br>• Assign artisan / karigar tasks and embroidery specs (`order_garment_work_items`)<br>• Material quality inspection (log defect notes and fabric condition) | Cannot change product prices, cannot modify store bank/payment settings, cannot create/delete admin users, cannot cancel orders. |
| **Store Manager** | • Customer intake and front-desk order booking<br>• Payment recording: log cash / card balance payments at pickup (`order_payments`)<br>• Material intake desk: check in physical fabrics/reference blouses, assign storage bin tags (`order_materials`), take Admin photos<br>• Date management: update estimated delivery dates with customer notes (`order_date_history`)<br>• Generate and download Order Dossiers and Completion Dossiers (`order_documents`) | Cannot edit Master Tailor technical body measurements, cannot modify catalogue base prices, cannot delete activity logs. |

#### Cryptographic Password Security
- **Strict Prohibition of Plaintext**: Plaintext passwords are never stored, logged, or exposed in sessions or API payloads.
- **Algorithm**: Standardized on PHP `password_hash($password, PASSWORD_DEFAULT)`, utilizing Bcrypt with cost factor 12 (or Argon2id where supported by the PHP runtime).
- **Verification**: Authenticated strictly via `password_verify($input, $hash)` with automatic rehashing upon login via `password_needs_rehash()`.

---

## Phase 2 CMS Tables (Separated from Core)

CMS-specific tables are separated from the core transactional order database and documented for Phase 2 implementation:

1. `gallery_albums` & `gallery_images`: Curated lookbooks, real-bride albums, and atelier portfolio.
2. `promotions_coupons`: Seasonal festive discount codes, referral credits, and wedding package coupons.
3. `blog_articles` & `blog_categories`: Bridal fashion guides, blouse styling tips, embroidery care guides.
4. `customer_testimonials`: Verified customer reviews with bride photos and rating stars.

---

## Summary Confirmation

- **Deliverable File:** `database/DATABASE_PLAN.md` updated in project root.
- **SQL Executed:** **ZERO** (No `CREATE TABLE`, `DROP TABLE`, or `ALTER DATABASE` executed).
- **Application Files Modified:** **ZERO** (Existing customer-facing PHP workflows, auth, checkout, cart, and dossier engines remain completely untouched).
- **Total Tables Designed:** **27 tables** (17 Core Order & Lifecycle tables, 7 Catalogue Master tables, 3 Admin & Audit tables) + 4 designated Phase 2 CMS tables.

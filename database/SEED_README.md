# Shagun Ladies Tailor — Database Seed Data Guide
**Target Database:** `shagun_ladies_tailor`  
**Seed File:** [`database/seed.sql`](file:///c:/xampp/htdocs/shagun-ladies-tailor/database/seed.sql)  
**Schema File:** [`database/schema.sql`](file:///c:/xampp/htdocs/shagun-ladies-tailor/database/schema.sql)  
**Specification Document:** [`database/DATABASE_PLAN.md`](file:///c:/xampp/htdocs/shagun-ladies-tailor/database/DATABASE_PLAN.md)  
**Environment:** XAMPP (MariaDB 10.4.32 / MySQL 8.0+, PHP 8.2.12, phpMyAdmin 5.2.1)

---

## 1. What `seed.sql` Contains

`database/seed.sql` populates the initial **Master Data and Catalogue Configuration** derived directly from the application source code (`includes/demo-data.php`, `includes/config.php`, `includes/dossier-pdf.php`):

1. **Shop Settings (`shop_settings`)**:
   - Official atelier title, tagline, and registered address: `Velankanni Road, Electronic City Phase 1, Bengaluru - 560100, Karnataka, India`.
   - Contact points: Phone/WhatsApp `+91 7019179423`, email `orders@shagunladiestailor.com`.
   - Tailoring & payment policies: minimum advance (30%), default advance (50%), max advance (100%), standard turnaround buffer (7 days).
2. **Garment Categories (`garment_categories`)**:
   - 7 canonical categories: Blouse Stitching (active / available), plus Lehenga, Kurti & Top, Salwar Suit, Anarkali, Gown, and Alterations (marked as coming-soon with `is_available = 0`).
3. **Garment Styles (`garment_styles`)**:
   - 10 bespoke blouse styles (U-Cut, Princess Cut, Katori Cut, Panel Cut, Boat Neck, High Neck, V-Neck, Back Open / Deep Back, Basic / Traditional, and Designer) with exact base prices (₹550 to ₹1,100) and lead times.
4. **Customization Groups (`customization_groups`)**:
   - 9 feature groups for blouses: Neck design, Sleeve style, Sleeve length, Lining, Cups, Piping, Back design, Finishing, and Embroidery / work.
5. **Customization Options (`customization_options`)**:
   - 28 specific choices with their price deltas, preserving all ₹0 baseline selections as legitimate catalogue records.
6. **Embroidery Designs (`embroidery_designs`)**:
   - 8 artisan embroidery designs across Machine Work (M-024 Bridal Motif, M-018 Paisley Border, M-031 Floral Jal, M-045 Geometrical Zari) and Hand Work (H-012 Zari Floral, H-007 Heavy Maggam / Aari Work, H-023 Pearl & Zardosi, H-034 Thread & Mirror Work) with exact prices (₹250 to ₹1,200) and swatches.
7. **Work Placements (`work_placements`)**:
   - 5 standard embroidery placements: Neck, Sleeve, Back, All Over, and Custom.
8. **Measurement Fields (`measurement_fields`)**:
   - 13 standardized bespoke measurement points for blouses (Bust, Upper Chest, Under Bust, Waist, Shoulder, Front Neck Depth, Back Neck Depth, Apex Point, Cross Back, Armhole, Sleeve Length, Sleeve Round, Blouse Finished Length) with min/max inch sanity bounds for future Master Tailor entry.

---

## 2. What `seed.sql` Intentionally Does NOT Contain

To ensure production integrity and zero data contamination, `seed.sql` strictly excludes:
- **No Mock Customer Accounts**: Zero rows in `users` or `customer_addresses`.
- **No Fake Family Members**: Zero rows in `customer_family_members`.
- **No Fake Orders or Garments**: Zero rows in `orders`, `order_people`, or `order_garments`.
- **No Mock Payments**: Zero rows in `order_payments`.
- **No Fake Materials or Photos**: Zero rows in `order_materials`, `order_garment_material_links`, or `material_photos`.
- **No Mock Measurements**: Zero customer numeric measurements (numeric measurements remain Admin-controlled upon physical consultation).
- **No Plaintext Passwords**: Zero rows in `admin_users`.

---

## 3. Seed Record Count by Table

| # | Table Name | Category | Records Seeded | Primary Source in Codebase |
|---|---|---|:---:|---|
| 1 | `shop_settings` | Configuration | 16 | `includes/config.php`, `includes/dossier-pdf.php` |
| 2 | `garment_categories` | Catalogue | 7 | `includes/demo-data.php::standard_stitching_categories()` |
| 3 | `garment_styles` | Catalogue | 10 | `includes/demo-data.php::blouse_styles()` |
| 4 | `customization_groups` | Catalogue | 9 | `includes/demo-data.php::blouse_customization_options()` |
| 5 | `customization_options` | Catalogue | 28 | `includes/demo-data.php::blouse_customization_options()` |
| 6 | `embroidery_designs` | Catalogue | 8 | `includes/demo-data.php::luxe_machine/hand_work_designs()` |
| 7 | `work_placements` | Catalogue | 5 | `includes/demo-data.php::luxe_work_placements()` |
| 8 | `measurement_fields` | Catalogue / Fit | 13 | `database/DATABASE_PLAN.md` (Bespoke Blouse points) |
| — | **Total Master Records** | | **96** | *(All remaining 19 transactional tables have 0 rows)* |

---

## 4. Duplicate Prevention & Idempotency

`seed.sql` uses `INSERT INTO ... ON DUPLICATE KEY UPDATE` across every statement:
- **Safe Re-runs**: Executing `seed.sql` multiple times will update existing catalogue titles, descriptions, and prices without inserting duplicates or throwing primary/unique key errors.
- **Dynamic Foreign Key Resolution**: Uses scoped SQL session variables (`@cat_blouse_id`, `@grp_neck_id`, etc.) querying natural unique slugs, ensuring foreign keys resolve accurately regardless of auto-increment offsets.

---

## 5. How to Import `seed.sql` into phpMyAdmin

> [!IMPORTANT]
> Always import `database/schema.sql` **BEFORE** importing `database/seed.sql`.

### Via phpMyAdmin
1. Open `http://localhost/phpmyadmin/` in your browser.
2. Select the **`shagun_ladies_tailor`** database in the left sidebar.
3. Verify that all 27 tables exist (imported via `schema.sql`).
4. Click on the **Import** tab.
5. Click **Choose File** and select:  
   `C:\xampp\htdocs\shagun-ladies-tailor\database\seed.sql`
6. Click **Import** (or **Go**).
7. phpMyAdmin will report successful insertion/updates across the 8 master tables (96 total records).

### Via MySQL Shell
```bash
mysql -u root -p shagun_ladies_tailor < "C:\xampp\htdocs\shagun-ladies-tailor\database\seed.sql"
```

---

## 6. How Admin Accounts Will Be Created Securely

In accordance with security best practices, `seed.sql` leaves `admin_users` completely empty.

During the upcoming Admin Authentication implementation phase, the initial Super Admin account will be provisioned using one of two secure mechanisms:
1. **Interactive CLI Setup Script (`bin/create-admin.php`)**:
   - Prompts for username, email, full name, role, and password via terminal.
   - Computes the hash using `password_hash($password, PASSWORD_DEFAULT)` (Bcrypt cost 12 or Argon2id).
   - Inserts the sanitized record directly into `admin_users`.
2. **First-Run Web Setup Wizard (`admin/install.php`)**:
   - Checks if `admin_users` has 0 rows.
   - Prompts for initial Super Admin setup with immediate session lock and self-deletion upon completion.

---

## 7. Safety Confirmations

- **No SQL Executed**: Zero queries have been run against MySQL.
- **Database Status**: The live database `shagun_ladies_tailor` remains completely untouched (0 tables, 0 records).
- **Zero Application Modifications**: Existing application code, customer-facing workflows, cart sessions, and tests remain 100% operational.

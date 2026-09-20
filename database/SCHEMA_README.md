# Shagun Ladies Tailor — Database Schema Guide
**Target Database:** `shagun_ladies_tailor`  
**Schema File:** [`database/schema.sql`](file:///c:/xampp/htdocs/shagun-ladies-tailor/database/schema.sql)  
**Specification Document:** [`database/DATABASE_PLAN.md`](file:///c:/xampp/htdocs/shagun-ladies-tailor/database/DATABASE_PLAN.md)  
**Environment:** XAMPP (MariaDB 10.4.32 / MySQL 8.0+, PHP 8.2.12, phpMyAdmin 5.2.1)  
**Storage Engine:** `InnoDB` | **Collation:** `utf8mb4_unicode_ci`

---

## 1. Overview & Table Summary

The schema file `database/schema.sql` defines exactly **27 relational tables** organized into three distinct tiers:

| Category | Table Count | Tables |
|---|:---:|---|
| **Core Order & Lifecycle** | 17 | `users`, `customer_addresses`, `customer_family_members`, `orders`, `order_people`, `order_garments`, `order_garment_customizations`, `order_garment_work_items`, `order_person_measurements`, `order_garment_measurement_values`, `order_materials`, `order_garment_material_links`, `material_photos`, `order_payments`, `order_date_history`, `order_status_history`, `order_documents` |
| **Catalogue Master Data** | 7 | `garment_categories`, `garment_styles`, `customization_groups`, `customization_options`, `measurement_fields`, `embroidery_designs`, `work_placements` |
| **Administration & System** | 3 | `admin_users`, `shop_settings`, `activity_logs` |
| **Total Phase 1 Tables** | **27** | *(Phase 2 CMS tables are kept separate and not generated in this step)* |

---

## 2. Core Architectural Guarantees

1. **Mandatory Authenticated Customers**:
   - Finalized orders require an authenticated customer account in `users` (`orders.user_id` has an enforced foreign key with `ON DELETE RESTRICT`).
   - Guest accounts are excluded from the order core.
2. **Strict Physical Garment Rule**:
   - **1 physical garment = 1 row in `order_garments`**.
   - If a person orders 3 blouses, they are tracked as 3 independent records with independent tailoring states, styles, and pricing.
3. **Physical Material Disaggregation**:
   - A physical item provided by a customer (e.g. 1 physical Reference Blouse) is entered once in `order_materials` with an atelier tag and storage bin.
   - It is mapped to 1 or more garments via `order_garment_material_links` (`usage_role = 'reference_sample'`).
4. **Extensible Garment-Specific Measurements**:
   - Customer-facing method (`reference_blouse` vs `visit_shop`) is stored in `order_person_measurements`.
   - In-shop Master Tailor numeric measurements are stored in `order_garment_measurement_values` backed by catalogue definitions in `measurement_fields` for all silhouettes (Blouse, Lehenga, Kurti, Salwar Suit, Anarkali, Gown).
5. **Multi-Payment Ledger**:
   - `order_payments` captures each payment instalment (Advance, Second, Final) with transaction references, retaining simulated gateway modes for the prototype while supporting live webhooks.
6. **Immutable Date Audit Log**:
   - Customer requested ready dates are locked post-advance; all admin delivery date adjustments are appended to `order_date_history`.
7. **Document Registry**:
   - Versioned generated Order Dossiers and Completion Dossiers are indexed in `order_documents`.
8. **Cryptographic Admin Security**:
   - `admin_users` stores cryptographic password hashes (`PASSWORD_DEFAULT` / Bcrypt / Argon2id), never plaintext.

---

## 3. Major Entity Relationships

```
 users (Authenticated Customers)
   ├── customer_addresses
   ├── customer_family_members
   └── orders (workflow_type: 'standard' | 'wedding' | 'family' | 'bulk')
        ├── order_people (Customer, Bride, Mother, Sister, Groom...)
        │    └── order_person_measurements (reference_blouse / visit_shop)
        │         └── order_garment_measurement_values (bust, waist, armhole, length...)
        │
        ├── order_garments (ONE ROW PER PHYSICAL PIECE)
        │    ├── order_garment_customizations (neck, sleeves, lining...)
        │    ├── order_garment_work_items (machine, hand, or both)
        │    └── order_garment_material_links ──┐
        │                                        │
        ├── order_materials <────────────────────┘ (1 physical item = 1 row)
        │    └── material_photos (color admin + grayscale dossier)
        │
        ├── order_payments (advance, second, final)
        ├── order_date_history (requested vs admin delivery changes)
        ├── order_status_history (status progression)
        └── order_documents (Order Dossier & Completion Dossier PDFs)
```

---

## 4. Foreign Key Dependency Creation Order

`schema.sql` creates tables in the following strict dependency sequence so that all foreign key references are resolved cleanly:

1. **Independent Masters**: `users`, `admin_users`, `shop_settings`, `garment_categories`, `embroidery_designs`, `work_placements`
2. **Category & User Dependents**: `customer_addresses`, `customer_family_members`, `garment_styles`, `customization_groups`, `measurement_fields`
3. **Sub-Catalogue & Orders**: `customization_options`, `orders`
4. **Order Sub-entities**: `order_people`, `order_materials`, `order_payments`, `order_date_history`, `order_status_history`, `order_documents`, `activity_logs`
5. **Garments & Photos**: `material_photos`, `order_person_measurements`, `order_garments`
6. **Garment Children & Linkages**: `order_garment_customizations`, `order_garment_work_items`, `order_garment_material_links`, `order_garment_measurement_values`

Total Foreign Keys: **34 constraints** across all tables.

---

## 5. How to Import `schema.sql` into phpMyAdmin

> [!NOTE]
> `schema.sql` is a pure SQL script and **does not execute automatically**. The live database `shagun_ladies_tailor` remains empty until manually imported or executed via migration scripts.

To import via phpMyAdmin:
1. Open your browser and navigate to **phpMyAdmin**: `http://localhost/phpmyadmin/`
2. In the left navigation panel, click on the **`shagun_ladies_tailor`** database.
3. Click on the **Import** tab in the top navigation bar.
4. Click **Choose File** (or Browse) and select:  
   `C:\xampp\htdocs\shagun-ladies-tailor\database\schema.sql`
5. Ensure the character set is set to **utf-8**.
6. Scroll down and click **Import** (or **Go**).
7. phpMyAdmin will execute the DDL statements and display 27 newly created tables under `shagun_ladies_tailor`.

Alternatively, using the MySQL CLI from the XAMPP Shell:
```bash
mysql -u root -p shagun_ladies_tailor < "C:\xampp\htdocs\shagun-ladies-tailor\database\schema.sql"
```

---

## 6. Safety Confirmations

- **No SQL Executed**: No queries have been executed against MySQL. The live database remains completely empty (0 tables).
- **Non-Destructive**: `schema.sql` contains zero `DROP TABLE`, `DROP DATABASE`, `TRUNCATE`, or `DELETE` statements.
- **No Secrets**: Contains no hardcoded passwords, API keys, or live credentials.
- **Zero Application Modifications**: Existing application code, customer-facing workflows, and session handlers remain 100% untouched.

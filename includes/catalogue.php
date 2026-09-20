<?php

declare(strict_types=1);

/**
 * Shagun Ladies Tailor — Database-Backed Catalogue Data Access Layer
 * 
 * Provides centralized, read-only data access to the MySQL/MariaDB catalogue tables:
 * - garment_categories
 * - garment_styles
 * - customization_groups & customization_options
 * - embroidery_designs
 * - work_placements
 * - measurement_fields
 * - shop_settings
 * 
 * Architecture Guarantees:
 * - Uses PDO prepared statements via includes/db.php.
 * - Centralized queries; never interpolates unescaped user input into SQL.
 * - Zero write operations.
 * - Exact array shape and data type compatibility with previous demo-data.php return shapes.
 * - Controlled local development fallback (logged warning) vs strict production service failure.
 */

require_once __DIR__ . '/db.php';

/**
 * Centralized failure handler for catalogue operations.
 *
 * In production: throws a RuntimeException (HTTP 503) — never silently uses stale prices.
 * In local dev: logs a prominent warning and calls the demo fallback function if available.
 *
 * @param string $operation Name of the catalogue operation.
 * @param Throwable $e The caught exception.
 * @param array $args Arguments to pass to the fallback function.
 * @return mixed
 * @throws RuntimeException In non-local environments.
 */
function catalogue_handle_failure(string $operation, Throwable $e, array $args = []): mixed
{
    $env = app_env('APP_ENV', 'local');
    $logMsg = sprintf('[CATALOGUE DB ERROR] Failed during %s: %s', $operation, $e->getMessage());
    error_log($logMsg);

    if ($env !== 'local') {
        throw new RuntimeException(
            sprintf('Catalogue database service unavailable during %s. Please check database service.', $operation),
            503,
            $e
        );
    }

    // In local development ONLY: log warning and invoke fallback if defined
    error_log(sprintf('[CATALOGUE LOCAL FALLBACK] Database read failed for %s; using local demo data fallback.', $operation));

    $fallbackFunc = 'demo_data_fallback_' . $operation;
    if (function_exists($fallbackFunc)) {
        return $fallbackFunc(...$args);
    }

    throw $e;
}

/**
 * Retrieve all garment categories.
 * Matches shape of demo-data.php::standard_stitching_categories().
 *
 * @return array<int, array{name: string, slug: string, description: string, image: string, href?: string, available: bool}>
 */
function catalogue_garment_categories(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->query('
            SELECT `name`, `slug`, `description`, `image`, `target_route`, `is_available`
            FROM `garment_categories`
            ORDER BY `display_order` ASC, `id` ASC
        ');
        $rows = $stmt->fetchAll();

        $categories = [];
        foreach ($rows as $row) {
            $cat = [
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'description' => (string) ($row['description'] ?? ''),
                'image' => (string) ($row['image'] ?? ''),
                'available' => (bool) $row['is_available'],
            ];
            if (!empty($row['target_route'])) {
                $cat['href'] = (string) $row['target_route'];
            }
            $categories[] = $cat;
        }

        $cache = $categories;
        return $categories;
    } catch (Throwable $e) {
        return catalogue_handle_failure('standard_stitching_categories', $e);
    }
}

/**
 * Retrieve all active blouse styles.
 * Matches shape of demo-data.php::blouse_styles().
 *
 * @return array<int, array{slug: string, name: string, description: string, price: int, image: string}>
 */
function catalogue_blouse_styles(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->query("
            SELECT gs.`slug`, gs.`name`, gs.`description`, gs.`base_price`, gs.`image`
            FROM `garment_styles` gs
            INNER JOIN `garment_categories` gc ON gs.`category_id` = gc.`id`
            WHERE gc.`slug` = 'blouse' AND gs.`is_active` = 1
            ORDER BY gs.`display_order` ASC, gs.`id` ASC
        ");
        $rows = $stmt->fetchAll();

        $styles = [];
        foreach ($rows as $row) {
            $styles[] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'price' => (int) round((float) $row['base_price']),
                'image' => (string) ($row['image'] ?? ''),
            ];
        }

        $cache = $styles;
        return $styles;
    } catch (Throwable $e) {
        return catalogue_handle_failure('blouse_styles', $e);
    }
}

/**
 * Retrieve a specific blouse style by slug.
 * Matches shape of demo-data.php::blouse_style_by_slug().
 *
 * @param string $slug
 * @return array{slug: string, name: string, description: string, price: int, image: string}|null
 */
function catalogue_blouse_style_by_slug(string $slug): ?array
{
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }

    // Check cached styles first to minimize queries
    $allStyles = catalogue_blouse_styles();
    foreach ($allStyles as $style) {
        if ($style['slug'] === $slug) {
            return $style;
        }
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT gs.`slug`, gs.`name`, gs.`description`, gs.`base_price`, gs.`image`
            FROM `garment_styles` gs
            INNER JOIN `garment_categories` gc ON gs.`category_id` = gc.`id`
            WHERE gc.`slug` = 'blouse' AND gs.`slug` = :slug AND gs.`is_active` = 1
            LIMIT 1
        ");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'price' => (int) round((float) $row['base_price']),
            'image' => (string) ($row['image'] ?? ''),
        ];
    } catch (Throwable $e) {
        return catalogue_handle_failure('blouse_style_by_slug', $e, [$slug]);
    }
}

/**
 * Retrieve all blouse customization options.
 * Matches shape of demo-data.php::blouse_customization_options().
 *
 * @return array<string, array{label: string, required: bool, choices: array<int, array{value: string, label: string, price: int}>}>
 */
function catalogue_blouse_customization_options(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->query("
            SELECT 
                cg.`slug` AS group_slug,
                cg.`label` AS group_label,
                cg.`is_required`,
                co.`value` AS choice_value,
                co.`label` AS choice_label,
                co.`price_delta`
            FROM `customization_groups` cg
            INNER JOIN `garment_categories` gc ON cg.`category_id` = gc.`id`
            INNER JOIN `customization_options` co ON co.`group_id` = cg.`id`
            WHERE gc.`slug` = 'blouse' AND cg.`is_active` = 1 AND co.`is_active` = 1
            ORDER BY cg.`display_order` ASC, cg.`id` ASC, co.`display_order` ASC, co.`id` ASC
        ");
        $rows = $stmt->fetchAll();

        $options = [];
        foreach ($rows as $row) {
            $gSlug = (string) $row['group_slug'];
            if (!isset($options[$gSlug])) {
                $options[$gSlug] = [
                    'label' => (string) $row['group_label'],
                    'required' => (bool) $row['is_required'],
                    'choices' => [],
                ];
            }
            $options[$gSlug]['choices'][] = [
                'value' => (string) $row['choice_value'],
                'label' => (string) $row['choice_label'],
                'price' => (int) round((float) $row['price_delta']),
            ];
        }

        $cache = $options;
        return $options;
    } catch (Throwable $e) {
        return catalogue_handle_failure('blouse_customization_options', $e);
    }
}

/**
 * Retrieve a specific customization choice by field and value.
 * Matches shape of demo-data.php::demo_choice().
 *
 * @param string $field Group slug (e.g. 'neck_design')
 * @param string $value Choice value (e.g. 'sweetheart')
 * @return array{value: string, label: string, price: int}|null
 */
function catalogue_choice(string $field, string $value): ?array
{
    $field = trim($field);
    $value = trim($value);

    if ($field === '' || $value === '') {
        return null;
    }

    // Check cached options first
    $allOptions = catalogue_blouse_customization_options();
    if (isset($allOptions[$field]['choices'])) {
        foreach ($allOptions[$field]['choices'] as $choice) {
            if ($choice['value'] === $value) {
                return $choice;
            }
        }
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT co.`value`, co.`label`, co.`price_delta`
            FROM `customization_options` co
            INNER JOIN `customization_groups` cg ON co.`group_id` = cg.`id`
            INNER JOIN `garment_categories` gc ON cg.`category_id` = gc.`id`
            WHERE gc.`slug` = 'blouse' 
              AND cg.`slug` = :field 
              AND co.`value` = :value
              AND cg.`is_active` = 1 
              AND co.`is_active` = 1
            LIMIT 1
        ");
        $stmt->execute(['field' => $field, 'value' => $value]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'value' => (string) $row['value'],
            'label' => (string) $row['label'],
            'price' => (int) round((float) $row['price_delta']),
        ];
    } catch (Throwable $e) {
        return catalogue_handle_failure('demo_choice', $e, [$field, $value]);
    }
}

/**
 * Retrieve embroidery designs filtered by category ('machine' or 'hand').
 *
 * @param string|null $category 'machine', 'hand', or null for all.
 * @return array<int, array{code: string, name: string, price: int, image: string, description: string, category: string}>
 */
function catalogue_embroidery_designs(?string $category = null): array
{
    static $cache = [];
    $cacheKey = $category ?? 'all';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $pdo = get_db_connection();
        if ($category !== null) {
            $stmt = $pdo->prepare('
                SELECT `code`, `category`, `name`, `base_price`, `image`, `description`
                FROM `embroidery_designs`
                WHERE `category` = :category AND `is_active` = 1
                ORDER BY `display_order` ASC, `id` ASC
            ');
            $stmt->execute(['category' => $category]);
        } else {
            $stmt = $pdo->query('
                SELECT `code`, `category`, `name`, `base_price`, `image`, `description`
                FROM `embroidery_designs`
                WHERE `is_active` = 1
                ORDER BY `display_order` ASC, `id` ASC
            ');
        }
        $rows = $stmt->fetchAll();

        $designs = [];
        foreach ($rows as $row) {
            $designs[] = [
                'code' => (string) $row['code'],
                'category' => (string) $row['category'],
                'name' => (string) $row['name'],
                'price' => (int) round((float) $row['base_price']),
                'image' => (string) ($row['image'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
            ];
        }

        $cache[$cacheKey] = $designs;
        return $designs;
    } catch (Throwable $e) {
        $op = $category === 'machine' ? 'luxe_machine_work_designs' : ($category === 'hand' ? 'luxe_hand_work_designs' : 'embroidery_designs');
        return catalogue_handle_failure($op, $e);
    }
}

/**
 * Retrieve active Machine Work embroidery designs.
 * Matches shape of demo-data.php::luxe_machine_work_designs().
 *
 * @return array<int, array{code: string, name: string, price: int, image: string, description: string}>
 */
function catalogue_machine_work_designs(): array
{
    $designs = catalogue_embroidery_designs('machine');
    return array_map(function ($item) {
        unset($item['category']);
        return $item;
    }, $designs);
}

/**
 * Retrieve active Hand Work embroidery designs.
 * Matches shape of demo-data.php::luxe_hand_work_designs().
 *
 * @return array<int, array{code: string, name: string, price: int, image: string, description: string}>
 */
function catalogue_hand_work_designs(): array
{
    $designs = catalogue_embroidery_designs('hand');
    return array_map(function ($item) {
        unset($item['category']);
        return $item;
    }, $designs);
}

/**
 * Retrieve standard work placement locations.
 * Matches shape of demo-data.php::luxe_work_placements().
 *
 * @return array<int, string>
 */
function catalogue_work_placements(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->query('
            SELECT `name`
            FROM `work_placements`
            WHERE `is_active` = 1
            ORDER BY `display_order` ASC, `id` ASC
        ');
        $placements = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $cache = $placements;
        return $placements;
    } catch (Throwable $e) {
        return catalogue_handle_failure('luxe_work_placements', $e);
    }
}

/**
 * Retrieve measurement field specifications for a given garment category.
 *
 * @param string $categorySlug
 * @return array<int, array{key: string, label: string, hint: string|null, min: float, max: float, required: bool}>
 */
function catalogue_measurement_fields(string $categorySlug = 'blouse'): array
{
    static $cache = [];
    if (isset($cache[$categorySlug])) {
        return $cache[$categorySlug];
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('
            SELECT mf.`measurement_key`, mf.`label`, mf.`instruction_hint`, mf.`min_value`, mf.`max_value`, mf.`is_required`
            FROM `measurement_fields` mf
            INNER JOIN `garment_categories` gc ON mf.`category_id` = gc.`id`
            WHERE gc.`slug` = :cat_slug
            ORDER BY mf.`display_order` ASC, mf.`id` ASC
        ');
        $stmt->execute(['cat_slug' => $categorySlug]);
        $rows = $stmt->fetchAll();

        $fields = [];
        foreach ($rows as $row) {
            $fields[] = [
                'key' => (string) $row['measurement_key'],
                'label' => (string) $row['label'],
                'hint' => $row['instruction_hint'] !== null ? (string) $row['instruction_hint'] : null,
                'min' => (float) $row['min_value'],
                'max' => (float) $row['max_value'],
                'required' => (bool) $row['is_required'],
            ];
        }

        $cache[$categorySlug] = $fields;
        return $fields;
    } catch (Throwable $e) {
        return catalogue_handle_failure('measurement_fields', $e, [$categorySlug]);
    }
}

/**
 * Retrieve all shop settings as a key-value map.
 *
 * @return array<string, string>
 */
function catalogue_shop_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->query('
            SELECT `setting_key`, `setting_value`
            FROM `shop_settings`
        ');
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $cache = $settings;
        return $settings;
    } catch (Throwable $e) {
        return catalogue_handle_failure('shop_settings', $e);
    }
}

/**
 * Retrieve a specific shop setting value.
 *
 * @param string $key
 * @param string|null $default
 * @return string|null
 */
function catalogue_shop_setting(string $key, ?string $default = null): ?string
{
    $settings = catalogue_shop_settings();
    return $settings[$key] ?? $default;
}

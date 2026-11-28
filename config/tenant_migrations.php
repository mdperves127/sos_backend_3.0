<?php

/**
 * Tenant migration path registry and table ownership catalog.
 *
 * IMPORTANT:
 * - Merchant and Dropshipper are TENANT TYPES (tenants.type), not separate databases.
 * - Both roles share one tenant schema today (database/migrations/tenant/*.php).
 * - Do NOT move already-executed migration files — Laravel records basenames in `migrations`.
 * - Put ONLY new role-scoped migrations under merchant/ or dropshipper/.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Migration paths
    |--------------------------------------------------------------------------
    */
    'paths' => [
        // Shared schema for every tenant (merchant + dropshipper). Existing files live here.
        'shared' => database_path( 'migrations/tenant' ),

        // Future merchant-only migrations (NOT loaded for dropshipper tenants).
        'merchant' => database_path( 'migrations/tenant/merchant' ),

        // Future dropshipper-only migrations (NOT loaded for merchant tenants).
        'dropshipper' => database_path( 'migrations/tenant/dropshipper' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Which extra paths run per tenants.type
    |--------------------------------------------------------------------------
    */
    'role_paths' => [
        'merchant'    => ['merchant'],
        'dropshipper' => ['dropshipper'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table ownership (usage-based, not filename-based)
    |--------------------------------------------------------------------------
    |
    | Shared = both roles read/write in their own tenant DB.
    | Merchant-primary = mainly written/managed by merchant tenants.
    | Dropshipper-primary = mainly written/managed by dropshipper tenants.
    | Central = lives on the platform/mysql connection.
    |
    | Note: merchant-primary tables still exist in the SHARED migration path so
    | both tenant DBs stay schema-compatible. Application code gates usage by type.
    |
    */
    'tables' => [

        'central' => [
            'tenants', // includes type + balance (wallet lives here; no wallets table)
            'domains',
            'subscriptions',
            'subscription_histories',
            'user_subscriptions',
            'recharges',
            'withdraws',
            'payment_histories', // central wallet ledger / platform fee rows
            'payment_stores', // platform wallet / subscription payments (also mirrored historically)
            'addons',
            'addon_features',
            'tenant_addons',
            'tenant_materials',
            'tenant_verifications',
            'seo',
            'settings', // platform settings (dropship platform fee, etc.)
            'mp_categories',
            'mp_sub_categories',
            'mp_brands',
            'permissions',
            'roles',
            'model_has_roles',
            'model_has_permissions',
            'role_has_permissions',
            'users', // platform/admin users when present on central
        ],

        'shared' => [
            'users',
            'password_resets',
            'personal_access_tokens',
            'permissions',
            'roles',
            'model_has_roles',
            'model_has_permissions',
            'role_has_permissions',
            'vendor_roles',
            'vendor_employees',
            'vendor_infos',
            'settings',
            'cms_settings',
            'media',
            'page_builders',
            'pages',
            'banners',
            'offers',
            'news',
            'n_categories',
            'notifications',
            'messages',
            'chats',
            'conversations',
            'chat_reports',
            'notes',
            'files',
            'countries',
            'states',
            'cities',
            // Schema shared; product/dropship ORDER ROWS are written on the merchant DB.
            // Dropshippers query merchant orders with orders.tenant_id = dropshipper id.
            'orders',
            'order_details',
            'order_variants',
            'order_deliveries',
            'order_delivery_to_couriers',
            'order_edit_histories',
            // Carts: dropshipper affiliate carts + merchant storefront carts (both roles).
            'carts',
            'cart_details',
            'wish_lists',
            'customers',
            'customer_payments',
            'customer_requiremnts',
            'coupons',
            'tenant_coupons',
            'tenant_coupon_usages',
            'coupon_requests',
            'delivery_charges',
            'delivery_companies',
            'delivery_and_pickup_addresses',
            'delivery_files',
            'courier_credentials',
            'payment_methods',
            'payment_histories',
            'payment_stores',
            'payment_gateway_settings',
            'advance_payments',
            'installments',
            'installment_payments',
            'banks',
            'woocommerce_credentials',
            'woocommerce_sync_logs',
            'tenant_contact_form_data',
        ],

        'merchant_primary' => [
            'products',
            'product_images',
            'product_variants',
            'product_purchases',
            'product_purchase_details',
            'product_bundles',
            'product_bundle_items',
            'product_pre_orders',
            'categories',
            'subcategories',
            'brands',
            'colors',
            'sizes',
            'units',
            'sub_units',
            'warehouses',
            'suppliers',
            'supplier_payments',
            'supplier_return_products',
            'specifications',
            'barcodes',
            'pos_sales',
            'pos_sales_details',
            'pos_sale_dues',
            'pos_sale_returns',
            'pos_sale_wastage_returns',
            'sales',
            'sales_details',
            'damages',
            'damage_details',
            // Written only when tenant()->type === merchant (table still migrated everywhere).
            'dropshipper_product_assignments',
            // Commission pending rows live with the order on the merchant DB.
            'pending_balances',
            'cancel_order_balances',
        ],

        'dropshipper_primary' => [
            // Listings/requests created in the dropshipper DB; tenant_id = merchant id.
            'product_details',
            'pending_products',
            'product_ratings',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage notes (from code analysis — not separate schemas)
    |--------------------------------------------------------------------------
    */
    'notes' => [
        'same_schema' => 'Merchant and dropshipper tenant DBs share database/migrations/tenant.',
        'wallet'      => 'No wallets table. Balance is tenants.balance; ledger is central payment_histories / withdraws.',
        'orders'      => 'Product checkout writes orders into the merchant (product) tenant DB.',
        'listings'    => 'Dropshipper catalog is product_details on the dropshipper DB.',
        'lag'         => 'Table-count differences between tenants are usually pending tenants:migrate, not role design.',
    ],
];

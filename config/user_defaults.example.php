<?php
/*
 * Local default vendor/account configuration template.
 *
 * Copy this file to:
 *
 *   config/user_defaults.php
 *
 * Then edit config/user_defaults.php for your own GnuCash vendor IDs,
 * accounts, stored-value accounts, and module settings.
 *
 * config/user_defaults.php is intentionally ignored by Git so it survives
 * updates, pulls, resets, and local development changes.
 */

return [
    // Vendor IDs
    'DEFAULT_VENDOR_AMAZON' => '',
    'DEFAULT_VENDOR_COSTCO' => '',
    'DEFAULT_VENDOR_WALMART' => '',
    'DEFAULT_VENDOR_LOWES' => '',
    'DEFAULT_VENDOR_HOME_DEPOT' => '',
    'DEFAULT_VENDOR_TRACTOR_SUPPLY' => '',

    // Bill export defaults
    'DEFAULT_AP_ACCOUNT' => 'Liabilities:Accounts Payable',
    'DEFAULT_TAX_ACCOUNT' => 'Expenses:Tax:Sales Tax',
    'DEFAULT_SHIPPING_ACCOUNT' => 'Expenses:Shipping',
    'DEFAULT_GIFT_WRAP_ACCOUNT' => 'Expenses:Gift Wrap',

    // Payment / stored-value accounts
    'DEFAULT_PAYMENT_ACCOUNT_AMAZON' => '',
    'DEFAULT_PAYMENT_ACCOUNT_HOME_DEPOT' => '',
    'DEFAULT_REWARDS_ACCOUNT_AMAZON' => '',
    'DEFAULT_GIFT_CARD_RETURNS_ACCOUNT_AMAZON' => '',
    'DEFAULT_PRIME_YOUNG_CASHBACK_ACCOUNT_AMAZON' => '',
    'DEFAULT_STORED_VALUE_OFFSET_ACCOUNT' => '',
    'DEFAULT_STORED_VALUE_ACCOUNT_WALMART' => '',
    'DEFAULT_STORED_VALUE_ACCOUNT_COSTCO' => '',
    'DEFAULT_STORED_VALUE_ACCOUNT_LOWES' => '',
    'DEFAULT_STORED_VALUE_ACCOUNT_TRACTOR_SUPPLY' => '',

    // Module settings
    'DEFAULT_LOWES_PAYMENT_MATCH_DATE_WINDOW_DAYS' => '14',
    'DEFAULT_LOWES_PARTIAL_RETURN_MANUAL_STAGE_MIN_AMOUNT' => '100.00',
];

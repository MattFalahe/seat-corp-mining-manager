<?php

return [
    // Page Headers
    'settings' => 'Settings',
    'settings_menu' => 'Settings Menu',
    'general' => 'General',
    'tax_rates' => 'Tax Rates',
    'pricing' => 'Pricing',
    'features' => 'Features',
    'advanced' => 'Advanced',
    'help' => 'Help',
    
    // Common
    'info' => 'Info',
    'warning' => 'Warning',
    'save_changes' => 'Save Changes',
    'reset_form' => 'Reset Form',
    'days' => 'days',
    'hours' => 'hours',
    'minutes' => 'minutes',
    'never' => 'Never',
    'updating' => 'Updating',
    'update_now' => 'Update Now',
    
    // Validation
    'validation_errors' => 'Please fix the following errors',
    
    // General Settings
    'general_settings' => 'General Settings',
    'general_info' => 'Configure basic corporation settings, time zones, and display preferences.',
    
    // Corporation Settings
    'corporation_settings' => 'Corporation Settings',
    'corporation_name' => 'Corporation Name',
    'corporation_name_placeholder' => 'Enter your corporation name',
    'corporation_name_help' => 'The name of your corporation for display purposes',
    'corporation_ticker' => 'Corporation Ticker',
    'corporation_ticker_placeholder' => 'CORP',
    'corporation_ticker_help' => 'Your corporation\'s ticker symbol (max 5 characters)',
    
    // Time Settings
    'time_settings' => 'Time & Date Settings',
    'timezone' => 'Timezone',
    'timezone_help' => 'Default timezone for all timestamps and schedules',
    'date_format' => 'Date Format',
    'date_format_help' => 'How dates should be displayed throughout the system',
    'time_format' => 'Time Format',
    'time_format_help' => '12-hour or 24-hour time format',
    
    // Display Settings
    'display_settings' => 'Display Settings',
    'items_per_page' => 'Items Per Page',
    'items_per_page_help' => 'Number of items to display per page in tables',
    'currency_decimals' => 'Currency Decimals',
    'currency_decimals_help' => 'Number of decimal places for ISK values',
    'show_character_portraits' => 'Show Character Portraits',
    'show_character_portraits_help' => 'Display character portraits in tables and lists',
    'compact_mode' => 'Compact Mode',
    'compact_mode_help' => 'Use a more compact layout with less spacing',

    // Payment Settings
    'payment_settings' => 'Payment Settings',
    'payment_match_tolerance' => 'Payment Match Tolerance',
    'payment_match_tolerance_help' => 'ISK tolerance when matching wallet payments to tax amounts. Default: 100 ISK',
    'payment_grace_period' => 'ESI Wallet Lag Buffer',
    'payment_grace_period_help' => 'Extra hours to wait before marking a tax as overdue, allowing ESI wallet data to arrive from CCP. EVE wallet journal entries can be delayed — this buffer prevents false "overdue" flags when a player has already paid but ESI hasn\'t delivered the data yet. Default: 24 hours',

    // Notification Settings (moved to dedicated Notifications tab)
    'notification_settings' => 'Notification Settings',
    'notifications' => 'Notifications',
    
    // Tax Rate Settings
    'tax_rate_settings' => 'Tax Rate Settings',
    'tax_rate_info' => 'Configure default tax rates for different types of mining activities.',
    'default_tax_rates' => 'Default Tax Rates',
    'ore_tax_rate' => 'Ore Tax Rate',
    'ore_tax_rate_help' => 'Default tax percentage for standard ore mining',
    'ice_tax_rate' => 'Ice Tax Rate',
    'ice_tax_rate_help' => 'Default tax percentage for ice harvesting',
    'gas_tax_rate' => 'Gas Tax Rate',
    'gas_tax_rate_help' => 'Default tax percentage for gas huffing',
    'moon_tax_rate' => 'Moon Ore Tax Rate',
    'moon_tax_rate_help' => 'Default tax percentage for moon ore mining',
    'mercoxit_tax_rate' => 'Mercoxit Tax Rate',
    'mercoxit_tax_rate_help' => 'Default tax percentage for mercoxit mining',
    
    // Tax Payment Method
    'tax_payment_method' => 'Tax Payment Method',
    'payment_method' => 'Payment Method',
    'wallet_method' => 'Direct ISK Transfer',
    'wallet_method_desc' => 'Members send ISK directly to corporation wallet',
    'tax_wallet_division' => 'Tax Wallet Division',
    'master_wallet' => 'Master Wallet',
    'division_1' => 'Division 1',
    'division_2' => 'Division 2',
    'division_3' => 'Division 3',
    'division_4' => 'Division 4',
    'division_5' => 'Division 5',
    'division_6' => 'Division 6',
    'division_7' => 'Division 7',
    'tax_wallet_division_help' => 'Which wallet division should receive tax payments',
    
    // Tax Code Settings
    'tax_code_settings' => 'Tax Code Settings',
    'tax_code_prefix' => 'Tax Code Prefix',
    'tax_code_prefix_help' => 'Prefix for generated tax codes (e.g., TAX-)',
    'tax_code_length' => 'Tax Code Length',
    'tax_code_length_help' => 'Number of characters in generated tax codes',
    'auto_generate_tax_codes' => 'Auto-Generate Tax Codes',
    'auto_generate_tax_codes_help' => 'Automatically generate unique tax codes for members',
    
    // Tax Period Settings
    'tax_period_settings' => 'Tax Period Settings',
    'tax_calculation_period' => 'Tax Calculation Period',
    'monthly' => 'Monthly',
    'weekly' => 'Weekly',
    'biweekly' => 'Bi-weekly',
    'tax_calculation_period_help' => 'How often taxes are calculated',
    'tax_payment_deadline' => 'Payment Deadline',
    'tax_payment_deadline_help' => 'Number of days members have to pay taxes after calculation',
    'send_tax_reminders' => 'Send Tax Reminders',
    'send_tax_reminders_help' => 'Send reminder notifications before tax deadline',
    'tax_reminder_days' => 'Reminder Days Before Deadline',
    'tax_reminder_days_help' => 'Send reminders this many days before the deadline',
    
    // Pricing Settings
    'pricing_settings' => 'Pricing Settings',
    'pricing_info' => 'Configure price sources, market hubs, and pricing adjustments.',
    
    // Price Source Settings
    'price_source_settings' => 'Price Source Settings',
    'price_provider' => 'Price Provider',
    'price_provider_help' => 'External service to use for market price data',
    'price_type' => 'Price Type',
    'sell_orders' => 'Sell Orders',
    'buy_orders' => 'Buy Orders',
    'average' => 'Average',
    'price_type_help' => 'Which order type to use for pricing',
    'price_percentile' => 'Price Percentile',
    'minimum' => 'Minimum',
    'maximum' => 'Maximum',
    'median' => 'Median',
    'percentile_5' => '5th Percentile',
    'percentile_95' => '95th Percentile',
    'price_percentile_help' => 'Statistical method for price calculation',
    'market_hub' => 'Market Hub',
    'market_hub_help' => 'Primary trade hub for price data',
    
    // Price Cache Settings
    'price_cache_settings' => 'Price Cache Settings',
    'price_cache_duration' => 'Cache Duration',
    'price_cache_duration_help' => 'How long to cache price data before refreshing',
    'auto_update_prices' => 'Auto-Update Prices',
    'auto_update_prices_help' => 'Automatically refresh price data when cache expires',
    'last_price_update' => 'Last Price Update',
    'prices_updated' => 'Prices have been updated successfully',
    'error_updating_prices' => 'Error updating prices',
    
    // Pricing Adjustments
    'pricing_adjustments' => 'Pricing Adjustments',
    'pricing_adjustment_warning' => 'Price adjustments affect all tax calculations. Use carefully.',
    'price_adjustment' => 'Price Adjustment Percentage',
    'price_adjustment_help' => 'Adjust all prices by this percentage (positive or negative)',
    'minimum_ore_value' => 'Minimum Ore Value',
    'minimum_ore_value_help' => 'Ignore ore below this total value (spam filter)',
    'apply_refining_efficiency' => 'Apply Refining Efficiency',
    'apply_refining_efficiency_help' => 'Calculate ore value based on refined minerals',
    'refining_efficiency' => 'Refining Efficiency',
    'refining_efficiency_help' => 'Average refining efficiency percentage',
    
    // Compressed Ore Pricing
    'compressed_ore_pricing' => 'Compressed Ore Pricing',
    'compressed_ore_pricing_method' => 'Pricing Method',
    'refined_value' => 'Refined Value',
    'refined_value_desc' => 'Calculate value based on refined minerals',
    'market_value' => 'Market Value',
    'market_value_desc' => 'Use direct market value of compressed ore',
    'auto_detect_compressed' => 'Auto-Detect Compressed Ore',
    'auto_detect_compressed_help' => 'Automatically identify compressed ore types',
    
    // Feature Settings
    'feature_settings' => 'Feature Settings',
    'features_info' => 'Enable or disable specific features and customize their behavior.',
    
    // Core Features
    'core_features' => 'Core Features',
    'enable_tax_tracking' => 'Enable Tax Tracking',
    'enable_tax_tracking_help' => 'Track and calculate mining taxes',
    'enable_ledger_tracking' => 'Enable Ledger Tracking',
    'enable_ledger_tracking_help' => 'Track individual mining activities',
    'enable_analytics' => 'Enable Analytics',
    'enable_analytics_help' => 'Show analytics and performance metrics',
    'enable_reports' => 'Enable Reports',
    'enable_reports_help' => 'Allow report generation and exports',
    
    // Mining Events
    'mining_events' => 'Mining Events',
    'enable_events' => 'Enable Mining Events',
    'enable_events_help' => 'Allow creation and tracking of mining events',
    'allow_event_creation' => 'Allow Event Creation',
    'allow_event_creation_help' => 'Let directors create new mining events',
    'event_bonus_multiplier' => 'Event Bonus Multiplier',
    'event_bonus_multiplier_help' => 'Bonus multiplier for mining during events',
    
    // Moon Mining
    'moon_mining' => 'Moon Mining',
    'enable_moon_tracking' => 'Enable Moon Tracking',
    'enable_moon_tracking_help' => 'Track moon mining extractions',

    // Permissions & Access
    'permissions_access' => 'Permissions & Access',
    'allow_public_stats' => 'Allow Public Statistics',
    'allow_public_stats_help' => 'Show mining statistics publicly',
    'allow_member_leaderboard' => 'Allow Member Leaderboard',
    'allow_member_leaderboard_help' => 'Display member mining leaderboards',
    'show_character_names' => 'Show Character Names',
    'show_character_names_help' => 'Display full character names in public areas',
    'allow_export_data' => 'Allow Data Export',
    'allow_export_data_help' => 'Exporting is off for everyone when this is off, directors and admins included. Covers mining, tax, analytics, theft and report downloads. Your settings backup is separate and always available.',
    'data_export_disabled' => 'Exporting is switched off. An administrator can turn it back on under Settings, Features, Allow Data Export.',
    
    // Automation & Processing
    'automation_processing' => 'Automation & Processing',
    'auto_process_ledger' => 'Auto-Process Ledger',
    'auto_process_ledger_help' => 'Automatically process mining ledger data',
    'ledger_processing_interval' => 'Processing Interval',
    'ledger_processing_interval_help' => 'How often to process ledger data (in minutes). Note: Changing this value requires manually updating the scheduled task interval in SeAT\'s scheduler.',
    'auto_calculate_taxes' => 'Auto-Calculate Taxes',
    'auto_calculate_taxes_help' => 'Automatically calculate taxes at end of period',
    'auto_generate_invoices' => 'Auto-Generate Invoices',
    'auto_generate_invoices_help' => 'Automatically create tax invoices',
    'verify_wallet_transactions' => 'Verify Wallet Transactions',
    'verify_wallet_transactions_help' => 'Automatically verify tax payments in wallet',
    
    // Data Retention
    'data_retention' => 'Data Retention',
    'data_retention_warning' => 'Changing retention periods will not immediately delete old data. Run cleanup manually.',
    'ledger_retention_days' => 'Ledger Retention Period',
    'ledger_retention_days_help' => 'How long to keep mining ledger records',
    'tax_record_retention_days' => 'Tax Record Retention',
    'tax_record_retention_days_help' => 'How long to keep tax records and invoices',
    'auto_cleanup_old_data' => 'Auto-Cleanup Old Data',
    'auto_cleanup_old_data_help' => 'Automatically delete data older than retention period',
    
    // Advanced Settings
    'advanced_settings' => 'Advanced Settings',
    'advanced_warning' => 'These settings are for advanced users only. Incorrect settings may cause issues.',
    'export_settings' => 'Export Settings',
    'export_description' => 'Export every setting, including the ones each corporation has of its own, to a JSON file for backup or transfer.',
    'export_now' => 'Export Settings',
    'applies_to_all_corporations' => 'All corporations',
    'export_include_webhooks' => 'Include webhooks',
    'export_webhooks_warning' => 'The file will contain your webhook URLs. Anyone who has it can post to those channels.',
    'import_settings' => 'Import Settings',
    'import_description' => 'Import settings from a previously exported JSON file.',
    'choose_file' => 'Choose File',
    'import_now' => 'Import Settings',
    'cache_management' => 'Cache Management',
    'cache_description' => 'Clear all cached data including prices, stats, and calculated values.',
    'clear_now' => 'Clear Cache',
    'confirm_clear_cache' => 'Are you sure you want to clear all cached data?',
    'clearing' => 'Clearing',
    'cache_cleared' => 'Cache has been cleared successfully',
    'error_clearing_cache' => 'Error clearing cache',
    'reset_settings' => 'Reset Settings',
    'reset_description' => 'Reset all settings to their default values. This cannot be undone!',
    'reset_now' => 'Reset to Defaults',
    'confirm_reset' => 'Are you sure you want to reset all settings to defaults?',
    'confirm_reset_final' => 'This action cannot be undone! Continue?',
    'resetting' => 'Resetting',
    'settings_reset' => 'Settings have been reset to defaults',
    'error_resetting' => 'Error resetting settings',
    
    // Help & Documentation
    'help_title' => 'Settings Help',
    'help_intro' => 'Need help configuring the Mining Manager? Here\'s a quick guide to each section.',
    'help_general' => 'General Settings',
    'help_general_desc' => 'Configure basic information and display preferences:',
    'help_general_1' => 'Set your corporation name and ticker for display',
    'help_general_2' => 'Choose timezone and date/time formats',
    'help_general_3' => 'Customize notification preferences',
    'help_tax' => 'Tax Settings',
    'help_tax_desc' => 'Set up tax rates and payment methods:',
    'help_tax_1' => 'Configure default tax rates for different ore types',
    'help_tax_2' => 'Set up wallet transfer payment with tax codes',
    'help_tax_3' => 'Set payment deadlines and reminder schedules',
    'help_pricing' => 'Pricing Settings',
    'help_pricing_desc' => 'Control how ore values are calculated:',
    'help_pricing_1' => 'Select price provider and market hub',
    'help_pricing_2' => 'Choose price type and percentile',
    'help_pricing_3' => 'Apply refining efficiency and adjustments',
    'help_features' => 'Feature Settings',
    'help_features_desc' => 'Enable or disable specific features:',
    'help_features_1' => 'Toggle tax tracking, events, and moon mining',
    'help_features_2' => 'Configure automation and processing',
    'help_features_3' => 'Set data retention periods',
    'need_more_help' => 'Need More Help?',
    'documentation_text' => 'For detailed documentation, examples, and troubleshooting, visit our GitHub repository.',
    'view_documentation' => 'View Documentation',
    
    // Messages
    'choose_payment_method' => 'Choose Payment Method',
    'payment_method_description' => 'Select how members should pay their mining taxes.',
    
    // Additional Settings Terms
    'clear_cache' => 'Clear Cache',
    'help_documentation' => 'Help & Documentation',

    // Webhook Settings
    'webhooks' => 'Webhooks',
    'webhook_notifications' => 'Webhook Notifications',
    'webhooks_info' => 'Configure webhooks to receive real-time theft detection notifications in Discord, Slack, or custom endpoints.',
    'webhook_statistics' => 'Webhook Statistics',
    'webhooks_configured' => 'Configured',
    'webhooks_enabled' => 'Enabled',
    'total_sent' => 'Total Sent',
    'total_failed' => 'Total Failed',
    'add_webhook' => 'Add Webhook',
    'configured_webhooks' => 'Configured Webhooks',
    'no_webhooks_configured' => 'No webhooks configured yet',
    'add_first_webhook' => 'Add Your First Webhook',
    'name' => 'Name',
    'type' => 'Type',
    'events' => 'Events',
    'health' => 'Health',
    'actions' => 'Actions',
    'not_tested' => 'Not Tested',
    'test_webhook' => 'Test Webhook',
    'edit_webhook' => 'Edit Webhook',
    'delete_webhook' => 'Delete Webhook',

    // Webhook Form
    'webhook_name' => 'Webhook Name',
    'webhook_name_help' => 'A friendly name to identify this webhook',
    'webhook_type' => 'Webhook Type',
    'webhook_url' => 'Webhook URL',
    'discord_webhook_help' => 'Go to Discord Server Settings → Integrations → Webhooks to get your webhook URL',
    'webhook_corporation' => 'Assign to Corporation',
    'webhook_corporation_global' => 'Global (admin — sees all corps)',
    'webhook_corporation_help' => 'Which corporation should this webhook receive notifications for? "Global" receives everything. The Tax Program Corp receives everything regardless. A specific corp receives: moon/theft/broadcast tax for its own operations, plus individual tax reminders / invoices / overdue only for miners who belong to that corp. Use this to let each mining-group director see only their own members\' tax activity.',
    'slack_webhook_help' => 'Go to Slack App Settings → Incoming Webhooks to get your webhook URL',
    'custom_webhook_help' => 'Enter your custom webhook endpoint URL',
    'notify_on_events' => 'Notify On Events',
    'theft_detected' => 'Theft Detected',
    'critical_theft' => 'Critical Theft',
    'active_theft' => 'Active Theft in Progress',
    'incident_resolved' => 'Incident Resolved',

    // Discord Settings
    'discord_settings' => 'Discord Settings',
    'discord_role_id' => 'Discord Role ID',
    'discord_role_id_help' => 'Enter a Discord role ID to ping when notifications are sent (enable Developer Mode in Discord, right-click role → Copy ID)',
    'discord_username' => 'Custom Username',
    'discord_username_help' => 'Override the webhook\'s username (optional)',

    // Slack Settings
    'slack_settings' => 'Slack Settings',
    'slack_channel' => 'Slack Channel',
    'slack_channel_help' => 'Override the default channel (e.g., #mining-alerts)',
    'slack_username' => 'Custom Username',
    'slack_username_help' => 'Override the webhook\'s username (optional)',

    // Custom Webhook Settings
    'custom_webhook_settings' => 'Custom Webhook Settings',
    'custom_webhook_info' => 'Custom webhooks receive a JSON payload with theft incident data. You can customize the payload structure below.',
    'custom_payload_template' => 'Custom Payload Template',
    'custom_payload_help' => 'JSON template with variables: {{event_type}}, {{character_id}}, {{character_name}}, {{ore_value}}, {{tax_owed}}, {{severity}}, {{status}}',

    // Report Notifications
    'reports_category' => 'Reports',
    'report_generated' => 'Report Generated',

    // Common
    'optional' => 'Optional',
    'cancel' => 'Cancel',
    'save_webhook' => 'Save Webhook',
];

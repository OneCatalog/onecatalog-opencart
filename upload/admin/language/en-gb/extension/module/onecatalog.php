<?php
// Heading
$_['heading_title']      = 'OneCatalog Import';
$_['heading_import']     = 'OneCatalog — import products';

// Text
$_['text_home']          = 'Home';
$_['text_extension']     = 'Extensions';
$_['text_success']       = 'Success: OneCatalog settings have been saved!';
$_['text_edit']          = 'OneCatalog — settings';
$_['text_enabled']       = 'Enabled';
$_['text_disabled']      = 'Disabled';
$_['text_no_token']      = 'API token is not set. Open Settings and enter the token before importing.';

// Buttons
$_['button_save']        = 'Save';
$_['button_cancel']      = 'Back';
$_['button_settings']    = 'Settings';
$_['button_import']      = 'Import';
$_['button_pick']        = 'Select products (OneCatalog)';
$_['button_cancel_run']  = 'Cancel';

// Import page
$_['entry_or_paste']     = 'or paste a list of identifiers (public_id), separated by comma/space/newline:';

// JS (stepper)
$_['js_empty']           = 'The identifier list is empty';
$_['js_importing']       = 'Importing…';
$_['js_done']            = 'Done:';
$_['js_error']           = 'Error';
$_['js_cancelled']       = 'Cancelled:';
$_['js_created']         = 'Created';
$_['js_updated']         = 'Updated';
$_['js_errors']          = 'Errors';
$_['js_last']            = 'Last result';

// Entry
$_['entry_status']       = 'Status';
$_['entry_api_base']     = 'Wiki API base URL';
$_['entry_api_token']    = 'API token';
$_['entry_lang']         = 'Product language';
$_['entry_step']         = 'Import step (batch size)';
$_['entry_new_status']   = 'New product status';
$_['entry_picker_base']  = 'Picker base URL';

// Help
$_['help_api_token']     = 'X-API-Key for Wiki API requests and the picker widget.';
$_['help_lang']          = 'Language code from the API (e.g. en, ru). Mapped to a store language.';
$_['help_step']          = 'Number of products per import batch (minimum 10).';
$_['help_new_status']    = 'Status set to newly created products only; not overwritten on re-import.';
$_['help_picker_base']   = 'Origin of the product picker widget (default https://tools.onecatalog.net).';

// Reference entities (§3/§7)
$_['help_references']       = 'Reference entities are disabled by default — enable them deliberately. Native targets are preferred over creating own fields.';
$_['entry_import_brand']    = 'Import brand';
$_['entry_import_tags']     = 'Import tags';
$_['entry_import_country']  = 'Import country';
$_['entry_import_collections'] = 'Import collections';
$_['entry_collection_target']  = '↳ Collections target';
$_['help_brand_native']     = '→ native Manufacturer';
$_['help_tags_native']      = '→ native product Tags field';
$_['help_country_attr']     = '→ attribute “Country”';
$_['text_target_attribute'] = 'Attribute';
$_['text_target_category']  = 'Category';

// B2B — prices & stock (§13)
$_['heading_b2b']             = 'OneCatalog — prices & stock (B2B)';
$_['heading_b2b_run']         = 'Run synchronization';
$_['entry_b2b_base']          = 'B2B API base URL';
$_['entry_b2b_url_key']       = 'Retailer key (url_key)';
$_['entry_b2b_private_key']   = 'Private key';
$_['entry_b2b_strategy']      = 'Price strategy';
$_['entry_b2b_region_priority']   = 'Region priority';
$_['entry_b2b_supplier_priority'] = 'Supplier priority';
$_['entry_b2b_supplier_fixed']    = 'Fixed supplier id';
$_['entry_b2b_promo']         = 'Promo as sale';
$_['entry_b2b_manage_stock']  = 'Manage stock';
$_['text_strategy_min']       = 'Minimum price';
$_['text_strategy_priority']  = 'By supplier priority';
$_['text_strategy_supplier']  = 'Fixed supplier';
$_['help_b2b_csv']            = 'Comma-separated ids, highest priority first.';
$_['help_b2b_supplier_fixed'] = 'Used only with the “Fixed supplier” strategy.';
$_['help_b2b_promo']          = 'Write promo_price (0 < promo < base) as a product special.';
$_['help_b2b_manage_stock']   = 'Write the summed warehouse stock to product quantity (subtract on).';
$_['help_b2b_run']            = 'Runs page-by-page in the browser (no cron needed). Only changed products are written (scan-and-diff).';
$_['button_b2b_run']          = 'Synchronize now';
$_['text_b2b_not_configured'] = 'B2B keys are not set — fill url_key and private_key, then save.';
$_['js_b2b_running']          = 'Syncing…';
$_['js_b2b_changed']          = 'Changed';
$_['js_b2b_unchanged']        = 'Unchanged';
$_['js_b2b_missing']          = 'Not in catalog';

// Error
$_['error_permission']   = 'Warning: You do not have permission to modify OneCatalog Import!';
$_['error_no_token']     = 'API token is not set (Settings).';

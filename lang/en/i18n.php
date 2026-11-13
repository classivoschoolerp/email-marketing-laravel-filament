<?php

declare(strict_types=1);

// WP-20 — language packs + internationalisation admin strings (spec §3.22 / §6.5).

return [

    'languages' => [
        'navigation_label' => 'Languages',
        'model_label' => 'Language',
        'plural_label' => 'Languages',
        'code' => 'Code',
        'name' => 'Name',
        'rtl' => 'RTL',
        'enabled' => 'Enabled',
        'default' => 'Default',
        'default_always_enabled' => 'The default language cannot be disabled.',
        'set_default' => 'Set as default',
        'default_set' => 'Default language set to :code.',
        'upload' => 'Upload language pack',
        'pack_file' => 'Pack file (zip of lang files)',
        'uploaded' => 'Language pack installed (:count files).',
        'create' => 'Create language pack',
        'created' => 'Language pack created from the English files — translate it in the editor.',
        'export' => 'Export',
    ],

    'upload' => [
        'invalid_zip' => 'The uploaded file is not a readable zip archive.',
        'no_lang_files' => 'The archive contains no language files (*.php / *.json).',
        'unsafe_entry' => 'The archive entry ":entry" has an unsafe path.',
        'nested_entry' => 'The archive entry ":entry" is nested too deeply — pack files must sit at the root or in one folder.',
        'bad_extension' => 'The archive entry ":entry" is not a language file (*.php / *.json).',
        'bad_php' => 'The archive entry ":entry" is not a valid PHP language file.',
        'bad_json' => 'The archive entry ":entry" is not valid JSON.',
        'bad_code' => 'The language code may only contain a-z, 0-9, "-" and "_" (max 12 characters).',
        'no_reference' => 'The reference language files could not be found.',
    ],

    'editor' => [
        'title' => 'Translation editor',
        'navigation_label' => 'Translations',
        'language' => 'Language',
        'group' => 'Group',
        'strings' => 'Strings',
        'key' => 'Key',
        'value' => 'Translation',
        'save' => 'Save translations',
        'saved' => 'Translations saved — changed strings are stored as overrides.',
        'export' => 'Export language pack',
    ],

    'locale_page' => [
        'title' => 'My language',
        'menu' => 'My language',
        'language' => 'Interface language',
        'save' => 'Save',
        'saved' => 'Your language preference has been saved.',
    ],

];

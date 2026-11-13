<?php

declare(strict_types=1);

/*
 * WP-26 saved builder blocks (§2.3.3 F-2.3.3-026, §2.5.4 F-2.5.4-005) and
 * the §2.3.3 F-2.3.3-025 audio widget. Strings reach the builder through
 * the `blocks` key of the JSON config the builder pages render.
 */
return [
    'fields' => [
        'name' => 'Block name',
        'html' => 'Block content',
    ],

    'panel' => [
        'category' => 'Saved blocks',
        'empty' => 'No saved blocks yet — select something in the canvas and choose “Save as block”.',
    ],

    'actions' => [
        'save_selection' => 'Save as block',
        'delete' => 'Delete block',
    ],

    'prompts' => [
        'name' => 'Name this block',
        'confirm_delete' => 'Delete this saved block?',
    ],

    'notices' => [
        'saved' => 'Block saved',
        'deleted' => 'Block deleted',
        'nothing_selected' => 'Select a block in the canvas first',
        'failed' => 'Could not save the block',
    ],

    // §2.3.3 F-2.3.3-025 audio widget
    'audio' => [
        'label' => 'Audio',
        'listen' => '▶ Listen to the audio',
        'caption' => 'Audio',
    ],
];

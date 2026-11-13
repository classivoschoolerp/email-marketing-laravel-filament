<?php

declare(strict_types=1);

namespace App\Support\Templates;

use Illuminate\Support\Facades\Route;

/**
 * The `blocks` + `i18n.blocks` half of the builder page config (WP-26,
 * F-2.3.3-025 audio widget / F-2.3.3-026 + F-2.5.4-005 saved blocks).
 *
 * One source for both builder pages — the template builder and the campaign
 * content builder mount the SAME resources/js/template-builder.js and share
 * the same saved blocks, so the config they hand it must not drift.
 */
final class BuilderBlocksConfig
{
    /**
     * Saved blocks are an optional module; without its routes the builder
     * gets no endpoints and hides the saved-blocks panel.
     *
     * @return array{listUrl?: string, saveUrl?: string, deleteUrl?: string}
     */
    public static function endpoints(): array
    {
        if (! Route::has('blocks.index')) {
            return [];
        }

        return [
            'listUrl' => route('blocks.index'),
            'saveUrl' => route('blocks.store'),
            // The uid placeholder is substituted client-side.
            'deleteUrl' => route('blocks.destroy', ['uid' => '__UID__']),
        ];
    }

    /** @return array<string, string> */
    public static function strings(): array
    {
        return [
            'category' => __('blocks.panel.category'),
            'empty' => __('blocks.panel.empty'),
            'save_selection' => __('blocks.actions.save_selection'),
            'delete' => __('blocks.actions.delete'),
            'prompt_name' => __('blocks.prompts.name'),
            'confirm_delete' => __('blocks.prompts.confirm_delete'),
            'saved' => __('blocks.notices.saved'),
            'deleted' => __('blocks.notices.deleted'),
            'nothing_selected' => __('blocks.notices.nothing_selected'),
            'failed' => __('blocks.notices.failed'),
            'audio_label' => __('blocks.audio.label'),
            'audio_listen' => __('blocks.audio.listen'),
            'audio_caption' => __('blocks.audio.caption'),
        ];
    }
}

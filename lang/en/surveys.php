<?php

declare(strict_types=1);

// WP-31 — surveys module strings (spec §2.8) and the public pages (§1.9).

return [

    'resource' => [
        'navigation_group' => 'Audience',
        'navigation_label' => 'Surveys',
        'label' => 'Survey',
        'plural_label' => 'Surveys',
    ],

    'tabs' => [
        'general' => 'General',
        'public_page' => 'Public page',
        'responses' => 'Responses',
    ],

    'status' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'paused' => 'Paused',
        'closed' => 'Closed',
    ],

    'responder_status' => [
        'incomplete' => 'Incomplete',
        'complete' => 'Complete',
    ],

    'fields' => [
        'name' => 'Name',
        'display_name' => 'Display name',
        'display_name_help' => 'Shown on the public page. Defaults to the name.',
        'description' => 'Description',
        'list' => 'List',
        'list_none' => 'No list (anonymous only)',
        'list_help' => 'Needed to identify respondents and to build segments from their answers.',
        'status' => 'Status',
        'start_at' => 'Starts at',
        'start_at_help' => 'Before this moment the survey is not open yet.',
        'end_at' => 'Ends at',
        'end_at_help' => 'The survey closes itself at this moment.',
        'max_responders' => 'Maximum responses',
        'max_responders_help' => '0 or empty = unlimited. Counted on completed responses only.',
        'start_page_content' => 'Start page content',
        'thank_you_content' => 'Thank-you page content',
        'closed_message' => 'Message when closed',
        'redirect_url' => 'Redirect after completion',
        'redirect_url_help' => 'When set, respondents go here instead of the thank-you page.',
        'meta_title' => 'Meta title',
        'meta_description' => 'Meta description',
        'show_progress_bar' => 'Show a progress bar',
        'fields_per_step' => 'Questions per step',
        'fields_per_step_help' => '0 = all questions on one page.',
        'html_allowed' => 'HTML is allowed.',
        'allow_anonymous' => 'Allow anonymous responses',
        'allow_anonymous_help' => 'When off, only links carrying a subscriber can answer.',
        'one_response_per_subscriber' => 'One response per subscriber',
        'one_response_help' => 'Anonymous respondents are never deduplicated — there is nothing to deduplicate on.',
        'completed_responders' => 'Completed',
        'all_responders' => 'Started',
        'unlimited' => 'Unlimited',
        'merge_tag' => 'Campaign merge tag',
    ],

    'actions' => [
        'create' => 'New survey',
        'questions' => 'Questions',
        'responders' => 'Responders',
        'stats' => 'Stats',
        'open_public' => 'Open public page',
    ],

    'questions' => [
        'title' => 'Questions',
        'page_title' => 'Questions — :name',
        'add' => 'Add question',
        'type' => 'Type',
        'label' => 'Question',
        'tag' => 'Tag',
        'tag_help' => 'Uppercase identifier used as the input name and the export column key.',
        'list_field' => 'Save into list field',
        'list_field_none' => 'Answer only',
        'list_field_help' => 'Mapped answers are copied onto the subscriber, which is what makes them segmentable.',
        'help_text' => 'Help text',
        'required' => 'Required',
        'options' => 'Options',
        'option_label' => 'Option label',
        'option_value' => 'Option value',
        'validation' => 'Validation',
        'answers' => 'Answers',
        'delete_warning' => 'Deleting ":label" also deletes :count stored answer(s). This cannot be undone.',
    ],

    'responders' => [
        'title' => 'Responders',
        'page_title' => 'Responders — :name',
        'email' => 'Email',
        'identity' => 'Identity',
        'anonymous' => 'Anonymous',
        'identified' => 'Identified',
        'all' => 'All',
        'status' => 'Status',
        'completed_at' => 'Completed',
        'export' => 'Export',
    ],

    'export' => [
        'sheet_name' => 'Responders',
        'responder_uid' => 'Response ID',
        'status' => 'Status',
        'identity' => 'Identity',
        'email' => 'Email',
        'started_at' => 'Started at',
        'completed_at' => 'Completed at',
        'ip' => 'IP',
        'format' => 'Format',
        'queued' => 'Export queued',
        'queued_body' => 'You will receive an email with the download link when it is ready.',
        'failed' => 'The survey responder export failed.',
        'mail_name' => 'Survey responders',
    ],

    'stats' => [
        'title' => 'Stats',
        'responders' => 'Responses started',
        'completed' => 'Responses completed',
        'questions' => 'Questions',
        'answered' => ':count answer(s)',
        'average' => 'average :value',
        'no_answers' => 'No answers yet.',
        'no_questions' => 'This survey has no questions yet.',
        'samples' => 'Sample answers',
        'yes' => 'Yes',
        'no' => 'No',
    ],

    'segment' => [
        'action' => 'Create segment from an answer',
        'question' => 'Question',
        'operator' => 'Operator',
        'value' => 'Value',
        'name' => 'Segment name',
        'default_name' => ':survey — :question',
        'created' => 'Segment ":name" created',
        'created_body' => 'Past identified answers are being copied onto their subscribers in the background.',
        'failed' => 'The segment could not be created. The survey needs a list.',
    ],

    'automations' => [
        'any_survey' => 'Any survey',
    ],

    'public' => [
        'identified_as' => 'You are answering as :email.',
        'anonymous_notice' => 'Your answers are recorded anonymously.',
        'start_button' => 'Start',
        'step_of' => 'Step :step of :total',
        'next' => 'Next',
        'finish' => 'Finish',
        'thanks_default' => 'Thank you for your answers.',
        'closed' => [
            'not_started' => 'This survey has not opened yet.',
            'ended' => 'This survey has closed.',
            'full' => 'This survey has reached its maximum number of responses.',
            'closed' => 'This survey is not accepting responses.',
            'identified_only' => 'This survey can only be answered through a personal link.',
            'already_responded' => 'You have already answered this survey. Thank you!',
            'no_questions' => 'This survey has no questions yet.',
        ],
    ],
];

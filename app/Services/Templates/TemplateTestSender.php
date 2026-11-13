<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Mail\TemplateTestMail;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Mail;

/**
 * Queues a send-test for a template (spec §2.5.5 F-2.5.5-004) with merge tags
 * replaced by sample subscriber data (F-2.5.5-005).
 */
class TemplateTestSender
{
    public function __construct(private readonly MergeTags $mergeTags) {}

    /** @param list<string> $addresses */
    public function send(EmailTemplate $template, array $addresses): void
    {
        $html = $this->mergeTags->renderSample($template->content_html);
        $subject = __('templates.test_email.subject', ['name' => $template->name]);

        foreach ($addresses as $address) {
            Mail::to($address)->queue(new TemplateTestMail($subject, $html));
        }
    }
}

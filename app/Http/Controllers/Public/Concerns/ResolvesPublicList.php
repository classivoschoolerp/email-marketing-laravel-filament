<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Concerns;

use App\Enums\ListPageType;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Lists\ListPageResolver;
use App\Services\Lists\PublicPageRenderer;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared plumbing for the public list endpoints (spec §1.5): resolve the
 * list/subscriber from URL uids — the uid IS the access secret (MailWizz
 * pattern: unguessable UUIDs, no session required) — bind the tenant
 * explicitly (public routes have no authenticated customer), and render the
 * branded "list not found / disabled" page (F-1.5-011) instead of a bare 404.
 */
trait ResolvesPublicList
{
    /** Resolve a visible, active list by uid or show the branded 404. */
    protected function resolvePublicList(string $uid): EmailList
    {
        /** @var EmailList|null $list */
        $list = EmailList::withoutTenancy()
            ->where('uid', $uid)
            ->where('visible', true)
            ->where('status', EmailList::STATUS_ACTIVE)
            ->first();

        if ($list === null) {
            // Hidden or disabled lists keep their own page customizations.
            $this->abortListNotFound(
                EmailList::withoutTenancy()->where('uid', $uid)->first(),
            );
        }

        // Public routes carry no session — the tenant is derived from the
        // list itself (docs/ARCHITECTURE.md rule 3; pattern of SetCurrentCustomer).
        app(CurrentCustomer::class)->set($list->customer()->withoutGlobalScopes()->first());

        return $list;
    }

    /** Resolve a subscriber of the list by uid or show the branded 404. */
    protected function resolvePublicSubscriber(EmailList $list, string $subscriberUid): Subscriber
    {
        /** @var Subscriber|null $subscriber */
        $subscriber = Subscriber::withoutTenancy()
            ->where('list_id', $list->id)
            ->where('uid', $subscriberUid)
            ->first();

        if ($subscriber === null) {
            $this->abortListNotFound($list);
        }

        return $subscriber;
    }

    /** Render the branded list-not-found page type with a 404 status. */
    protected function abortListNotFound(?EmailList $list): never
    {
        $renderer = app(PublicPageRenderer::class);
        $resolver = app(ListPageResolver::class);

        $content = $list !== null
            ? $renderer->html($list, ListPageType::ListNotFound)
            : $renderer->substitute($resolver->defaultContent(ListPageType::ListNotFound), null);

        throw new HttpResponseException(response()->view('public.lists.page', [
            'title' => __('public.lists.not_found_title'),
            'content' => $content,
            'footerHtml' => $list !== null ? $renderer->footer($list) : '',
            'customCss' => $list !== null
                ? $renderer->safeCss($resolver->customCss($list, ListPageType::ListNotFound))
                : null,
            'customJs' => $list !== null
                ? $renderer->safeJs($resolver->customJs($list, ListPageType::ListNotFound))
                : null,
        ], 404));
    }
}

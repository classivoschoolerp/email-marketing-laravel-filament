<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\EmailListResource;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\Subscriber;
use Filament\Facades\Filament;
use Filament\GlobalSearch\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Database\Eloquent\Builder;

/**
 * §4.4 F-4.4-001 — global search across lists, campaigns, subscribers and
 * templates (WP-40).
 *
 * Implemented as a panel-level {@see GlobalSearchProvider} rather than as
 * `getGloballySearchableAttributes()` on each resource, for two reasons:
 *
 *  1. TENANCY. A search box is the one place where a missing fence leaks the
 *     whole install. Every query here is fenced TWICE — the CustomerScope
 *     global scope plus an explicit `customer_id` predicate — and that is
 *     visible in one file instead of four.
 *
 *  2. INDEX DISCIPLINE (F-4.4-002). Each query is written to hit a real
 *     index: subscribers use the WP-26 `subscribers_customer_email_idx`
 *     prefix scan, campaigns/lists/templates use their tenant indexes. The
 *     default provider would OR every searchable attribute together, which
 *     defeats the index on the biggest table in the system.
 *
 * Results are only ever produced for a signed-in customer; with no tenant
 * bound the provider returns an empty set rather than an unfenced one.
 */
class PanelGlobalSearchProvider implements GlobalSearchProvider
{
    /** Shorter terms match nearly everything and are not worth a query. */
    public const MIN_LENGTH = 2;

    public const LIMIT = 10;

    public function getResults(string $query): ?GlobalSearchResults
    {
        $results = GlobalSearchResults::make();
        $term = trim($query);

        $customer = Filament::auth()->user();

        if (! $customer instanceof Customer || mb_strlen($term) < self::MIN_LENGTH) {
            return $results;
        }

        $customerId = (int) $customer->id;

        $this->push($results, __('analytics.search.lists'), $this->lists($term, $customerId));
        $this->push($results, __('analytics.search.campaigns'), $this->campaigns($term, $customerId));
        $this->push($results, __('analytics.search.subscribers'), $this->subscribers($term, $customerId));
        $this->push($results, __('analytics.search.templates'), $this->templates($term, $customerId));

        return $results;
    }

    /** @param list<GlobalSearchResult> $rows */
    private function push(GlobalSearchResults $results, string $category, array $rows): void
    {
        if ($rows !== []) {
            $results->category($category, $rows);
        }
    }

    /** @return list<GlobalSearchResult> */
    private function lists(string $term, int $customerId): array
    {
        return EmailList::query()
            ->where('customer_id', $customerId)
            ->where(fn (Builder $q) => $q->where('name', 'like', $term.'%')->orWhere('name', 'like', '%'.$term.'%'))
            ->withCount('subscribers')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (EmailList $list): GlobalSearchResult => new GlobalSearchResult(
                title: (string) $list->name,
                url: EmailListResource::getUrl('overview', ['record' => $list], panel: 'customer'),
                details: [
                    __('analytics.search.details.subscribers') => (string) $list->subscribers_count,
                ],
            ))
            ->all();
    }

    /** @return list<GlobalSearchResult> */
    private function campaigns(string $term, int $customerId): array
    {
        return Campaign::query()
            ->where('customer_id', $customerId)
            ->where(fn (Builder $q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('subject', 'like', '%'.$term.'%'))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Campaign $campaign): GlobalSearchResult => new GlobalSearchResult(
                title: (string) $campaign->name,
                url: CampaignResource::getUrl('edit', ['record' => $campaign], panel: 'customer'),
                details: array_filter([
                    __('analytics.search.details.subject') => (string) $campaign->subject,
                    __('analytics.search.details.status') => $campaign->status?->label() ?? '',
                ]),
            ))
            ->all();
    }

    /**
     * Subscribers are the only table here that can hold millions of rows, so
     * the search is a PREFIX match (`term%`) on the indexed `email` column —
     * that is what `subscribers_customer_email_idx` can actually serve. A
     * term containing `@` additionally matches the domain part, because
     * "find everyone at acme.test" is the query operators actually run.
     *
     * @return list<GlobalSearchResult>
     */
    private function subscribers(string $term, int $customerId): array
    {
        $term = mb_strtolower($term);

        return Subscriber::query()
            ->where('customer_id', $customerId)
            ->where(function (Builder $q) use ($term): void {
                $q->where('email', 'like', $term.'%');

                if (str_contains($term, '@') || str_contains($term, '.')) {
                    $q->orWhere('email', 'like', '%'.$term);
                }
            })
            ->with('list')
            ->orderBy('email')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Subscriber $subscriber): GlobalSearchResult => new GlobalSearchResult(
                title: (string) $subscriber->email,
                url: SubscriberResource::getUrl('view', ['record' => $subscriber], panel: 'customer'),
                details: array_filter([
                    __('analytics.search.details.list') => (string) ($subscriber->list?->name ?? ''),
                    __('analytics.search.details.status') => $subscriber->status?->label() ?? '',
                ]),
            ))
            ->all();
    }

    /** @return list<GlobalSearchResult> */
    private function templates(string $term, int $customerId): array
    {
        return EmailTemplate::query()
            ->where('customer_id', $customerId)
            ->where('name', 'like', '%'.$term.'%')
            ->with('category')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (EmailTemplate $template): GlobalSearchResult => new GlobalSearchResult(
                title: (string) $template->name,
                url: EmailTemplateResource::getUrl('edit', ['record' => $template], panel: 'customer'),
                details: array_filter([
                    __('analytics.search.details.category') => (string) ($template->category?->name ?? ''),
                ]),
            ))
            ->all();
    }
}

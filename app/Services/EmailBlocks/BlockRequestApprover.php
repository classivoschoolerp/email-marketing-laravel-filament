<?php

declare(strict_types=1);

namespace App\Services\EmailBlocks;

use App\Enums\BlockRequestStatus;
use App\Models\EmailBlockRequest;
use App\Models\GlobalBlacklistEmail;

/**
 * Approves a block request (spec §3.19 F-3.19-002/003): inserts the address
 * into the global blacklist forward-base (§3.17, WP-16 extends) with
 * source='block-request' and marks the request approved. Shared by the
 * admin resource actions and the auto-approve path.
 */
class BlockRequestApprover
{
    public function approve(EmailBlockRequest $request): void
    {
        if (! $request->status->isApprovable()) {
            return;
        }

        GlobalBlacklistEmail::query()->firstOrCreate(
            ['email' => $request->email],
            [
                'reason' => __('public.block_requests.blacklist_reason'),
                'source' => GlobalBlacklistEmail::SOURCE_BLOCK_REQUEST,
                'customer_id' => null,
            ],
        );

        $request->status = BlockRequestStatus::Approved;
        $request->save();
    }

    public function reject(EmailBlockRequest $request): void
    {
        if ($request->status === BlockRequestStatus::Approved) {
            return;
        }

        $request->status = BlockRequestStatus::Rejected;
        $request->save();
    }
}

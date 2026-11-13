<?php

declare(strict_types=1);

// WP-20 — 日本語言語パック (spec §6.5 F-6.5-002)。

return [

    'lists' => [
        'not_found_title' => 'リストは利用できません',
        'subscribe_success' => 'ご登録ありがとうございます！',
        'profile_updated' => 'プロフィールを更新しました。',
        'save_profile' => 'プロフィールを更新',
        'unsubscribing_as' => ':email の配信登録を解除しようとしています。',
        'reason_label' => '配信解除の理由を教えてください（任意）',
        'reason_text_label' => '補足があればご記入ください（任意）',
        'unsubscribe_button' => '配信を解除',
        'unsubscribe_confirm_button' => 'はい、配信を解除します',
        'one_click_done' => '配信登録を解除しました。',

        'reasons' => [
            'no_longer_interested' => 'もう興味がない',
            'too_many_emails' => 'メールが多すぎる',
            'never_subscribed' => 'このリストに登録した覚えがない',
            'content_not_relevant' => '内容が自分に合っていない',
            'other' => 'その他',
        ],

        // WP-42 §1.5 F-1.5-012 — 配信設定センター。
        'preferences_link' => '解除せずに配信設定を変更する',
        'preferences_as' => 'この設定は :email に適用されます。',
        'preferences_saved' => '配信設定を保存しました。',
        'save_preferences' => '設定を保存',
        'topics_label' => 'トピック',
        'topics_help' => '受け取りたいトピックだけを選んでください。チェックを外すと、すべてを解除しなくてもそのメールだけ止まります。',
        'frequency_label' => 'どのくらいの頻度でメールを受け取りますか？',
        'pause_days_label' => '何日間停止しますか？',
        'pause_days_required' => 'メールを何日間停止するか入力してください。',
        'unsubscribe_all_link' => 'すべての配信を解除する',

        'frequencies' => [
            'every_send' => 'すべてのメール',
            'weekly' => '週に最大 1 通',
            'monthly' => '月に最大 1 通',
            'paused' => 'しばらくメールを停止する',
        ],

        // WP-42 §1.5 F-1.5-013 — 再登録ページ。
        'resubscribing_as' => ':email を再登録しようとしています。',
        'resubscribe_button' => '再登録する',
        'resubscribe_blocked' => 'このアドレスは再登録できません。お心当たりがない場合はお問い合わせください。',
    ],

    'campaigns' => [
        'open_web_view' => 'メール全文を読む',
    ],

    'block_requests' => [
        'title' => '今後一切メールを受け取らない',
        'intro' => 'メールアドレスを入力すると、送信者を問わず、このプラットフォームからそのアドレスへのすべてのメールを停止します。まず確認メールを1通お送りします。',
        'submit' => 'メールブロックを申請',
        'sent' => '受信トレイをご確認ください — この申請を確認するためのリンクをお送りしました。',
        'confirmed_title' => '申請を確認しました',
        'confirmed_body' => ':email へのすべてのメールをブロックする申請が確認され、処理されます。',
        'blacklist_reason' => '受信者によるブロック申請',

        'status' => [
            'pending' => '確認待ち',
            'confirmed' => '確認済み',
            'approved' => '承認済み',
            'rejected' => '却下',
        ],

        'mail' => [
            'subject' => 'メールブロック申請を確認してください',
            'intro' => 'どなたか（おそらくあなた）が、このアドレスに今後一切メールを送らないよう申請しました。下のボタンをクリックして申請を確認してください。',
            'button' => 'ブロック申請を確認',
            'ignore' => 'この申請に心当たりがない場合は、このメールを無視してください — 何も変わりません。',
        ],

        'admin' => [
            'navigation_label' => 'メールブロック申請',
            'model_label' => 'メールブロック申請',
            'plural_label' => 'メールブロック申請',
            'email' => 'メールアドレス',
            'status' => 'ステータス',
            'ip' => 'IPアドレス',
            'confirmed_at' => '確認日時',
            'requested_at' => '申請日時',
            'approve' => '承認',
            'approved' => '申請を承認しました — アドレスをグローバルブラックリストに追加しました。',
            'reject' => '却下',
            'rejected' => '申請を却下しました。',
            'bulk_approve' => '選択した申請を承認',
            'auto_approve_on' => '自動承認は有効です — 確認済みの申請は自動的にブラックリストに登録されます (EMP_BLOCK_REQUESTS_AUTO_APPROVE)。',
            'auto_approve_off' => '自動承認は無効です — 確認済みの申請はここで審査を待ちます (EMP_BLOCK_REQUESTS_AUTO_APPROVE)。',
        ],
    ],

    'page_defaults' => [
        'title' => 'リストページの既定値',
        'navigation_label' => 'リストページ既定値',
        'state_customized' => 'このインストール用にカスタマイズ済み',
        'state_translatable' => '組み込みテンプレートを使用中',
        'saved' => '既定値を保存しました — 独自の内容を持たないリストは直ちにこれを継承します。',
        'reset' => '組み込みテンプレートに戻す',
        'reset_confirm' => 'インストールの既定値を破棄して組み込みテンプレートに戻しますか？',
        'reset_done' => '既定値を組み込みテンプレートに戻しました。',
    ],

];

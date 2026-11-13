<?php

declare(strict_types=1);

// WP-20 — pacchetto lingua italiano (spec §6.5 F-6.5-002).

return [

    'lists' => [
        'not_found_title' => 'Lista non disponibile',
        'subscribe_success' => 'Grazie per esserti iscritto!',
        'profile_updated' => 'Il tuo profilo è stato aggiornato.',
        'save_profile' => 'Aggiorna profilo',
        'unsubscribing_as' => 'Stai annullando l\'iscrizione di :email.',
        'reason_label' => 'Perché annulli l\'iscrizione? (facoltativo)',
        'reason_text_label' => 'Vuoi aggiungere qualcosa? (facoltativo)',
        'unsubscribe_button' => 'Annulla iscrizione',
        'unsubscribe_confirm_button' => 'Sì, annulla la mia iscrizione',
        'one_click_done' => 'La tua iscrizione è stata annullata.',

        'reasons' => [
            'no_longer_interested' => 'Non sono più interessato',
            'too_many_emails' => 'Ricevo troppe email',
            'never_subscribed' => 'Non mi sono mai iscritto a questa lista',
            'content_not_relevant' => 'I contenuti non sono rilevanti per me',
            'other' => 'Altro',
        ],

        // WP-42 §1.5 F-1.5-012 — centro preferenze.
        'preferences_link' => 'Aggiorna le tue preferenze invece di andartene',
        'preferences_as' => 'Queste preferenze valgono per :email.',
        'preferences_saved' => 'Le tue preferenze sono state salvate.',
        'save_preferences' => 'Salva preferenze',
        'topics_label' => 'Argomenti',
        'topics_help' => 'Seleziona solo gli argomenti che ti interessano. Deselezionarne uno ferma quelle email senza cancellarti da tutto.',
        'frequency_label' => 'Con quale frequenza vuoi ricevere le nostre email?',
        'pause_days_label' => 'Per quanti giorni vuoi sospendere?',
        'pause_days_required' => 'Indica per quanti giorni sospendere le tue email.',
        'unsubscribe_all_link' => 'Preferisco cancellarmi da tutto',

        'frequencies' => [
            'every_send' => 'Tutte le email',
            'weekly' => 'Al massimo una email a settimana',
            'monthly' => 'Al massimo una email al mese',
            'paused' => 'Sospendi le mie email per un po\'',
        ],

        // WP-42 §1.5 F-1.5-013 — pagina di re-iscrizione.
        'resubscribing_as' => 'Stai iscrivendo di nuovo :email.',
        'resubscribe_button' => 'Iscriviti di nuovo',
        'resubscribe_blocked' => 'Questo indirizzo non può essere iscritto di nuovo. Contattaci se pensi che si tratti di un errore.',
    ],

    'campaigns' => [
        'open_web_view' => 'Leggi l\'email completa',
    ],

    'block_requests' => [
        'title' => 'Non inviatemi più email',
        'intro' => 'Inserisci il tuo indirizzo email e bloccheremo tutte le email di questa piattaforma verso di esso, da qualsiasi mittente. Prima ti invieremo una email di conferma.',
        'submit' => 'Richiedi blocco email',
        'sent' => 'Controlla la tua casella — ti abbiamo inviato un link per confermare questa richiesta.',
        'confirmed_title' => 'Richiesta confermata',
        'confirmed_body' => 'La tua richiesta di bloccare tutte le email verso :email è stata confermata e sarà elaborata.',
        'blacklist_reason' => 'Blocco richiesto dal destinatario',

        'status' => [
            'pending' => 'In attesa di conferma',
            'confirmed' => 'Confermata',
            'approved' => 'Approvata',
            'rejected' => 'Rifiutata',
        ],

        'mail' => [
            'subject' => 'Conferma la tua richiesta di blocco email',
            'intro' => 'Qualcuno (speriamo tu) ci ha chiesto di non inviare mai più email a questo indirizzo. Clicca il pulsante qui sotto per confermare la richiesta.',
            'button' => 'Conferma richiesta di blocco',
            'ignore' => 'Se non hai fatto tu questa richiesta, ignora questa email — non cambierà nulla.',
        ],

        'admin' => [
            'navigation_label' => 'Richieste di blocco email',
            'model_label' => 'Richiesta di blocco email',
            'plural_label' => 'Richieste di blocco email',
            'email' => 'Email',
            'status' => 'Stato',
            'ip' => 'Indirizzo IP',
            'confirmed_at' => 'Confermata il',
            'requested_at' => 'Richiesta il',
            'approve' => 'Approva',
            'approved' => 'Richiesta approvata — indirizzo aggiunto alla blacklist globale.',
            'reject' => 'Rifiuta',
            'rejected' => 'Richiesta rifiutata.',
            'bulk_approve' => 'Approva selezionate',
            'auto_approve_on' => 'L\'approvazione automatica è ATTIVA — le richieste confermate vengono inserite in blacklist automaticamente (EMP_BLOCK_REQUESTS_AUTO_APPROVE).',
            'auto_approve_off' => 'L\'approvazione automatica è DISATTIVATA — le richieste confermate attendono qui la revisione (EMP_BLOCK_REQUESTS_AUTO_APPROVE).',
        ],
    ],

    'page_defaults' => [
        'title' => 'Contenuti predefiniti delle pagine lista',
        'navigation_label' => 'Pagine lista predefinite',
        'state_customized' => 'Personalizzato per questa installazione',
        'state_translatable' => 'Utilizza il modello integrato',
        'saved' => 'Predefinito salvato — le liste senza un proprio contenuto lo ereditano.',
        'reset' => 'Ripristina il modello integrato',
        'reset_confirm' => 'Scartare il predefinito dell\'installazione e tornare al modello integrato?',
        'reset_done' => 'Predefinito ripristinato al modello integrato.',
    ],

];

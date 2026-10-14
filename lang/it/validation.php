<?php

return [
    'required' => 'Il campo :attribute è obbligatorio.',
    'string' => 'Il campo :attribute deve essere un testo.',
    'email' => 'Inserisci un indirizzo email valido.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'boolean' => 'Il valore di :attribute non è valido.',
    'array' => 'La selezione :attribute non è valida.',
    'distinct' => 'La selezione :attribute contiene duplicati.',
    'in' => 'La selezione :attribute non è consentita.',
    'exists' => 'La selezione :attribute non è disponibile.',
    'unique' => 'Il valore di :attribute non è disponibile.',
    'regex' => 'Il formato di :attribute non è valido.',
    'confirmed' => 'La conferma di :attribute non coincide.',
    'min' => ['string' => ':attribute deve contenere almeno :min caratteri.', 'numeric' => ':attribute deve essere almeno :min.', 'array' => 'Seleziona almeno :min elemento per :attribute.'],
    'max' => ['string' => ':attribute può contenere al massimo :max caratteri.', 'numeric' => ':attribute non può superare :max.', 'array' => 'Seleziona al massimo :max elementi per :attribute.'],
    'password' => ['mixed' => 'La password deve includere maiuscole e minuscole.', 'letters' => 'La password deve includere lettere.', 'numbers' => 'La password deve includere numeri.', 'symbols' => 'La password deve includere simboli.'],
    'attributes' => ['name' => 'nome', 'email' => 'email', 'password' => 'password', 'target' => 'recapito', 'channel' => 'canale', 'roles' => 'ruoli', 'permissions' => 'ambiti', 'reason' => 'motivazione', 'minutes' => 'durata', 'code' => 'codice di verifica', 'recovery_code' => 'codice di recupero'],
];

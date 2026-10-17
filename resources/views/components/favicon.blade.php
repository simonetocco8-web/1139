<link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v={{ is_file(public_path('favicon.ico')) ? substr(hash_file('sha256', public_path('favicon.ico')), 0, 12) : '1' }}">

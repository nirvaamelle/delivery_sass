{{--
    The per-login challenge. Exit gate clause 4, second half.

    Asked once per session: what is being authenticated is the session, not the
    click, and a challenge on every page load is one people route around.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor code</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 30rem; margin: 5rem auto; padding: 0 1rem; color: #111; }
        .error { color: #b91c1c; }
        input { font-size: 1.5rem; padding: .5rem; letter-spacing: .3em; width: 7em; }
        button { font-size: 1rem; padding: .6rem 1.1rem; }
    </style>
</head>
<body>
    <h1>Enter your code</h1>

    <p>Open your authenticator app and enter the six-digit code it is showing.</p>

    <form method="POST" action="{{ route('two-factor.challenge.verify') }}">
        @csrf
        <label for="code" class="sr-only">Six-digit code</label><br>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required>
        <button type="submit">Continue</button>
    </form>

    @error('code')
        <p class="error">{{ $message }}</p>
    @enderror
</body>
</html>

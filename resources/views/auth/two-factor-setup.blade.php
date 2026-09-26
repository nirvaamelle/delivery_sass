{{--
    Enrolment. Exit gate clause 4.

    The secret is shown as a QR code AND as text: a phone that cannot scan is
    common, and a page offering only a picture strands whoever has one.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor authentication</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 34rem; margin: 4rem auto; padding: 0 1rem; color: #111; }
        code { background: #f3f4f6; padding: .2rem .4rem; border-radius: .25rem; word-break: break-all; }
        .error { color: #b91c1c; }
        input { font-size: 1.25rem; padding: .5rem; letter-spacing: .3em; width: 8em; }
        button { font-size: 1rem; padding: .55rem 1rem; }
    </style>
</head>
<body>
    <h1>Two-factor authentication</h1>

    @if ($confirmed)
        <p>This account is enrolled. <a href="/admin">Continue</a>.</p>
    @else
        <p>
            Your account can move money, read salaries or grant access, so a second
            factor is required before you can use the system.
        </p>

        @if ($qr)
            <div>{!! $qr !!}</div>
        @endif

        <p>If you cannot scan, enter this key by hand: <code>{{ $secret }}</code></p>

        <form method="POST" action="{{ route('two-factor.confirm') }}">
            @csrf
            <label for="code">Enter the six-digit code from your authenticator</label><br>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            <button type="submit">Confirm</button>
        </form>

        @error('code')
            <p class="error">{{ $message }}</p>
        @enderror
    @endif
</body>
</html>

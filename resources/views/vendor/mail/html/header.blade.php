@props(['url'])

@php
    $mailLogoPath = public_path('images/mail-logo.png');
    $mailLogoCid = file_exists($mailLogoPath) && isset($message) ? $message->embed($mailLogoPath) : null;
@endphp

<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($mailLogoCid)
<img src="{{ $mailLogoCid }}" class="logo" alt="{{ config('app.name') }} Logo">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>

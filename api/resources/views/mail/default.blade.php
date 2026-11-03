{{--
    Mail theme (white-label, docs/white-label.md). Markdown::render() uses the view mail.<theme>
    (here: the default theme) before the framework's mail::themes.<theme>; this one is Laravel's
    default theme with neutral text and border tones (MailTheme: zinc → neutral, R = G = B, like
    the console) and the brand's colours (BrandingService::forMail(), the tenant's when a mail
    is sent for one): links in the primary scale's shade 700, readable on the white body, and the
    primary button in shade 600. The header and footer keep their own link colours.
--}}
@php($brand = app(\App\Services\WhiteLabel\BrandingService::class)->forMail())
{!! \App\Services\WhiteLabel\MailTheme::neutral(view('mail::themes.default')->render()) !!}

/* Brand colours */

a {
    color: {{ $brand['mail_link_color'] }};
}

.button-blue,
.button-primary {
    background-color: {{ $brand['mail_button_color'] }};
    border-bottom: 8px solid {{ $brand['mail_button_color'] }};
    border-left: 18px solid {{ $brand['mail_button_color'] }};
    border-right: 18px solid {{ $brand['mail_button_color'] }};
    border-top: 8px solid {{ $brand['mail_button_color'] }};
}

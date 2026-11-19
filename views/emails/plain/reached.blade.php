<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-trust-levels.email.reached.plain.body', [
'{level}' => $blueprint->levelName,
'{url}' => $url->to('forum')->route('user', ['username' => $blueprint->slug, 'filter' => 'trust']),
]) !!}
</x-slot:body>
</x-mail::plain.notification>

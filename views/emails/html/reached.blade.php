<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-trust-levels.email.reached.html.body', [
            '{level}' => e($blueprint->levelName),
            '{url}' => $url->to('forum')->route('user', ['username' => $blueprint->slug, 'filter' => 'trust']),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>

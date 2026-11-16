<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-trust-levels.email.reached.html.body', [
            '{level}' => e(resolve(\ErnestDefoe\TrustLevels\Names::class)->name($blueprint->level)),
            '{url}' => $url->to('forum')->route('user', ['username' => resolve(\Flarum\Http\SlugManager::class)->forResource(\Flarum\User\User::class)->toSlug($blueprint->user), 'filter' => 'trust']),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>

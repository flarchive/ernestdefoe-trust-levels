<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-trust-levels.email.reached.plain.body', [
'{level}' => resolve(\ErnestDefoe\TrustLevels\Names::class)->name($blueprint->level),
'{url}' => $url->to('forum')->route('user', ['username' => resolve(\Flarum\Http\SlugManager::class)->forResource(\Flarum\User\User::class)->toSlug($blueprint->user), 'filter' => 'trust']),
]) !!}
</x-slot:body>
</x-mail::plain.notification>

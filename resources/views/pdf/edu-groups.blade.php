{{--
    Обуки: all groups of a program in one document, one group per page.

    Pdf::loadView('pdf.edu-groups', ['groups' => [$data, ...], 'title' => ..., 'printed_at' => ...])->setPaper('a4')

    groups      list<array>  each one the $group array documented in pdf/edu-group.blade.php
    title       ?string      document title; defaults to the first group's program_title
    printed_at  ?string      date in the footer; defaults to today (d.m.Y)

    Each group carries its own labels; the title and footer are bilingual when any group is.
--}}
@php
    $groups = array_values($groups ?? []);
    $labels = \App\Services\Edu\GroupDocumentBuilder::documentLabels($groups);
    $title = $title ?? ($groups[0]['program_title'] ?? 'Обука');
@endphp
<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} — {{ $labels['groups'] }}</title>
    @include('pdf.partials.edu-group-styles')
</head>
<body>
    @include('pdf.partials.edu-group-footer', ['labels' => $labels])
    @forelse($groups as $group)
        <div class="group{{ $loop->last ? '' : ' group--break' }}">
            @include('pdf.partials.edu-group', ['group' => $group])
        </div>
    @empty
        <div class="empty">{{ $labels['no_groups'] }}</div>
    @endforelse
</body>
</html>
